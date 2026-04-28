<?php

/**
 * Example 9: PromptPool — parallel execution with prompt caching
 *
 * Workloads where you run "the same big context with N small variations"
 * (rubric grading, heuristic auditing, per-page SEO checks) should not
 * be looped sequentially. Sequential `executeSync()`:
 *   - bills the input tokens N times,
 *   - accumulates latency.
 *
 * `PromptPool::executeWithWarmup()`:
 *   1. Sends prompt #1 alone (warmup) → the shared section is written
 *      to the Anthropic prompt cache.
 *   2. Sends the rest in parallel via `Http::pool` → each request reads
 *      the shared section as a cache hit.
 *
 * The example below runs a 5-axis rubric grader (rate the whole
 * encounter on five dimensions).
 *
 * ⚠ Anthropic only. Use `executeSync()` for OpenAI / Google.
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Exceptions\PoolExecutionException;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\PromptPool;
use Kent013\PrismPrompt\Values\CacheType;

// ── DTO ────────────────────────────────────────────
class RubricAxisScoreDto
{
    public function __construct(
        public readonly string $axis,
        public readonly int $score,        // 1-5
        public readonly string $feedback,  // 50-100 chars
    ) {}
}

// ── Prompt subclass ────────────────────────────────

/**
 * @extends Prompt<RubricAxisScoreDto>
 */
class RubricAxisPrompt extends Prompt
{
    public function __construct(
        public readonly string $axis,                 // 'empathy' / 'logic' / ...
        public readonly string $conversationContext,  // a few KB of conversation log shared by all 5 calls
        public readonly float $averageScore,
        public readonly int $turnCount,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $text): RubricAxisScoreDto
    {
        $data = $this->extractJson($text);

        /** @var array{axis: string, score: int, feedback: string} $data */
        return new RubricAxisScoreDto($data['axis'], $data['score'], $data['feedback']);
    }
}

// ── YAML ───────────────────────────────────────────
// resources/prompts/rubric/axis.yaml
//
// name: rubric.axis
// provider: anthropic
// model: claude-haiku-4-5-20251001
// max_tokens: 800
// temperature: 0.2
//
// system_prompt: |
//   You are an evaluator for business-communication training.
//   Score the requested axis on a 1-5 scale and return JSON.
//
// # `sections` defines named text fragments. Each prompt declares which
// # sections are cacheable via withCacheBreakpoints().
// sections:
//   shared: |
//     # Conversation log (the artefact under review)
//     {{ $conversationContext }}
//
//     # Auxiliary heuristic signals
//     Average score: {{ $averageScore }}
//     Turn count: {{ $turnCount }}
//   axis: |
//     # Scoring axis: {{ $axis }}
//
// prompt: |
//   Output: {"axis": "{{ $axis }}", "score": <1-5>, "feedback": "<50-100 chars>"}

// ════════════════════════════════════════════════════
// Parallel execution
// ════════════════════════════════════════════════════

$conversationContext = '...(several KB of conversation log)';
$averageScore = 3.4;
$turnCount = 12;

$axes = ['empathy', 'logic', 'specificity', 'inquiry', 'listening'];

// Build five prompts.
$prompts = collect($axes)
    ->map(fn (string $axis) => (new RubricAxisPrompt(
        axis: $axis,
        conversationContext: $conversationContext,
        averageScore: $averageScore,
        turnCount: $turnCount,
    ))
        // Cache the shared section ephemerally. It MUST be byte-stable.
        ->withCacheBreakpoints(['shared' => CacheType::Ephemeral])
        ->withMetadata([
            'organization_id' => $orgId,
            'subject_type' => 'App\\Models\\Encounter',
            'subject_id' => $encounterId,
            'rubric_axis' => $axis,  // listeners can aggregate cost per axis
        ]))
    ->all();

try {
    /** @var array<int, RubricAxisScoreDto> $results */
    $results = PromptPool::executeWithWarmup($prompts, concurrency: 5);
    // $results[0] = empathy, $results[1] = logic, ...
} catch (PoolExecutionException $e) {
    // We can identify which axis failed.
    $failedAxis = $axes[$e->getPromptIndex()];
    Log::error('rubric_axis_failed', [
        'axis' => $failedAxis,
        'previous' => $e->getPrevious()?->getMessage(),
    ]);

    throw $e;
}

// ════════════════════════════════════════════════════
// Verifying that caching kicked in
// ════════════════════════════════════════════════════
//
// Hook a PromptExecutionCompleted listener and inspect usage:
//
//   Event::listen(PromptExecutionCompleted::class, function ($e) {
//       // Warmup call:    cache_creation_input_tokens covers shared section.
//       // Subsequent calls: cache_read_input_tokens covers shared section.
//       Log::info('llm_pool_call', [
//           'axis' => $e->metadata['rubric_axis'] ?? null,
//           'usage' => $e->totalUsage,
//           'cost_usd' => $e->cost?->totalCostUsd,
//           'cache_read' => $e->cost?->cacheReadCostUsd,
//       ]);
//   });
//
// With a 4-8 KB shared section the cache_read rate is typically 1/10 of
// the input rate, so an N=5 fan-out costs roughly 1.4-1.8× a single
// call instead of the naive 5×.

// ════════════════════════════════════════════════════
// Caveats
// ════════════════════════════════════════════════════
//
// - The shared section MUST be byte-stable to register a cache hit.
//   No timestamps, no rounded floats with drifting precision, no random
//   numbers in that fragment.
// - The keys passed to `withCacheBreakpoints()` must match keys in the
//   YAML `sections:` map. Mismatched keys raise
//   InvalidCacheBreakpointException.
// - Concurrency defaults to `config('prism-prompt.pool.concurrency')`
//   (env PRISM_PROMPT_POOL_CONCURRENCY). Tune against the Anthropic
//   rate limit.
// - PromptPool only supports Anthropic. For other providers loop with
//   `Prompt::executeSync()`.
