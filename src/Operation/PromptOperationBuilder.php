<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kent013\PrismPrompt\Operation\Exceptions\InconsistentPhaseManifestException;
use Kent013\PrismPrompt\Operation\Exceptions\InvalidPhaseManifestException;
use Kent013\PrismPrompt\Operation\Exceptions\LeaseSemanticMismatchException;
use Kent013\PrismPrompt\Operation\Models\PromptJob;
use Kent013\PrismPrompt\Operation\Models\PromptJobAttempt;
use Kent013\PrismPrompt\Operation\Models\PromptSerializationLock;

/**
 * @internal Use PromptOperation::for() to construct.
 */
final class PromptOperationBuilder
{
    /** @var list<string> */
    private array $phaseManifest = [];

    private int $heartbeatTtlSeconds;

    private ?string $serializationGroup = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private bool $retryFailed = true;

    public function __construct(
        private readonly Model $scope,
        private readonly string $operationName,
        private readonly string $idempotencyKey,
        private readonly int $operationVersion = 1,
    ) {
        $default = config('prism-prompt.jobs.default_heartbeat_ttl_seconds', 90);
        if (! is_int($default)) {
            $default = 90;
        }
        $this->heartbeatTtlSeconds = $default;
    }

    /**
     * @param  list<string>  $phaseNames
     */
    public function withPhases(array $phaseNames): self
    {
        if ($phaseNames === []) {
            throw new InvalidPhaseManifestException('Phase manifest must not be empty');
        }
        if (count($phaseNames) !== count(array_unique($phaseNames))) {
            throw new InvalidPhaseManifestException('Phase manifest contains duplicates');
        }
        foreach ($phaseNames as $name) {
            if ($name === '') {
                throw new InvalidPhaseManifestException('Phase name must not be empty');
            }
        }
        $this->phaseManifest = $phaseNames;

        return $this;
    }

    public function withHeartbeatTtl(int $seconds): self
    {
        $this->heartbeatTtlSeconds = $seconds;

        return $this;
    }

