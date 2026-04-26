<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PromptJobCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(public int $jobId) {}
}
