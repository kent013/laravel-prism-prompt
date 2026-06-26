<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Values;

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\PromptPool;

/**
 * Provider call metadata captured while parsing a pooled / warmup HTTP
 * response in {@see PromptPool}.
 *
 * The package deliberately knows nothing about cost: it only retains the raw
 * provider `usage` map, the resolved model, the provider request id and the
 * full raw response body so that the application layer can map usage → tokens,
 * compute cost and persist an LLM call-cost ledger row. Pool/warmup HTTP calls
 * bypass {@see Prompt::executePrism()} and therefore never
 * emit `PromptExecutionCompleted`; this value object is the bridge that lets the
 * caller reconstruct the same telemetry.
 */
final readonly class PoolCallMeta
{
    /**
     * @param  array<string, mixed>|null  $usage  Raw provider `usage` map (e.g.
     *                                            Anthropic `input_tokens` /
     *                                            `output_tokens` / cache keys).
     *                                            Null when the response carried
     *                                            no usage block.
     */
    public function __construct(
        public ?array $usage,
        public ?string $model,
        public ?string $requestId,
        public string $rawBody,
    ) {}
}
