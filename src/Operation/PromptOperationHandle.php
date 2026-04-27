<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Kent013\PrismPrompt\Operation\Exceptions\IncompletePhaseException;
use Kent013\PrismPrompt\Operation\Exceptions\StaleOwnershipException;
use Kent013\PrismPrompt\Operation\Exceptions\UnknownPhaseException;
use Kent013\PrismPrompt\Operation\Models\PendingLlmCallResolution;
use Kent013\PrismPrompt\Operation\Models\PromptJob;
use Kent013\PrismPrompt\Operation\Models\PromptJobAttempt;
use Kent013\PrismPrompt\Operation\Models\PromptJobPhaseLlmCall;
use Kent013\PrismPrompt\Operation\Models\PromptJobPhaseRecord;
use Kent013\PrismPrompt\Operation\Models\PromptSerializationLock;
use Throwable;

final class PromptOperationHandle
{
    /** @param list<string> $phaseManifest */
    public function __construct(
        private PromptJob $job,
        private readonly ?PromptJobAttempt $ownerAttempt,
        private readonly string $scopeType,
        private readonly string $scopeId,
        private readonly array $phaseManifest,
        private readonly int $heartbeatTtlSeconds,
        private readonly ?string $serializationGroup,
    ) {}

    public function isOwner(): bool
    {
        return $this->ownerAttempt !== null;
    }

    public function ownerToken(): ?string
    {
        return $this->ownerAttempt?->owner_token;
    }

    public function job(): PromptJob
    {
        return $this->job;
    }

    /**
     * Phase 実行ラッパー。
     *
     * @param  Closure(PromptJobPhase): mixed  $body
     * @param  null|Closure(CompletedPhaseRecord): void  $onSkipped  既に完了済 phase の skip 時
     * @param  null|Closure(PromptJobPhase): void  $onCommit  phase 完了 transaction 内で実行 (副作用 promote 等)
     */
    public function phase(
        string $name,
        Closure $body,
        ?Closure $onSkipped = null,
        ?Closure $onCommit = null,
    ): mixed {
        if (! in_array($name, $this->phaseManifest, true)) {
            throw new UnknownPhaseException("Phase '{$name}' is not in manifest");
        }

        $existing = $this->getCompletedPhaseRecord($name);
        if ($existing !== null) {
            if ($onSkipped !== null) {
                $onSkipped(new CompletedPhaseRecord(
                    name: $existing->phase_name,
                    outputReference: $existing->output_reference,
                    completedAt: $existing->completed_at,
                ));
            }

            return null;
        }

        $this->assertOwnership();
        $this->updateCurrentPhase($name);

        $attempt = $this->ownerAttempt;
        assert($attempt !== null);

        event(new Events\PromptJobPhaseStarted(
            jobId: $this->job->id,
            phaseName: $name,
            attemptId: $attempt->id,
        ));

        $phaseHandle = new InternalPromptJobPhase(
            job: $this->job,
            attempt: $attempt,
            phaseName: $name,
            heartbeatTtlSeconds: $this->heartbeatTtlSeconds,
            scopeType: $this->scopeType,
            scopeId: $this->scopeId,
            serializationGroup: $this->serializationGroup,
        );

        try {
            $result = $body($phaseHandle);

            // v0.14.0: commit transaction を共通 helper に切り出し (streamingPhase と共有)
            $this->commitPhase($phaseHandle, $name, $onCommit);

            event(new Events\PromptJobPhaseCompleted(
                jobId: $this->job->id,
                phaseName: $name,
                attemptId: $attempt->id,
                outputReference: $phaseHandle->outputReferenceInternal(),
            ));

            return $result;
        } catch (Throwable $e) {
            $this->recordPhaseError($name, $e);
            event(new Events\PromptJobPhaseFailed(
                jobId: $this->job->id,
                phaseName: $name,
                attemptId: $attempt->id,
                errorClass: $e::class,
                errorMessage: $e->getMessage(),
            ));

            throw $e;
        }
    }

