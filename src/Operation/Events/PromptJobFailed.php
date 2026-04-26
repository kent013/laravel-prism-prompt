<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PromptJobFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $jobId,
        public string $errorClass,
        public string $errorMessage,
    ) {}
}
