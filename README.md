# Laravel Prism Prompt

Laravel Mailable-like API for LLM prompts with [Prism](https://github.com/echolabsdev/prism).

Define your prompts as **YAML templates + PHP classes**. Move text and
model settings out of code, separate the system message from the user
message, parse responses into typed DTOs, and observe every call via
events with USD cost attached — the same way you'd compose a Mailable.

```yaml
# resources/prompts/greeting.yaml
provider: anthropic
model: claude-sonnet-4-5-20250929

system_prompt: |
  You are a friendly greeting assistant.
  Always respond in JSON with "message" and "tone" fields.

prompt: |
  Say hello to {{ $userName }}.
```

```php
use Kent013\PrismPrompt\Prompt;

$result = Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();
```

That's the whole loop. Everything else in this package is a layer on top:
DTO mapping, multi-provider fallback, prompt-injection defence, parallel
execution with prompt caching, observability, durable operations.

## Installation

```bash
composer require kent013/laravel-prism-prompt
```

Publish config:

```bash
# Provider defaults, cache, debug
php artisan vendor:publish --tag=prism-prompt-config

# Pricing table (per-model USD rates)
php artisan vendor:publish --tag=prism-prompt-pricing
```

## At a glance

| Need | Approach | Doc | Example |
|------|----------|-----|---------|
| One-shot prompt with a YAML file | `Prompt::load('name', $vars)->executeSync()` | [yaml-template](docs/yaml-template.md) | [01](examples/01-basic-system-prompt.php) |
| Typed DTO response (legacy / text JSON) | Subclass + `parseResponse()` + `extractJson()` | [yaml-template](docs/yaml-template.md) | [02](examples/02-json-dto-response.php) |
| **Schema-enforced DTO response** (recommended) | Subclass + `getJsonSchema()` + `parseStructured()` | [structured-output](docs/structured-output.md) | [13](examples/13-structured-output.php) |
| Send chat history natively | Override `buildConversationMessages()` | [yaml-template](docs/yaml-template.md) | [03](examples/03-conversation-history.php) |
| Defend against prompt injection | `UserInput::from()` + `DefensiveInstructions` | [prompt-injection](docs/prompt-injection.md) | [06](examples/06-user-input-defense.php) |
| Multi-provider fallback (BYOK) | YAML `models[]` + `withApiKeys()` | [providers](docs/providers.md) | [08](examples/08-multi-provider-fallback.php) |
| Parallel batch with shared cache | `PromptPool::executeWithWarmup()` | [parallel-execution](docs/parallel-execution.md) | [09](examples/09-prompt-pool-parallel.php) |
| Prompt caching on a single `executeSync()` | `sections:` + `withCacheBreakpoints()` | [parallel-execution](docs/parallel-execution.md#caching-in-single-execution-executesync) | — |
| Embeddings for RAG | `EmbeddingPrompt` | [embedding](docs/embedding.md) | [10](examples/10-embedding-rag.php) |
| Chat assistant w/ history + defence | Combine `UserInput` + history override + DTO | [prompt-injection](docs/prompt-injection.md) | [11](examples/11-chatbot-with-defense.php) |
| Multi-prompt pipeline (NPC reply → eval → hint) | Chain Prompt subclasses | [yaml-template](docs/yaml-template.md) | [12](examples/12-bundle-pipeline.php) |
| Cost / usage / audit trail | Listen to `PromptExecutionCompleted` + `withMetadata()` | [events-and-cost](docs/events-and-cost.md) | [05](examples/05-events-and-cost.php) |
| Cost / usage for pooled calls | `PromptPool::executeWithWarmup()` + `$prompt->getPoolCallMeta()` | [parallel-execution](docs/parallel-execution.md) | [09](examples/09-prompt-pool-parallel.php) |
| Durable, resumable LLM operation | `PromptOperation::for()->claimOrFollow()` | [prompt-operation](docs/prompt-operation.md) | [07](examples/07-prompt-operation.php) |
| Test mocked LLM calls | `Prompt::fake()` + assertions | [testing](docs/testing.md) | [04](examples/04-testing.php) |
| Listener-based debug logs | `PRISM_PROMPT_DEBUG=true` | [debug-logging](docs/debug-logging.md) | — |

## Settings priority

Settings resolve highest-wins:

1. Class property (e.g. `protected ?float $temperature = 0.5`)
2. YAML field
3. Config default (`config('prism-prompt.default_*')`)

## Subclassing

Use `Prompt::load()` for the simplest case. When you need typed DTOs,
custom message construction, or fluent variables in `__construct`,
subclass:

```php
use Kent013\PrismPrompt\Prompt;

/** @extends Prompt<GreetingResponse> */
class GreetingPrompt extends Prompt
{
    public function __construct(public readonly string $userName)
    {
        parent::__construct();
    }

    protected function parseResponse(string $text): GreetingResponse
    {
        $data = $this->extractJson($text);
        return new GreetingResponse($data['message'], $data['tone']);
    }
}

$result = (new GreetingPrompt('Alice'))->executeSync();
```

### Structured output (recommended for new code, since v0.15.0)

For prompts whose output must conform to a fixed shape, declare a Prism schema
via `getJsonSchema()`. The base will route the call through
`Prism::structured()` and pass the decoded array straight to
`parseStructured()` — no `extractJson()` regex, no YAML JSON example to drift
out of sync.

```php
use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\{ObjectSchema, StringSchema, NumberSchema};

/** @extends Prompt<GreetingResponse> */
class GreetingPrompt extends Prompt
{
    public function __construct(public readonly string $userName) { parent::__construct(); }

    protected function getJsonSchema(): ?Schema
    {
        return new ObjectSchema(
            name: 'greeting_response',
            description: 'A greeting reply',
            properties: [
                new StringSchema('message', 'the greeting text'),
                new NumberSchema(
                    name: 'tone',
                    description: 'tone score',
                    minimum: 0.0,
                    maximum: 1.0,
                ),
            ],
            requiredFields: ['message', 'tone'],
        );
    }

    /** @param array<string, mixed> $data */
    protected function parseStructured(array $data): GreetingResponse
    {
        return new GreetingResponse($data['message'], (float) $data['tone']);
    }

    // Keep parseResponse as a transitional fallback for Prompt::fake([TextResponseFake])
    // tests, or remove once the legacy path is gone.
    protected function parseResponse(string $text): GreetingResponse
    {
        $data = $this->extractJson($text);
        return new GreetingResponse($data['message'], (float) $data['tone']);
    }
}
```

`getJsonSchema()` returning `null` (the default) keeps the legacy
`Prism::text()` + `extractJson()` path, so existing subclasses continue to work
unchanged. See [docs/structured-output.md](docs/structured-output.md) for the
full contract (event payload, fakes, error handling).

YAML lookup (in priority order):

1. `$promptName` property — relative path from `prompts_path`
2. Naming convention — `GreetingPrompt` → `greeting.yaml`
3. `$promptsDirectory` — group prompts under a subdirectory

`getTemplatePath()` is overridable for full control.

## Configuration reference

### `config/prism-prompt.php`

| Key | Default | Description |
|-----|---------|-------------|
| `default_provider` | `anthropic` | Default LLM provider for text generation |
| `default_model` | `claude-sonnet-4-5-20250929` | Default model for text generation |
| `default_max_tokens` | `4096` | Maximum tokens in LLM response |
| `default_temperature` | `0.7` | Response randomness (0.0 - 1.0) |
| `default_embedding_provider` | `openai` | Default provider for embeddings |
| `default_embedding_model` | `text-embedding-3-small` | Default model for embeddings |
| `prompts_path` | `resource_path('prompts')` | Base path for YAML templates |
| `cache.enabled` | `true` | Enable YAML template caching |
| `cache.ttl` | `3600` | Cache TTL in seconds |
| `cache.store` | `null` | Cache store (null = default) |
| `pool.concurrency` | `5` | Default `PromptPool` concurrency (env `PRISM_PROMPT_POOL_CONCURRENCY`) |
| `debug.enabled` | `false` | Auto-register `PerformanceLogListener` |
| `debug.log_channel` | `prism-prompt` | Log channel for debug output |
| `debug.save_files` | `false` | Auto-register `PerformanceDebugFileListener` |
| `debug.storage_path` | `storage_path('prism-prompt-debug')` | Directory for debug files |

### `config/prism-prompt-pricing.php`

| Key | Default | Description |
|-----|---------|-------------|
| `pricing_source` | `defaults_shipped` | Label embedded in every `PricingSnapshot`. Override via `PRISM_PROMPT_PRICING_SOURCE` |
| `unknown_model_behavior` | `zero` | `zero` returns a zero-cost snapshot; `throw` raises `InvalidArgumentException` |
| `models.{provider}.{model}` | Anthropic Claude set | Per-million-token rates: `input`, `output`, optional `cache_write` / `cache_read` |

## Documentation

In-depth topic guides live under [`docs/`](docs/):

- [yaml-template.md](docs/yaml-template.md) — YAML schema, message structure, override hierarchy
- [structured-output.md](docs/structured-output.md) — `getJsonSchema()` / `parseStructured()` for Prism::structured() (v0.15.0+)
- [providers.md](docs/providers.md) — multi-provider fallback, runtime API keys
- [prompt-injection.md](docs/prompt-injection.md) — `UserInput`, `DefensiveInstructions`
- [parallel-execution.md](docs/parallel-execution.md) — `PromptPool` with prompt caching
- [events-and-cost.md](docs/events-and-cost.md) — events, `withMetadata`, USD cost
- [testing.md](docs/testing.md) — `Prompt::fake()` + assertions
- [debug-logging.md](docs/debug-logging.md) — listener-based debug
- [embedding.md](docs/embedding.md) — `EmbeddingPrompt`
- [prompt-operation.md](docs/prompt-operation.md) — durable, resumable operations

Runnable examples are under [`examples/`](examples/):

| File | Topic |
|------|-------|
| [01-basic-system-prompt.php](examples/01-basic-system-prompt.php) | Quickest path with `Prompt::load()` |
| [02-json-dto-response.php](examples/02-json-dto-response.php) | Subclass + `extractJson()` → DTO (legacy text path) |
| [13-structured-output.php](examples/13-structured-output.php) | Subclass + `getJsonSchema()` → DTO (Prism::structured, v0.15.0+) |
| [03-conversation-history.php](examples/03-conversation-history.php) | Native chat history via `buildConversationMessages()` |
| [04-testing.php](examples/04-testing.php) | Message-aware `Prompt::fake()` assertions |
| [05-events-and-cost.php](examples/05-events-and-cost.php) | `PromptExecutionCompleted` listener + cost log |
| [06-user-input-defense.php](examples/06-user-input-defense.php) | `UserInput` + `DefensiveInstructions` |
| [07-prompt-operation.php](examples/07-prompt-operation.php) | `PromptOperation` durable workflow |
| [08-multi-provider-fallback.php](examples/08-multi-provider-fallback.php) | BYOK with auto provider selection |
| [09-prompt-pool-parallel.php](examples/09-prompt-pool-parallel.php) | 5-axis rubric grading via `PromptPool` |
| [10-embedding-rag.php](examples/10-embedding-rag.php) | RAG document indexing with `EmbeddingPrompt` |
| [11-chatbot-with-defense.php](examples/11-chatbot-with-defense.php) | Chatbot combining history + `UserInput` + DTO |
| [12-bundle-pipeline.php](examples/12-bundle-pipeline.php) | Multi-prompt pipeline (NPC reply → eval → hint) |

## License

MIT
