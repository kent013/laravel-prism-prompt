# Structured output (`Prism::structured()` integration)

Available since **v0.15.0**.

`Prompt` exposes two opt-in hooks that route an LLM call through
`Prism::structured()->withSchema(...)->asStructured()` instead of the legacy
`Prism::text()` + `extractJson()` regex. Provider-side schema enforcement
catches contract drift at request time rather than letting silent fallbacks
mask it after a buggy `extractJson` traversal.

## When to use

Use the structured path when **all of the following** hold:

- The LLM response must conform to a fixed object shape (required keys, value
  ranges, enum values).
- Your target provider/model supports structured output. Anthropic, OpenAI and
  Gemini in `echolabsdev/prism` v0.99+ all do.
- You want contract violations to fail loudly at the provider boundary rather
  than be reinterpreted by `extractJson`.

If you genuinely need free-form text, keep the default `parseResponse()` path
— there is nothing to opt in to.

## Hook reference

```php
use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Contracts\Schema;

/**
 * Return a Prism Schema to enable structured-output mode.
 * Default null keeps the legacy text + extractJson() path.
 */
protected function getJsonSchema(): ?Schema
{
    return null; // default
}

/**
 * Map the decoded structured payload into TResponse.
 * Default falls back to parseResponse(json_encode($data)) for legacy compat.
 *
 * @param  array<string, mixed>  $data
 * @return TResponse
 */
protected function parseStructured(array $data): mixed
{
    return $this->parseResponse(json_encode($data, JSON_THROW_ON_ERROR));
}
```

When `getJsonSchema()` returns a `Schema`, `executeSync()` / `execute()`
dispatches through `Prism::structured()` and feeds the decoded array straight
into `parseStructured()`. When it returns `null`, the legacy text path runs
unchanged.

## Minimal example

```php
use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\{ObjectSchema, StringSchema, NumberSchema, BooleanSchema};

final readonly class GreetingResponse
{
    public function __construct(
        public string $message,
        public float $tone,
        public bool $polite,
    ) {}
}

/** @extends Prompt<GreetingResponse> */
final class GreetingPrompt extends Prompt
{
    public function __construct(public readonly string $userName)
    {
        parent::__construct();
    }

    protected function getJsonSchema(): ?Schema
    {
        return new ObjectSchema(
            name: 'greeting_response',
            description: 'A greeting reply',
            properties: [
                new StringSchema('message', 'the greeting text'),
                new NumberSchema(
                    name: 'tone',
                    description: 'tone score (0.0 = cold, 1.0 = warm)',
                    minimum: 0.0,
                    maximum: 1.0,
                ),
                new BooleanSchema('polite', 'true if polite register'),
            ],
            requiredFields: ['message', 'tone', 'polite'],
        );
    }

    /** @param array<string, mixed> $data */
    protected function parseStructured(array $data): GreetingResponse
    {
        return new GreetingResponse(
            message: (string) $data['message'],
            tone: (float) $data['tone'],
            polite: (bool) $data['polite'],
        );
    }

    // Optional. Reached only when Prompt::fake([TextResponseFake]) short-circuits
    // before the structured branch (i.e. tests). Production traffic uses parseStructured.
    protected function parseResponse(string $text): GreetingResponse
    {
        $data = $this->extractJson($text);
        return new GreetingResponse(
            message: (string) $data['message'],
            tone: (float) $data['tone'],
            polite: (bool) $data['polite'],
        );
    }
}

$result = (new GreetingPrompt('Alice'))->executeSync();
```

## YAML guidance

Once `getJsonSchema()` is in place, **remove any `# 出力形式 / # output format`
JSON example from the YAML system prompt**. The schema becomes the single
source of truth — duplicating field names in YAML re-introduces the very
drift hazard structured output is meant to prevent.

A defensive instruction block (see
[prompt-injection.md](prompt-injection.md)) is still recommended at the top
of `system_prompt` even when using structured output.

## Event contract

Both paths emit the same events with the same payload semantics:

