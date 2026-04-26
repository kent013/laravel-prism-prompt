<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Kent013\PrismPrompt\Operation\Models\PromptJob;

/**
 * PromptOperationHandle::follow() の戻り値。
 */
final readonly class FollowResult
{
    private function __construct(
        public string $kind,        // 'completed' | 'failed' | 'cancelled' | 'stale' | 'timeout'
        public PromptJob $job,
    ) {}

    public static function completed(PromptJob $job): self
    {
        return new self('completed', $job);
    }

    public static function failed(PromptJob $job): self
    {
        return new self('failed', $job);
    }

    public static function cancelled(PromptJob $job): self
    {
        return new self('cancelled', $job);
    }

    public static function stale(PromptJob $job): self
    {
        return new self('stale', $job);
    }

    public static function timeout(PromptJob $job): self
    {
        return new self('timeout', $job);
    }

    public function isCompleted(): bool
    {
        return $this->kind === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->kind === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->kind === 'cancelled';
    }

    public function isStale(): bool
    {
        return $this->kind === 'stale';
    }

    public function isTimeout(): bool
    {
        return $this->kind === 'timeout';
    }
}
