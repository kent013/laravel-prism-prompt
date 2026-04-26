<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Kent013\PrismPrompt\Operation\Models\PromptJob;

final readonly class AlreadyCancelled implements ClaimResult
{
    public function __construct(public PromptJob $job) {}

    public function job(): PromptJob
    {
        return $this->job;
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
        return false;
    }

    public function isAlreadyTerminal(): bool
    {
        return true;
    }
}