| Field | Text path | Structured path |
|-------|-----------|-----------------|
| `executionId` | uuid | uuid |
| `promptClass` | subclass FQN | subclass FQN |
| `promptTemplate` | YAML basename | YAML basename |
| `provider` / `model` | resolved | resolved |
| `finishReason` | from response | from response |
| `stepCount` | `$result->steps->count()` | `$result->steps->count()` |
| `totalUsage` | `Usage` | `Usage` |
| `durationMs` | wall time | wall time |
| `requestId` | `$result->meta->id` | `$result->meta->id` |
| `response` | `Prism\Prism\Text\Response` | `Prism\Prism\Structured\Response` |
| `metadata` | `withMetadata()` payload | `withMetadata()` payload |
| `cost` | `CostCalculation` or null | `CostCalculation` or null |

`PromptExecutionCompleted::$response` is typed as
`TextResponse|StructuredResponse`. Both classes expose `->text`, `->steps`,
`->usage`, `->meta`, so listeners that only need text-level data work
identically across both paths. Listeners that touch
`StructuredResponse::$structured` must narrow with `instanceof`.

`PromptExecutionFailed` is unchanged: any provider-side schema violation
surfaces as a `Throwable` and triggers the failure event.

## Testing

Two complementary fakes work with structured prompts:

### `Prism::fake([StructuredResponseFake])` — full provider path

Goes through `Prism::structured()` exactly the way production does. Use this
when you want to assert on event payload, token counts, listener side effects,
or instanceof checks against the real `Prism\Prism\Structured\Response`.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Usage;

Prism::fake([
    StructuredResponseFake::make()
        ->withStructured(['message' => 'hi', 'tone' => 0.9, 'polite' => true])
        ->withUsage(new Usage(promptTokens: 10, completionTokens: 5))
        ->withFinishReason(FinishReason::Stop),
]);

$dto = (new GreetingPrompt('Alice'))->executeSync();
expect($dto->tone)->toBe(0.9);
```

### `Prompt::fake([TextResponseFake])` — Prompt-layer short-circuit

Stays in the package layer and decodes the fake's text as JSON before handing
it to `parseStructured()`. Fast and useful when you do not want to load Prism's
provider stack.

```php
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;

Prompt::fake([
    TextResponseFake::make()->withText('{"message":"hi","tone":0.9,"polite":true}'),
]);

$dto = (new GreetingPrompt('Alice'))->executeSync();
```

The decoder rejects non-object payloads with
`InvalidJsonResponseException`, so contract regressions stay loud:

| Fake text | Outcome |
|-----------|---------|
| `''` | `InvalidJsonResponseException("empty text")` |
| `'["a","b"]'` | `InvalidJsonResponseException("list array")` |
| `'"hello"'` | `InvalidJsonResponseException("non-object JSON value")` |
| `'42'` | `InvalidJsonResponseException("non-object JSON value")` |
| `'{"k":"v"}'` | passed to `parseStructured(['k' => 'v'])` |

## Migration from `extractJson()` (legacy)

For existing prompts that use `parseResponse()` + `extractJson()`:

1. Add a `getJsonSchema()` mirroring the YAML's intended shape (with proper
   value constraints — `minimum`/`maximum`, `pattern`, `enum`).
2. Add `parseStructured(array $data)` that maps the array straight to the
   DTO without re-encoding to JSON.
3. Strip the JSON-output instruction from the YAML `system_prompt`. Keep the
   defensive instruction block.
4. Switch tests to `Prism::fake([StructuredResponseFake])` and tighten weak
   assertions (`->toBeBool()` → `->toBe(...)` etc.).
5. Mark the old `parseResponse()` / `extractDtoFromResponseString()` as
   `@deprecated` and remove it once the legacy text path is no longer
   reachable in your call sites (e.g. when you stop using
   `Prompt::fake([TextResponseFake])` for that prompt).

The legacy path keeps working unchanged for prompts that opt out — the
upgrade is per-Prompt.
