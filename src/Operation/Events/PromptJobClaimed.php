<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PromptJobClaimed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $jobId,
        public string $ownerToken,
        public int $attemptNumber,
    ) {}
}
