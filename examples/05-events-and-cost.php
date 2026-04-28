<?php

/**
 * Example 5: Events & Cost Calculation
 *
 * Event-driven hooks (added in v0.7) plus the USD cost information
 * (added in v0.8) computed automatically per call.
 *
 * - `Prompt::executeSync()` dispatches `PromptExecutionCompleted` /
 *   `PromptExecutionFailed` Laravel events.
 * - `withMetadata()` lets callers attach arbitrary domain context
 *   (tenant id, evaluation id, ...) that flows verbatim into the event.
 * - `$event->cost` is a `CostCalculation?` (USD scalars + a
 *   `PricingSnapshot`), resolved from `config/prism-prompt-pricing.php`.
 *
 * ⚠ FX conversion (USD → JPY etc.) and database persistence are
 *    deliberately out of scope; wire those up in your own listener.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;
use Kent013\PrismPrompt\Prompt;

// ════════════════════════════════════════════════════
// Scenario A: Caller attaches context
// ════════════════════════════════════════════════════

$result = Prompt::load('greeting', ['userName' => 'Alice'])
    ->withMetadata([
        // Any keys you like — your own listener reads them.
        'organization_id' => 42,
        'subject_type' => 'App\\Models\\Evaluation',
        'subject_id' => 1,
        'feature' => 'onboarding_greeting',
    ])
    ->executeSync();
// $result is whatever parseResponse() returns (raw text in this case).

// ════════════════════════════════════════════════════
// Scenario B: Record cost on success
// ════════════════════════════════════════════════════

Event::listen(PromptExecutionCompleted::class, function (PromptExecutionCompleted $event): void {
    // $event->cost has two distinct null-vs-not-null meanings.
    $cost = $event->cost;

    if ($cost === null) {
        // *** Failure signal *** — pricing resolution threw unexpectedly.
        // Unknown-model fall-through is *not* null; it returns a
        // snapshot with source='unknown_model:...' and a zero total.
        // So a null here means something is genuinely wrong upstream.
        Log::warning('llm_cost_resolution_failed', [
            'execution_id' => $event->executionId,
            'provider' => $event->provider,
            'model' => $event->model,
        ]);

        return;
    }

    // Happy path — USD scalars + a PricingSnapshot.
    //
    //   inputCostUsd       float
    //   outputCostUsd      float
    //   cacheWriteCostUsd  ?float  (null if the model has no cache pricing)
    //   cacheReadCostUsd   ?float
    //   totalCostUsd       float
    //   snapshot           PricingSnapshot  (Arrayable)
    //
    // Persist into your own llm_call_logs table:
    DB::table('llm_call_logs')->insert([
        'execution_id' => $event->executionId,
        'organization_id' => $event->metadata['organization_id'] ?? null,
        'subject_type' => $event->metadata['subject_type'] ?? null,
        'subject_id' => $event->metadata['subject_id'] ?? null,
        'provider' => $event->provider,
        'model' => $event->model,
        'input_tokens' => $event->totalUsage->promptTokens,
        'output_tokens' => $event->totalUsage->completionTokens,
        // DECIMAL(12,6) column with half-up rounding.
        'total_cost_usd' => number_format($cost->totalCostUsd, 6, '.', ''),
        // JSON column — PricingSnapshot is Arrayable.
        'pricing_snapshot' => json_encode($cost->snapshot->toArray()),
        'duration_ms' => (int) round($event->durationMs),
        'created_at' => now(),
    ]);

    // If you need a non-USD currency (e.g. JPY), call your FX service
    // here. The package only commits to USD.
});

// ════════════════════════════════════════════════════
// Scenario C: Record failures too
// ════════════════════════════════════════════════════

Event::listen(PromptExecutionFailed::class, function (PromptExecutionFailed $event): void {
    // Failed calls may still have incurred API cost.
    // exception + metadata are available; response / usage / cost are not.
    Log::error('llm_call_failed', [
        'execution_id' => $event->executionId,
        'provider' => $event->provider,
        'model' => $event->model,
        'duration_ms' => $event->durationMs,
        'organization_id' => $event->metadata['organization_id'] ?? null,
        'exception' => $event->exception::class.': '.$event->exception->getMessage(),
    ]);
});

// ════════════════════════════════════════════════════
// Scenario D: Unknown model handling
// ════════════════════════════════════════════════════
//
// When you call a model that is missing from the pricing table, the
// behaviour depends on `config('prism-prompt-pricing.unknown_model_behavior')`:
//
//   'zero' (default):
//     cost->totalCostUsd === 0.0
//     cost->snapshot->source === 'unknown_model:provider/model'
//     → Persist as a normal zero-cost row in your listener.
//
//   'throw':
//     `Prompt::executePrism()` raises InvalidArgumentException.
//     `executeSync()` re-throws, so catch at the call site or handle it
//     via PromptExecutionFailed.
//
// A practical workflow: leave temporary zero-cost rows in place when a
// new model ships, then update the pricing table and recompute the
// affected rows once you have authoritative rates.
