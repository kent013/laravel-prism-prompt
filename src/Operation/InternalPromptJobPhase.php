<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Carbon\CarbonImmutable;
use Kent013\PrismPrompt\Operation\Exceptions\StaleOwnershipException;
use Kent013\PrismPrompt\Operation\Models\PromptJob;
use Kent013\PrismPrompt\Operation\Models\PromptJobAttempt;
use Kent013\PrismPrompt\Operation\Models\PromptSerializationLock;

/**
 * @internal PromptJobPhase の実装。Handle::phase() body から渡される。
 */
final class InternalPromptJobPhase implements PromptJobPhase
{
    /** @var list<int> */
    private array $pendingLlmCallLogIds = [];

    /** @var list<string> */
    private array $pendingCorrelationIds = [];

    private ?string $outputReference = null;

    /**
     * Phase 内で setMetadata で蓄積されるローカル metadata。
     * Phase コミット時に Job model の metadata 列にマージされる。
     *
     * @var array<string, mixed>
     */
    private array $localMetadata = [];

    public function __construct(
        private readonly PromptJob $job,
        private readonly PromptJobAttempt $attempt,
        private readonly string $phaseName,
        private readonly int $heartbeatTtlSeconds,
        private readonly string $scopeType,
        private readonly string $scopeId,
        private readonly ?string $serializationGroup,
    ) {}

    public function name(): string
    {
        return $this->phaseName;
    }

    public function attemptId(): int
    {
        return $this->attempt->id;
    }

    public function attemptNumber(): int
    {
        return $this->attempt->attempt_number;
    }

    public function isCompleted(): bool
    {
        return $this->job->phases()->where('phase_name', $this->phaseName)->exists();
    }

    public function attachLlmCallLog(int $llmCallLogId): void
    {
        $this->pendingLlmCallLogIds[] = $llmCallLogId;
    }

    public function attachLlmCallByCorrelationId(string $correlationId): void
    {
        $this->pendingCorrelationIds[] = $correlationId;
    }

    public function setOutputReference(string $reference): void
    {
        $this->outputReference = $reference;
    }

    public function setMetadata(string $key, $value): void
    {
        $this->localMetadata[$key] = $value;
    }

    public function heartbeat(): void
    {
        $now = CarbonImmutable::now();
        $affected = PromptJob::query()
            ->where('id', $this->job->id)
            ->where('owner_token', $this->attempt->owner_token)
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

    public function metadata(): PromptMetadataBuilder
    {
        return new PromptMetadataBuilder(
            job: $this->job,
            attempt: $this->attempt,
            scopeType: $this->scopeType,
            scopeId: $this->scopeId,
            phaseName: $this->phaseName,
        );
    }

    /** @internal */
    public function attemptIdInternal(): int
    {
        return $this->attempt->id;
    }

    /** @internal */
    public function outputReferenceInternal(): ?string
    {
        return $this->outputReference;
    }

    /**
     * @return list<int>
     *
     * @internal
     */
    public function pendingLlmCallLogIds(): array
    {
        return $this->pendingLlmCallLogIds;
    }

    /**
     * @return list<string>
     *
     * @internal
     */
    public function pendingCorrelationIds(): array
    {
        return $this->pendingCorrelationIds;
    }

    /**
     * @return array<string, mixed>
     *
     * @internal Phase 完了時に Job metadata に merge される予定 (現状は accessor のみ提供)
     */
    public function localMetadata(): array
    {
        return $this->localMetadata;
    }
}
