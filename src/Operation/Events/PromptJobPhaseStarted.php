<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PromptJobPhaseStarted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $jobId,
        public string $phaseName,
        public int $attemptId,
    ) {}
}
