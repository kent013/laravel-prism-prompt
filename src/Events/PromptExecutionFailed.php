<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Events;

use Throwable;

/**
 * Dispatched when an LLM call fails with an exception.
 * Failed calls may still incur API costs.
 */
final readonly class PromptExecutionFailed
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $executionId,
        public string $promptClass,
        public ?string $promptTemplate,
        public string $provider,
        public string $model,
        public float $durationMs,
        public Throwable $exception,
        public array $metadata,
    ) {}
}
