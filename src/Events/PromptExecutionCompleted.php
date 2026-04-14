<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Events;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Usage;

/**
 * Dispatched when an LLM call completes successfully.
 * 1 executeSync() = 1 event.
 */
final readonly class PromptExecutionCompleted
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
        public FinishReason $finishReason,
        public int $stepCount,
        public Usage $totalUsage,
        public float $durationMs,
        public ?string $requestId,
        public TextResponse $response,
        public array $metadata,
    ) {}
}
