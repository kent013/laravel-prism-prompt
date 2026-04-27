<?php

declare(strict_types=1);

/**
 * Example 07: PromptOperation (durable LLM operation coordinator)
 *
 * `PromptOperation` は LLM 呼び出しを含む operation を妨害 (リロード / LLM 失敗 /
 * プロセスクラッシュ / 2 タブ並行) に対して堅牢化するための job coordinator。
 * `Prompt` クラスは無変更で使い、Operation で wrap するだけで以下が得られる:
 *
 * - 同一入力 (scope + operation_name + idempotency_key) は 1 つの Job として束ねる
 * - 2 つ目以降の呼び出しは SameOperationFollower として既存 Job を follow
 * - phase 単位で完了マーカーを残し、次回呼び出しは未完了 phase のみ再実行
 * - heartbeat TTL 切れで stale 判定 → 別プロセスが claim 引き継ぎ
 * - phase ↔ llm_call_log の N:N 紐付けで監査トレース
 *
 * このサンプルは training アプリの "initial-message" operation を想定:
 * 1. facilitator NPC の冒頭メッセージ生成 (LLM call 1 回)
 * 2. 進捗ガイドの初期評価 (LLM call 1 回)
 *
 * 各 phase で Prompt を呼び、結果を draft で永続化 → onCommit で active に promote。
 */

use App\Models\Training\TrainingSessionMessage;
use App\Models\UserScenarioProgress;
use App\Services\AI\Prompts\AnalyzeProgressPrompt;
use App\Services\AI\Prompts\GenerateInitialMessagePrompt;
use App\Services\Billing\TrainingCreditService;
use Carbon\CarbonImmutable;
use Kent013\PrismPrompt\Operation\AlreadyCancelled;
use Kent013\PrismPrompt\Operation\AlreadyCompleted;
use Kent013\PrismPrompt\Operation\AlreadyFailed;
use Kent013\PrismPrompt\Operation\BlockedBySerialization;
use Kent013\PrismPrompt\Operation\OwnerClaim;
use Kent013\PrismPrompt\Operation\PromptJobPhase;
use Kent013\PrismPrompt\Operation\PromptOperation;
use Kent013\PrismPrompt\Operation\PromptOperationHandle;
use Kent013\PrismPrompt\Operation\SameOperationFollower;
use Kent013\PrismPrompt\Operation\WaitResult;

// app 側の domain model (UserScenarioProgress 等)
$progress = UserScenarioProgress::find(7);

for ($attempt = 0; $attempt < 3; $attempt++) {
    $claim = PromptOperation::for($progress, 'training.initial-message', 'fixed')
        ->withPhases(['generate-initial-message', 'analyze-progress'])
        ->withSerializationGroup("training-write:{$progress->id}")
        ->withHeartbeatTtl(90)
        ->claimOrFollow();

    if ($claim instanceof OwnerClaim) {
        $handle = $claim->handle();

        try {
            // Phase 1: 冒頭メッセージ生成
            $handle->phase(
                'generate-initial-message',
                body: function (PromptJobPhase $phase) use ($handle, $progress): void {
                    $metadata = $phase->metadata()
                        ->subjectFromScope()
                        ->correlationIdFromPhase()
                        ->toArguments();

                    $response = (new GenerateInitialMessagePrompt)
                        ->withMetadata($metadata)
                        ->executeSync();

                    // event listener が llm_call_logs に correlation_id 付きで記録するので
                    // ここでは correlation_id を pending に登録 (2 段階解決)
                    $phase->attachLlmCallByCorrelationId($metadata['correlation_id']);

                    // 副作用永続化: draft で書き込む
                    $message = TrainingSessionMessage::create([
                        'user_scenario_progress_id' => $progress->id,
                        'job_id' => $handle->job()->id,
                        'attempt_id' => $phase->attemptId(),
                        'visibility' => 'draft',
                        'role' => 'assistant',
                        'content' => $response->content,
                        'occurred_at' => CarbonImmutable::now(),
                    ]);
                    $phase->setOutputReference("message:{$message->id}");
                },
                onCommit: function (PromptJobPhase $phase) use ($progress): void {
                    // Phase 完了 transaction 内で draft → active promote
                    TrainingSessionMessage::query()
                        ->where('user_scenario_progress_id', $progress->id)
                        ->where('visibility', 'active')
                        ->update(['visibility' => 'inactive']);
                    TrainingSessionMessage::query()
                        ->where('user_scenario_progress_id', $progress->id)
                        ->where('attempt_id', $phase->attemptId())
                        ->where('visibility', 'draft')
                        ->update(['visibility' => 'active']);
                },
                onSkipped: function (): void {
                    // 既に Phase 1 が完了していた場合 (再開時)。状態を再ロードする
                    // ここでは何もしないが、必要なら $messages を context にロードする
                },
            );

            // Phase 2: 進捗ガイド評価
            $handle->phase('analyze-progress', function (PromptJobPhase $phase): void {
                $response = (new AnalyzeProgressPrompt)
                    ->withMetadata($phase->metadata()->correlationIdFromPhase()->toArguments())
                    ->executeSync();
                $phase->attachLlmCallByCorrelationId($phase->metadata()->correlationIdFromPhase()->toArguments()['correlation_id']);
                // ... 永続化 ...
            });

            // 全 phase 完了 → status='completed' + credit commit
            $handle->complete(onCommit: function (PromptOperationHandle $h): void {
                app(TrainingCreditService::class)->commitOperation($h);
            });

            // SSE で永続化済 messages を replay する等 (省略)
        } catch (Throwable $e) {
            $handle->fail($e, onFail: function (PromptOperationHandle $h): void {
                app(TrainingCreditService::class)->releaseOperation($h);
            });

            throw $e;
        }

        break;
    }

    if ($claim instanceof SameOperationFollower) {
        // 別リクエストが既に owner として処理中。完了を polling で待つ
        $result = $claim->handle()->follow();
        if ($result->isCompleted()) {
            // 永続化済み messages を読み出して SSE replay
            break;
        }
        if ($result->isStale()) {
            // owner が heartbeat 切れ → 上位ループで claim 試行
            continue;
        }
        if ($result->isFailed() || $result->isCancelled() || $result->isTimeout()) {
            // エラー event を返す
            break;
        }
    }

    if ($claim instanceof BlockedBySerialization) {
        // 同 scope の別 operation (例: send-message) が走っている。lock 解放を待つ
        $waitResult = $claim->waitForLockRelease(90);
        if ($waitResult === WaitResult::Timeout) {
            // 諦めて 425 Too Early 等を返す
            break;
        }

        continue;  // 解放 → 自分の Job claim を再試行
    }

    if ($claim instanceof AlreadyCompleted) {
        // 既に過去の試行で完了している。永続化済み messages を replay
        break;
    }
    if ($claim instanceof AlreadyFailed) {
        // 過去試行が失敗 + retryFailed=false の場合。エラー返却
        break;
    }
    if ($claim instanceof AlreadyCancelled) {
        // 明示キャンセル済み
        break;
    }
}
