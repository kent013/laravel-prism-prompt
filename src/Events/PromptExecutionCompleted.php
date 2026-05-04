<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Events;

use Kent013\PrismPrompt\Pricing\CostCalculation;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Usage;

/**
 * Dispatched when an LLM call completes successfully.
 * 1 executeSync() = 1 event.
 *
 * `cost` is populated automatically from the configured pricing table
 * (see `config/prism-prompt-pricing.php`). It is null only when pricing
 * resolution itself fails unexpectedly — unknown models fall back to a
 * zero-cost snapshot rather than null, so consumers can treat null as
 * an exceptional case.
 *
 * `response` is a union of Prism's TextResponse (legacy text path) and
 * StructuredResponse (Prism::structured() path). Both expose `->text`,
 * `->steps`, `->usage`, `->meta` so common access via `$event->response->text`
 * remains safe across both. Listeners that need StructuredResponse-only
 * fields (`->structured`) must narrow with `instanceof`.
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
        public TextResponse|StructuredResponse $response,
        public array $metadata,
        public ?CostCalculation $cost = null,
    ) {}
}
