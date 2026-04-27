<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Carbon\CarbonImmutable;
use Kent013\PrismPrompt\Operation\Models\PromptSerializationLock;

final class BlockedBySerialization implements ClaimResult
{
    public function __construct(
        private readonly string $scopeType,
        private readonly string $scopeId,
        private readonly string $serializationGroup,
        private readonly int $blockingJobId,
    ) {}

    public function blockingJobId(): int
    {
        return $this->blockingJobId;
    }

    /**
     * lock の解放を polling 待機する。
     */
    public function waitForLockRelease(int $timeoutSeconds = 90): WaitResult
    {
        $deadline = CarbonImmutable::now()->addSeconds($timeoutSeconds);
        $intervalsMs = [250, 500, 1000, 2000, 2000];
        $i = 0;
        while (CarbonImmutable::now()->lt($deadline)) {
            $lock = PromptSerializationLock::query()
                ->where('scope_type', $this->scopeType)
                ->where('scope_id', $this->scopeId)
                ->where('serialization_group', $this->serializationGroup)
                ->first();

            if ($lock === null || $lock->isExpired()) {
                return WaitResult::Released;
            }
            $sleepMs = $intervalsMs[min($i, count($intervalsMs) - 1)];
            usleep($sleepMs * 1000);
            $i++;
        }

        return WaitResult::Timeout;
    }

    public function isOwner(): bool
    {
        return false;
    }

    public function isSameOperationFollower(): bool
    {
        return false;
    }

    public function isBlockedBySerialization(): bool
    {
        return true;
    }

    public function isAlreadyTerminal(): bool
    {
        return false;
    }
}
