<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

final readonly class OwnerClaim implements ClaimResult
{
    public function __construct(public PromptOperationHandle $handle) {}

    public function handle(): PromptOperationHandle
    {
        return $this->handle;
    }

    public function isOwner(): bool
    {
        return true;
    }

    public function isSameOperationFollower(): bool
    {
        return false;
    }

    public function isBlockedBySerialization(): bool
    {
        return false;
    }

    public function isAlreadyTerminal(): bool
    {
        return false;
    }
}
