<?php

/**
 * Example 5: Events & Cost Calculation
 *
 * v0.7 / v0.8 で追加された Event 駆動のフックと、
 * 自動的に計算される USD コスト情報を利用するパターン。
 *
 * - Prompt::executeSync() は PromptExecutionCompleted / PromptExecutionFailed を
 *   Laravel イベントとして dispatch する
 * - withMetadata() で呼び出し側の文脈（テナント ID、Evaluation ID 等）を
 *   そのまま event へ流し込める
 * - event->cost は CostCalculation?（USD スカラー + PricingSnapshot）
 *   — pricing 表（config/prism-prompt-pricing.php）から自動計算される
 *
 * ＊ FX 変換（USD→JPY 等）と DB 永続化は意図的に package 側では行わない。
 *    自分の ListenerServiceProvider で配線する。
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;
use Kent013\PrismPrompt\Prompt;

// ════════════════════════════════════════════════════
// Scenario A: 呼び出し側で context を付与
// ════════════════════════════════════════════════════

$result = Prompt::load('greeting', ['userName' => 'Alice'])
    ->withMetadata([
        // どんな key でも OK — 自作 Listener で読む
        'organization_id' => 42,
        'subject_type' => 'App\\Models\\Evaluation',
        'subject_id' => 1,
        'feature' => 'onboarding_greeting',
    ])
    ->executeSync();
// $result は通常どおり parseResponse() の戻り値（この場合は raw text）

// ════════════════════════════════════════════════════
// Scenario B: 成功 event を受けてコストを記録
// ════════════════════════════════════════════════════

Event::listen(PromptExecutionCompleted::class, function (PromptExecutionCompleted $event): void {
    // event に載っている cost は「pricing が解決できたか」で 2 通りある
    $cost = $event->cost;

    if ($cost === null) {
        // *** 障害兆候 ***: pricing resolution が内部で throw したケース。
        // unknown model の zero-cost ケースは null ではなく snapshot.source='unknown_model:...'
        // で返るので、この null は本当の異常だけを意味する。
        Log::warning('llm_cost_resolution_failed', [
            'execution_id' => $event->executionId,
            'provider' => $event->provider,
            'model' => $event->model,
        ]);

        return;
    }

    // 通常経路: USD スカラー + PricingSnapshot をそのまま使える
    //
    //   inputCostUsd       float
    //   outputCostUsd      float
    //   cacheWriteCostUsd  ?float  (モデルに cache 価格がなければ null)
    //   cacheReadCostUsd   ?float
    //   totalCostUsd       float
    //   snapshot           PricingSnapshot  (Arrayable)
    //
    // 例: アプリ側の llm_call_logs テーブルに書き出す
    DB::table('llm_call_logs')->insert([
        'execution_id' => $event->executionId,
        'organization_id' => $event->metadata['organization_id'] ?? null,
        'subject_type' => $event->metadata['subject_type'] ?? null,
        'subject_id' => $event->metadata['subject_id'] ?? null,
        'provider' => $event->provider,
        'model' => $event->model,
        'input_tokens' => $event->totalUsage->promptTokens,
        'output_tokens' => $event->totalUsage->completionTokens,
        // DECIMAL(12,6) カラム想定で half-up 丸め
        'total_cost_usd' => number_format($cost->totalCostUsd, 6, '.', ''),
        // JSON カラム — PricingSnapshot は Arrayable なのでそのまま
        'pricing_snapshot' => json_encode($cost->snapshot->toArray()),
        'duration_ms' => (int) round($event->durationMs),
        'created_at' => now(),
    ]);

    // 非 USD（例: JPY）が必要ならここで自分の FX サービスを呼ぶ
    // ー package 側は USD までしか責任を持たない
});

// ════════════════════════════════════════════════════
// Scenario C: 失敗 event も落とさず記録
// ════════════════════════════════════════════════════

Event::listen(PromptExecutionFailed::class, function (PromptExecutionFailed $event): void {
    // 失敗した LLM call も API 課金が発生している可能性はある。
    // exception と metadata は取れるが、response / usage / cost は入っていない。
    Log::error('llm_call_failed', [
        'execution_id' => $event->executionId,
        'provider' => $event->provider,
        'model' => $event->model,
        'duration_ms' => $event->durationMs,
        'organization_id' => $event->metadata['organization_id'] ?? null,
        'exception' => $event->exception::class.': '.$event->exception->getMessage(),
    ]);
});

// ════════════════════════════════════════════════════
// Scenario D: unknown model の扱い
// ════════════════════════════════════════════════════
//
// pricing 表に定義がないモデルを呼んだ場合、
// config('prism-prompt-pricing.unknown_model_behavior') に応じて挙動が変わる:
//
//   'zero' (default):
//     cost->totalCostUsd === 0.0
//     cost->snapshot->source === 'unknown_model:provider/model'
//     → Listener 側で「通常の zero-cost 行」として保存してよい。
//
//   'throw':
//     Prompt::executePrism() 内で InvalidArgumentException が上がる。
//     executeSync() は例外を再 throw するので、呼び出し側で catch するか
//     PromptExecutionFailed 側で拾うこと。
//
// モデル追加直後の一時的な zero-cost 行は後日 pricing 表を整備した後に
// 再計算で更新する運用が現実的。