    /**
     * v0.14.0: Phase 実行 + 中で yield された値を caller に forward する Generator API。
     *
     * SSE / streaming pipeline を phase scope に取り込むためのもの。`phase()` と異なり
     * body は Generator を返す (`yield` で値を caller に流す)。
     *
     * 利点:
     * - phase scope 内で実 work が走るので heartbeat / ownership 再確認が正しく適用される
     * - `phase()` を no-op marker として使う pattern (T388/T393 で発生) を不要にする
     * - 監査 (devnotes/20260427-2200-comprehensive-audit/) Critical 1 の構造的解消
     *
     * 使用例:
     * ```php
     * yield from $handle->streamingPhase('send-message-pipeline', function ($phase) {
     *     yield from $pipeline->stream(); // SSE event を caller に forward
     * });
     * ```
     *
     * v0.14.1: PHPStan generic 型 (`@template`) を導入し、body の yield 型を caller の
     * `yield from` 結果へ正確に伝播させる。これにより app 側で
     * `\Generator<int, array{event: string, data: array<string, mixed>}>` のような
     * 厳格な戻り値を持つ method 内で `yield from streamingPhase(...)` が型安全になる。
     *
     * @template TKey
     * @template TYield
     *
     * @param  Closure(PromptJobPhase): \Generator<TKey, TYield, mixed, mixed>  $body
     * @param  null|Closure(CompletedPhaseRecord): void  $onSkipped
     * @param  null|Closure(PromptJobPhase): void  $onCommit
     * @return \Generator<TKey, TYield, mixed, mixed>
     */
    public function streamingPhase(
        string $name,
        Closure $body,
        ?Closure $onSkipped = null,
        ?Closure $onCommit = null,
    ): \Generator {
        if (! in_array($name, $this->phaseManifest, true)) {
            throw new UnknownPhaseException("Phase '{$name}' is not in manifest");
        }

        $existing = $this->getCompletedPhaseRecord($name);
        if ($existing !== null) {
            if ($onSkipped !== null) {
                $onSkipped(new CompletedPhaseRecord(
                    name: $existing->phase_name,
                    outputReference: $existing->output_reference,
                    completedAt: $existing->completed_at,
                ));
            }

            return;
        }

        $this->assertOwnership();
        $this->updateCurrentPhase($name);

        $attempt = $this->ownerAttempt;
        assert($attempt !== null);

        event(new Events\PromptJobPhaseStarted(
            jobId: $this->job->id,
            phaseName: $name,
            attemptId: $attempt->id,
        ));

        $phaseHandle = new InternalPromptJobPhase(
            job: $this->job,
            attempt: $attempt,
            phaseName: $name,
            heartbeatTtlSeconds: $this->heartbeatTtlSeconds,
            scopeType: $this->scopeType,
            scopeId: $this->scopeId,
            serializationGroup: $this->serializationGroup,
        );

        try {
            $generator = $body($phaseHandle);
            if (! $generator instanceof \Generator) {
                throw new \TypeError(
                    "streamingPhase body must return a Generator. Did you forget `yield`? phase='{$name}'"
                );
            }
            // body の yield をすべて caller に forward
            yield from $generator;

            // body 完了後に commit transaction (phase() と同一ロジック)
            $this->commitPhase($phaseHandle, $name, $onCommit);

            event(new Events\PromptJobPhaseCompleted(
                jobId: $this->job->id,
                phaseName: $name,
                attemptId: $attempt->id,
                outputReference: $phaseHandle->outputReferenceInternal(),
            ));
        } catch (Throwable $e) {
            $this->recordPhaseError($name, $e);
            event(new Events\PromptJobPhaseFailed(
                jobId: $this->job->id,
                phaseName: $name,
                attemptId: $attempt->id,
                errorClass: $e::class,
                errorMessage: $e->getMessage(),
            ));

            throw $e;
        }
    }