    public function withSerializationGroup(?string $group): self
    {
        $this->serializationGroup = $group;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function withRetryFailed(bool $retry): self
    {
        $this->retryFailed = $retry;

        return $this;
    }

    public function claimOrFollow(): ClaimResult
    {
        if ($this->phaseManifest === []) {
            throw new InvalidPhaseManifestException('Call withPhases() before claimOrFollow()');
        }

        $newToken = (string) Str::uuid();
        $scopeType = $this->scope->getMorphClass();
        $scopeId = (string) $this->scope->getKey();

        return DB::transaction(function () use ($newToken, $scopeType, $scopeId): ClaimResult {
            // Step A: 行確保 (race-safe)
            $this->ensureJobRowExists($scopeType, $scopeId);

            // Step B: lock 付き取得
            $job = PromptJob::query()
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->where('operation_name', $this->operationName)
                ->where('idempotency_key', $this->idempotencyKey)
                ->where('operation_version', $this->operationVersion)
                ->lockForUpdate()
                ->first();
            assert($job instanceof PromptJob);

            // Step C: phase manifest 整合性
            if ($job->phase_manifest !== $this->phaseManifest) {
                if ($job->phase_manifest === [] || $job->phase_manifest === ['__pending__']) {
                    // INSERT 直後 (manifest 未確定) なら最新で上書き
                    $job->phase_manifest = $this->phaseManifest;
                    $job->save();
                } else {
                    throw new InconsistentPhaseManifestException(
                        "Phase manifest mismatch for job {$job->id}: expected ".json_encode($job->phase_manifest)
                            .' got '.json_encode($this->phaseManifest)
                    );
                }
            }

            // v0.14.2 Step C-2: lease semantics (serialization_group / heartbeat_ttl_seconds) drift 検出
            // (audit Round 1 観点 1 Warning 対応)。
            //
            // 既存 Job の lease semantics と claim 時の指定値が異なる場合、subtle なバグ
            // (例: 既存 owner は heartbeat_ttl=180 で動いているのに新 claim が 90 で待つ → 早期 takeover)
            // を防ぐため LeaseSemanticMismatchException を throw する。
            // INSERT 直後 (job が初期値) は upgrade される。
            $semanticsAreInitial = $job->serialization_group === null && $job->heartbeat_ttl_seconds === 0;
            if ($semanticsAreInitial) {
                $job->serialization_group = $this->serializationGroup;
                $job->heartbeat_ttl_seconds = $this->heartbeatTtlSeconds;
                $job->save();
            } else {
                if ($job->serialization_group !== $this->serializationGroup) {
                    throw new LeaseSemanticMismatchException(
                        "Serialization group mismatch for job {$job->id}: existing="
                            .var_export($job->serialization_group, true)
                            .' got='.var_export($this->serializationGroup, true)
                            .'. operationVersion を bump してください'
                    );
                }
                if ($job->heartbeat_ttl_seconds !== $this->heartbeatTtlSeconds) {
                    throw new LeaseSemanticMismatchException(
                        "Heartbeat TTL mismatch for job {$job->id}: existing={$job->heartbeat_ttl_seconds}"
                            ." got={$this->heartbeatTtlSeconds}. operationVersion を bump してください"
                    );
                }
            }

            // Step D: terminal 早期返却 / follower 判定
            if ($job->status === 'completed') {
                return new AlreadyCompleted($job);
            }
            if ($job->status === 'cancelled') {
                return new AlreadyCancelled($job);
            }
            if ($job->status === 'failed' && ! $this->retryFailed) {
                return new AlreadyFailed($job);
            }
            if ($job->status === 'generating' && ! $job->isStale()) {
                return new SameOperationFollower($this->buildHandle($job));
            }

            // Step D-2: stale takeover の場合、過去 attempt を 'stale_takeover' でクローズ + event 発火
            // (Codex Round 1 Warning 反映: stale_takeover end_status と PromptJobStaleDetected event の活用)
            $previousAttemptId = null;
            if ($job->status === 'generating' && $job->isStale()) {
                $previousAttempt = PromptJobAttempt::query()
                    ->where('job_id', $job->id)
                    ->whereNull('ended_at')
                    ->orderByDesc('attempt_number')
                    ->first();
                if ($previousAttempt !== null) {
                    $previousAttempt->update([
                        'ended_at' => now(),
                        'end_status' => 'stale_takeover',
                    ]);
                    $previousAttemptId = $previousAttempt->id;
                }
            }

            // Step E: SerializationGroup 取得 (group 指定時のみ)
            if ($this->serializationGroup !== null) {
                $lockOwnerJobId = $this->acquireSerializationLock($scopeType, $scopeId, $job->id, $newToken);
                if ($lockOwnerJobId !== $job->id) {
                    return new BlockedBySerialization(
                        scopeType: $scopeType,
                        scopeId: $scopeId,
                        serializationGroup: $this->serializationGroup,
                        blockingJobId: $lockOwnerJobId,
                    );
                }
            }

            // Step F: claim (lockForUpdate 内なので CAS 不要、トランザクションで保護)
            // status は Step D/E で確認済み (claim 可能なケースのみここに到達)
            // SQL を DB 中立に保つため interval 計算は使わず、stale 判定は PHP 側 (isStale())
            $now = now();
            PromptJob::query()
                ->where('id', $job->id)
                ->update([
                    'status' => 'generating',
                    'owner_token' => $newToken,
                    'current_phase' => null,
                    'heartbeat_at' => $now,
                    'started_at' => $job->started_at ?? $now,
                    'last_error_class' => null,
                    'last_error_message' => null,
                    'updated_at' => $now,
                ]);

            $job = $job->fresh();
            assert($job instanceof PromptJob);

            // Step G: attempt insert
            $attempt = PromptJobAttempt::create([
                'job_id' => $job->id,
                'attempt_number' => ($job->attempts()->max('attempt_number') ?? 0) + 1,
                'owner_token' => $newToken,
                'started_at' => $now,
            ]);

            event(new Events\PromptJobClaimed(
                jobId: $job->id,
                ownerToken: $newToken,
                attemptNumber: $attempt->attempt_number,
            ));

            if ($previousAttemptId !== null) {
                event(new Events\PromptJobStaleDetected(
                    jobId: $job->id,
                    previousAttemptId: $previousAttemptId,
                ));
            }

            return new OwnerClaim($this->buildHandle($job, $attempt));
        });
    }

    private function ensureJobRowExists(string $scopeType, string $scopeId): void
    {
        // Postgres / MySQL 両対応の race-safe insert (CONFLICT は raw 不要、Eloquent firstOrCreate 相当)
        PromptJob::query()->firstOrCreate(
            [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'operation_name' => $this->operationName,
                'idempotency_key' => $this->idempotencyKey,
                'operation_version' => $this->operationVersion,
            ],
            [
                'serialization_group' => $this->serializationGroup,
                'status' => 'pending',
                'phase_manifest' => $this->phaseManifest,
                'heartbeat_ttl_seconds' => $this->heartbeatTtlSeconds,
                'metadata' => $this->metadata !== [] ? $this->metadata : null,
            ],
        );
    }

    /**
     * lock テーブルを更新し、owner となった job_id を返す。自分が取れなければ blocking job_id が返る。
     */
    private function acquireSerializationLock(string $scopeType, string $scopeId, int $jobId, string $newToken): int
    {
        $now = now();
        $expiresAt = $now->copy()->addSeconds($this->heartbeatTtlSeconds);

        // 既存 lock 取得 (FOR UPDATE)
        $existing = PromptSerializationLock::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('serialization_group', $this->serializationGroup)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            PromptSerializationLock::create([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'serialization_group' => $this->serializationGroup,
                'job_id' => $jobId,
                'owner_token' => $newToken,
                'acquired_at' => $now,
                'heartbeat_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            return $jobId;
        }

        if ($existing->job_id === $jobId) {
            // 自分が既に保持している (再 claim) → 更新
            $existing->update([
                'owner_token' => $newToken,
                'acquired_at' => $now,
                'heartbeat_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            return $jobId;
        }

        if ($existing->isExpired($now->toImmutable())) {
            // 期限切れ → 奪取
            $existing->update([
                'job_id' => $jobId,
                'owner_token' => $newToken,
                'acquired_at' => $now,
                'heartbeat_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            return $jobId;
        }

        return (int) $existing->job_id;
    }

    private function buildHandle(PromptJob $job, ?PromptJobAttempt $attempt = null): PromptOperationHandle
    {
        return new PromptOperationHandle(
            job: $job,
            ownerAttempt: $attempt,
            scopeType: $this->scope->getMorphClass(),
            scopeId: (string) $this->scope->getKey(),
            phaseManifest: $this->phaseManifest,
            heartbeatTtlSeconds: $this->heartbeatTtlSeconds,
            serializationGroup: $this->serializationGroup,
        );
    }
}
