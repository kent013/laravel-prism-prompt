# Events, Metadata & USD Cost

Every `Prompt::executeSync()` dispatches a Laravel event. Subscribe from
anywhere in your app to record cost, usage, or audit trails — no
subclassing required.

| Event | Fires when |
|-------|------------|
| `PromptExecutionCompleted` | Call returned successfully |
| `PromptExecutionFailed`    | Call threw (network, validation, parse, etc.) |

Event dispatch is wrapped in a `try/catch`: a buggy listener will be
logged but will **never** propagate back into the LLM call site.

## Subscribing

```php
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;

Event::listen(PromptExecutionCompleted::class, function (PromptExecutionCompleted $event): void {
    // $event->executionId      — UUID for this call
    // $event->promptClass      — e.g. App\Prompts\GreetingPrompt
    // $event->promptTemplate   — basename of the YAML template, or null
    // $event->provider         — 'anthropic' / 'openai' / ...
    // $event->model            — resolved model id
    // $event->finishReason     — Prism\Prism\Enums\FinishReason
    // $event->stepCount        — number of Prism steps
    // $event->totalUsage       — Prism\Prism\ValueObjects\Usage
    // $event->durationMs       — float
    // $event->requestId        — provider request id, or null
    // $event->response         — Prism\Prism\Text\Response
    // $event->metadata         — array<string, mixed> from withMetadata()
    // $event->cost             — ?CostCalculation (see below)
});

Event::listen(PromptExecutionFailed::class, function (PromptExecutionFailed $event): void {
    // Same shape minus response/cost/totalUsage; adds $event->exception.
    // Failed calls may still have incurred API cost — decide your own policy.
});
```

## Caller-side context with `withMetadata()`

Attach domain context at the call site so listeners can attribute the
call to your tenant / user / subject:

```php
$result = (new GreetingPrompt('Alice'))
    ->withMetadata([
        'organization_id' => $orgId,
        'subject_type'    => Evaluation::class,
        'subject_id'      => $evaluation->id,
    ])
    ->executeSync();
```

`withMetadata()` merges on repeat calls. The array flows through
`$event->metadata` verbatim — the package never interprets it, so use
whatever keys your listener expects.

## USD cost

`PromptExecutionCompleted::$cost` is populated from
`config/prism-prompt-pricing.php` *before* the event fires. You get
per-token USD scalars plus an immutable `PricingSnapshot`.

```php
Event::listen(PromptExecutionCompleted::class, function (PromptExecutionCompleted $event): void {
    $cost = $event->cost;
    if ($cost === null) {
        // Pricing resolution threw unexpectedly — alert, not normal.
        return;
    }

    $cost->inputCostUsd;       // float
    $cost->outputCostUsd;      // float
    $cost->cacheWriteCostUsd;  // ?float (null when the model has no cache pricing)
    $cost->cacheReadCostUsd;   // ?float
    $cost->totalCostUsd;       // float

    // PricingSnapshot is Arrayable — drop it straight into a JSON column.
    $snapshotJson = $cost->snapshot->toArray();
    // PricingSnapshot::fromArray() restores it on read.
});
```

### Pricing table

`config/prism-prompt-pricing.php` (publishable) ships with current
Anthropic Claude rates. Extend it with any provider/model combo you call:

```php
return [
    'pricing_source' => env('PRISM_PROMPT_PRICING_SOURCE', 'vendor_YYYY-MM-DD'),
    'unknown_model_behavior' => env('PRISM_PROMPT_UNKNOWN_MODEL_BEHAVIOR', 'zero'),
    'models' => [
        'anthropic' => [
            'claude-sonnet-4-6' => [
                'input' => 3.00, 'output' => 15.00,
                'cache_write' => 3.75, 'cache_read' => 0.30,
            ],
            // ...
        ],
    ],
];
```

| Key | Description |
|-----|-------------|
| `pricing_source` | String embedded into every `PricingSnapshot`. Bump it when you update rates so old records stay auditable |
| `unknown_model_behavior` | `'zero'` (default) returns a zero-cost snapshot with a throttled `Log::warning` and `source='unknown_model:...'`. `'throw'` raises `InvalidArgumentException` |
| `models.{provider}.{model}` | Per-million-token rates: `input`, `output`, optional `cache_write`, optional `cache_read` |

### Billing notes

- Reasoning / `thought` tokens (Claude extended thinking) are billed at
  the `output` rate.
- Cache costs are only applied when both the usage value and the rate
  are non-null; otherwise they stay `null` on the result.
- **Non-USD currency conversion and database persistence are deliberately
  out of scope** for this package. Handle FX and storage in your own
  event listener.

### `cost === null` vs zero-cost snapshots

| Result | Meaning | Treat as |
|--------|---------|----------|
| `cost === null` | Pricing resolution threw unexpectedly | **Alert-worthy** — something is wrong upstream |
| `cost->totalCostUsd === 0.0` and `snapshot.source === 'unknown_model:...'` | Model not in the pricing table, fell back to zero per `unknown_model_behavior = zero` | Normal — expected for new models before you update the table |

See [`examples/05-events-and-cost.php`](../examples/05-events-and-cost.php)
for a complete listener example.