    /**
     * v0.14.0: phase() と streamingPhase() で共有する commit transaction.
     * (元々 phase() 内に inline されていたものを切り出し)
     */
    private function commitPhase(
        InternalPromptJobPhase $phaseHandle,
        string $name,
        ?Closure $onCommit,
    ): void {
        DB::transaction(function () use ($phaseHandle, $name, $onCommit): void {
            // 1. ownership 再確認
            $current = PromptJob::query()->lockForUpdate()->find($this->job->id);
            if ($current === null || $current->owner_token !== $this->ownerToken()) {
                throw new StaleOwnershipException(
                    "Ownership lost for job {$this->job->id} during phase '{$name}' commit"
                );
            }

            // 2. phase row insert (UNIQUE 重複は例外を起こさせる)
            $phaseOrder = array_search($name, $this->phaseManifest, true);
            $phaseRecord = PromptJobPhaseRecord::create([
                'job_id' => $this->job->id,
                'phase_name' => $name,
                'phase_order' => $phaseOrder === false ? 0 : $phaseOrder,
                'attempt_id' => $phaseHandle->attemptIdInternal(),
                'output_reference' => $phaseHandle->outputReferenceInternal(),
                'completed_at' => CarbonImmutable::now(),
            ]);

            // 3. pending llm_call_log_ids を join table に insert
            $pendingDirect = $phaseHandle->pendingLlmCallLogIds();
            $sequence = 1;
            foreach ($pendingDirect as $logId) {
                PromptJobPhaseLlmCall::create([
                    'phase_id' => $phaseRecord->id,
                    'llm_call_log_id' => $logId,
                    'sequence' => $sequence++,
                    'created_at' => CarbonImmutable::now(),
                ]);
            }

            // 4. correlation_id 経由を 2 段階解決
            $logsTable = (string) config('prism-prompt.jobs.llm_call_logs_table', 'llm_call_logs');
            foreach ($phaseHandle->pendingCorrelationIds() as $cid) {
                $row = DB::table($logsTable)->where('correlation_id', $cid)->first();
                if ($row !== null) {
                    PromptJobPhaseLlmCall::create([
                        'phase_id' => $phaseRecord->id,
                        'llm_call_log_id' => (int) $row->id,
                        'sequence' => $sequence++,
                        'created_at' => CarbonImmutable::now(),
                    ]);
                    PendingLlmCallResolution::create([
                        'phase_id' => $phaseRecord->id,
                        'correlation_id' => $cid,
                        'sequence' => $sequence - 1,
                        'resolved_at' => CarbonImmutable::now(),
                        'created_at' => CarbonImmutable::now(),
                    ]);
                } else {
                    PendingLlmCallResolution::create([
                        'phase_id' => $phaseRecord->id,
                        'correlation_id' => $cid,
                        'sequence' => $sequence++,
                        'resolved_at' => null,
                        'created_at' => CarbonImmutable::now(),
                    ]);
                }
            }

            // 5. app onCommit
            if ($onCommit !== null) {
                $onCommit($phaseHandle);
            }

            // 6. heartbeat
            $this->touchHeartbeatInternal();
        });
    }

    /**
     * 全 required phase が完了済みであることを検証してから status='completed' に。
     *
     * @param  null|Closure(self): void  $onCommit  transaction 内で credit commit 等を実行
     */
    public function complete(?Closure $onCommit = null): void
    {
        $completed = $this->job->phases()->pluck('phase_name')->all();
        $missing = array_diff($this->phaseManifest, $completed);
        if ($missing !== []) {
            throw new IncompletePhaseException('Missing phases: '.implode(', ', $missing));
        }

        DB::transaction(function () use ($onCommit): void {
            $current = PromptJob::query()->lockForUpdate()->find($this->job->id);
            if ($current === null || $current->owner_token !== $this->ownerToken()) {
                throw new StaleOwnershipException("Ownership lost for job {$this->job->id} during complete()");
            }
            $now = CarbonImmutable::now();
            $current->update([
                'status' => 'completed',
                'completed_at' => $now,
            ]);
            $this->ownerAttempt?->update([
                'ended_at' => $now,
                'end_status' => 'completed',
            ]);

            if ($onCommit !== null) {
                $onCommit($this);
            }

            $this->releaseSerializationLockIfHeld();
        });
        event(new Events\PromptJobCompleted(jobId: $this->job->id));
    }

    /**
     * @param  null|Closure(self): void  $onFail  transaction 内で credit release 等
     */
    public function fail(Throwable $e, ?Closure $onFail = null): void
    {
        DB::transaction(function () use ($e, $onFail): void {
            $current = PromptJob::query()->lockForUpdate()->find($this->job->id);
            if ($current === null) {
                return;
            }
            // Codex Round 1 Critical 反映: stale owner が現 owner の Job を fail にできないよう owner_token 検証
            if ($this->ownerToken() === null || $current->owner_token !== $this->ownerToken()) {
                throw new StaleOwnershipException("Cannot fail job {$this->job->id}: ownership lost");
            }
            $now = CarbonImmutable::now();
            $current->update([
                'status' => 'failed',
                'last_error_class' => $e::class,
                'last_error_message' => $e->getMessage(),
            ]);
            $this->ownerAttempt?->update([
                'ended_at' => $now,
                'end_status' => 'failed',
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);
            if ($onFail !== null) {
                $onFail($this);
            }
            $this->releaseSerializationLockIfHeld();
        });
        event(new Events\PromptJobFailed(
            jobId: $this->job->id,
            errorClass: $e::class,
            errorMessage: $e->getMessage(),
        ));
    }

    /**
     * @param  null|Closure(self): void  $onCancel  transaction 内で credit release 等
     */
    public function cancel(?string $reason = null, ?Closure $onCancel = null): void
    {
        DB::transaction(function () use ($reason, $onCancel): void {
            $current = PromptJob::query()->lockForUpdate()->find($this->job->id);
            if ($current === null) {
                return;
            }
            // Codex Round 1 Critical 反映: stale owner が現 owner の Job を cancel できないよう owner_token 検証
            // 例外: ownerAttempt=null (follower 経由の cancel) は許可しない (本 API は owner 専用)
            if ($this->ownerToken() === null || $current->owner_token !== $this->ownerToken()) {
                throw new StaleOwnershipException("Cannot cancel job {$this->job->id}: ownership lost");
            }
            $now = CarbonImmutable::now();
            $current->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancelled_reason' => $reason,
            ]);
            $this->ownerAttempt?->update([
                'ended_at' => $now,
                'end_status' => 'cancelled',
            ]);
            if ($onCancel !== null) {
                $onCancel($this);
            }
            $this->releaseSerializationLockIfHeld();
        });
        event(new Events\PromptJobCancelled(jobId: $this->job->id, reason: $reason));
    }

    /**
     * Follower 専用: leader の完了を待ち、結果を返す。
     */
    public function follow(): FollowResult
    {
        $intervalsMs = (array) config('prism-prompt.jobs.follower_poll_intervals_ms', [250, 500, 1000, 2000, 2000, 2000]);
        $maxWait = (int) config('prism-prompt.jobs.follower_max_wait_seconds', 120);
        $deadline = CarbonImmutable::now()->addSeconds($maxWait);
        $i = 0;
        while (CarbonImmutable::now()->lt($deadline)) {
            /** @var PromptJob|null $job */
            $job = $this->job->fresh();
            if ($job === null) {
                return FollowResult::cancelled($this->job);
            }
            if ($job->status === 'completed') {
                return FollowResult::completed($job);
            }
            if ($job->status === 'failed') {
                return FollowResult::failed($job);
            }
            if ($job->status === 'cancelled') {
                return FollowResult::cancelled($job);
            }
            if ($job->isStale()) {
                return FollowResult::stale($job);
            }
            $sleep = (int) $intervalsMs[min($i, count($intervalsMs) - 1)];
            usleep($sleep * 1000);
            $i++;
        }

        return FollowResult::timeout($this->job->fresh() ?? $this->job);
    }

    public function metadata(): PromptMetadataBuilder
    {
        return new PromptMetadataBuilder(
            job: $this->job,
            attempt: $this->ownerAttempt,
            scopeType: $this->scopeType,
            scopeId: $this->scopeId,
        );
    }

    private function getCompletedPhaseRecord(string $name): ?PromptJobPhaseRecord
    {
        return $this->job->phases()->where('phase_name', $name)->first();
    }

    private function assertOwnership(): void
    {
        if ($this->ownerAttempt === null) {
            throw new StaleOwnershipException("Cannot run phase: not the owner of job {$this->job->id}");
        }
    }

    private function updateCurrentPhase(string $name): void
    {
        PromptJob::query()
            ->where('id', $this->job->id)
            ->where('owner_token', $this->ownerToken())
            ->update(['current_phase' => $name]);
    }

    private function recordPhaseError(string $name, Throwable $e): void
    {
        // Codex Round 1 Critical 反映: stale owner の例外で現 owner の last_error を上書きしないよう
        // owner_token 一致時のみ更新
        PromptJob::query()
            ->where('id', $this->job->id)
            ->where('owner_token', $this->ownerToken())
            ->update([
                'last_error_class' => $e::class,
                'last_error_message' => $e->getMessage(),
            ]);
    }

    private function touchHeartbeatInternal(): void
    {
        $now = CarbonImmutable::now();
        $affected = PromptJob::query()
            ->where('id', $this->job->id)
            ->where('owner_token', $this->ownerToken())
            ->update(['heartbeat_at' => $now]);
        if ($affected === 0) {
            throw new StaleOwnershipException("Heartbeat lost for job {$this->job->id}");
        }
        if ($this->serializationGroup !== null) {
            PromptSerializationLock::query()
                ->where('scope_type', $this->scopeType)
                ->where('scope_id', $this->scopeId)
                ->where('serialization_group', $this->serializationGroup)
                ->where('job_id', $this->job->id)
                ->update([
                    'heartbeat_at' => $now,
                    'expires_at' => $now->addSeconds($this->heartbeatTtlSeconds),
                ]);
        }
    }

    private function releaseSerializationLockIfHeld(): void
    {
        if ($this->serializationGroup === null) {
            return;
        }
        // Codex Round 1 Critical 反映: stale owner が現 owner の lock を削除しないよう owner_token 一致条件を追加
        $ownerToken = $this->ownerToken();
        if ($ownerToken === null) {
            return;
        }
        PromptSerializationLock::query()
            ->where('scope_type', $this->scopeType)
            ->where('scope_id', $this->scopeId)
            ->where('serialization_group', $this->serializationGroup)
            ->where('job_id', $this->job->id)
            ->where('owner_token', $ownerToken)
            ->delete();
    }
}
