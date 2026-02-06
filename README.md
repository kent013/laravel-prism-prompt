# Laravel Prism Prompt

Laravel Mailable-like API for LLM prompts with [Prism](https://github.com/echolabsdev/prism).

## Installation

```bash
composer require kent013/laravel-prism-prompt
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=prism-prompt-config
```

### Settings Priority

Settings are resolved in the following priority (high to low):

1. Class property
2. YAML template
3. Config default

## Usage

### Quick Start with `load()`

Just write a YAML template and use `Prompt::load()` — no PHP class needed:

```yaml
# resources/prompts/greeting.yaml
name: greeting
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 1024
temperature: 0.7

prompt: |
  Say hello to {{ $userName }}.
```

```php
use Kent013\PrismPrompt\Prompt;

$result = Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();
// Returns raw text string
```

`load()` resolves YAML from `{config('prism-prompt.prompts_path')}/{name}.yaml`.

### Subclass for Custom Response Parsing

When you need DTO mapping or custom logic, create a subclass:

```php
use Kent013\PrismPrompt\Prompt;

class GreetingPrompt extends Prompt
{
    public function __construct(
        public readonly string $userName,
    ) {
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

### YAML Template Resolution

YAML template is resolved in the following priority:

1. **`$promptName` property** — relative path from `prompts_path`
2. **Naming convention** — derived from class name (`GreetingPrompt` → `greeting.yaml`)

```php
// 1. $promptName: resources/prompts/standard/greeting.yaml
class GreetingPrompt extends Prompt
{
    protected string $promptName = 'standard/greeting';
    // ...
}

// 2. Naming convention: resources/prompts/greeting.yaml
class GreetingPrompt extends Prompt
{
    // No $promptName needed — auto-derived from class name
    // ...
}
```

Use `$promptsDirectory` to group prompts in a subdirectory:

```php
// resources/prompts/training/hint_generation.yaml
class HintGenerationPrompt extends Prompt
{
    protected string $promptsDirectory = 'training';
    // Naming convention: hint_generation.yaml
    // ...
}
```

You can still override `getTemplatePath()` for full path control.

## Runtime API Key Configuration

You can provide a custom API key at runtime using fluent methods:

```php
// Set custom API key
$result = (new GreetingPrompt('Alice'))
    ->withApiKey('user-provided-api-key')
    ->executeSync();

// Or use withProviderConfig for more options
$result = (new GreetingPrompt('Alice'))
    ->withProviderConfig([
        'api_key' => 'custom-api-key',
        'url' => 'https://custom-endpoint.example.com',
    ])
    ->executeSync();
```

**Note:** Do not reuse Prompt instances after calling these methods. Use one instance per request.

## Embedding

`EmbeddingPrompt` provides embedding generation via `Prism::embeddings()`.

### Quick Start with `load()`

```yaml
# resources/prompts/document-embedding.yaml
provider: openai
model: text-embedding-3-small
```

```php
use Kent013\PrismPrompt\EmbeddingPrompt;

$embedding = EmbeddingPrompt::load('document-embedding')
    ->withApiKey($userApiKey)
    ->executeSync('Text to embed');
// Returns array<int, float>
```

### Testing

```php
use Kent013\PrismPrompt\EmbeddingPrompt;
use Kent013\PrismPrompt\Testing\EmbeddingResponseFake;

$fake = EmbeddingPrompt::fake([
    EmbeddingResponseFake::make()->withEmbedding([0.1, 0.2, 0.3]),
]);

$result = EmbeddingPrompt::load('document-embedding')->executeSync('test');

$fake->assertCallCount(1);
$fake->assertTextContains('test');
$fake->assertProvider('openai');

EmbeddingPrompt::stopFaking();
```

## Testing with Fake

Similar to `Prism::fake()`, you can mock prompt executions in tests:

```php
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;

// Set up fake responses
$fake = Prompt::fake([
    TextResponseFake::make()->withText('{"message": "Hello!", "tone": "friendly"}'),
    TextResponseFake::make()->withText('{"message": "Goodbye!", "tone": "warm"}'),
]);

// Execute prompts - they will return fake responses in sequence
$result1 = (new GreetingPrompt('Alice'))->executeSync();
$result2 = (new GreetingPrompt('Bob'))->executeSync();

// Make assertions
$fake->assertCallCount(2);
$fake->assertPromptContains('Alice');
$fake->assertProvider('anthropic');
$fake->assertModel('claude-sonnet-4-5-20250929');

// Stop faking when done
Prompt::stopFaking();
```

### Available Assertions

| Method | Description |
|--------|-------------|
| `assertCallCount(int $count)` | Assert number of prompt executions |
| `assertPrompt(string $prompt)` | Assert exact prompt text was sent |
| `assertPromptContains(string $text)` | Assert prompt contains specific text |
| `assertPromptClass(string $class)` | Assert specific prompt class was used |
| `assertProvider(string $provider)` | Assert provider was used |
| `assertModel(string $model)` | Assert model was used |
| `assertRequest(Closure $fn)` | Custom assertion with recorded requests |

### TextResponseFake Builder

```php
TextResponseFake::make()
    ->withText('response text')
    ->withUsage(100, 50);  // promptTokens, completionTokens
```

## Debug Logging

Enable performance logging for debugging LLM calls:

```env
PRISM_PROMPT_DEBUG=true
PRISM_PROMPT_LOG_CHANNEL=prism-prompt
PRISM_PROMPT_SAVE_FILES=true
```

When enabled, logs include:
- Execution ID
- Prompt class
- Provider and model
- Duration (ms)
- Token usage (prompt/completion/total)

When `save_files` is enabled, debug files are saved to `storage/prism-prompt-debug/{date}/{execution-id}/`:
- `prompt.txt` - The rendered prompt
- `response.txt` - The LLM response
- `metadata.json` - Execution metadata

### Custom Logger

You can provide a custom logger by extending `Prompt` and overriding `getPerformanceLogger()`:

```php
use Kent013\PrismPrompt\Contracts\PerformanceLoggerInterface;

class MyPrompt extends Prompt
{
    protected function getPerformanceLogger(): ?PerformanceLoggerInterface
    {
        return app(MyCustomLogger::class);
    }
}
```

## Response Parsing

### JSON Response

```php
protected function parseResponse(string $text): SomeDto
{
    $data = $this->extractJson($text);
    return new SomeDto($data);
}
```

### Plain Text Response

```php
protected function parseResponse(string $text): string
{
    return trim($text);
}
```

## Traits

### ValidatesPromptVariables

For validating required variables:

```php
use Kent013\PrismPrompt\Traits\ValidatesPromptVariables;

class MyService
{
    use ValidatesPromptVariables;

    public function process(PromptTemplate $template, array $variables): void
    {
        $this->validateVariables($variables, $template);
    }
}
```

## Configuration Reference

| Key | Default | Description |
|-----|---------|-------------|
| `default_provider` | `anthropic` | Default LLM provider for text generation |
| `default_model` | `claude-sonnet-4-5-20250929` | Default model for text generation |
| `default_max_tokens` | `4096` | Maximum tokens in LLM response |
| `default_temperature` | `0.7` | Response randomness (0.0 - 1.0) |
| `default_embedding_provider` | `openai` | Default provider for embeddings (separate since not all providers support embeddings) |
| `default_embedding_model` | `text-embedding-3-small` | Default model for embeddings |
| `prompts_path` | `resource_path('prompts')` | Base path for YAML templates. Used by `load()`, `$promptName`, and naming convention |
| `cache.enabled` | `true` | Enable YAML template caching |
| `cache.ttl` | `3600` | Cache TTL in seconds |
| `cache.store` | `null` | Cache store (null = default) |
| `debug.enabled` | `false` | Enable performance logging |
| `debug.log_channel` | `prism-prompt` | Log channel for performance logs |
| `debug.save_files` | `false` | Save prompt/response/metadata files to disk |
| `debug.storage_path` | `storage_path('prism-prompt-debug')` | Directory for debug files |

## License

MIT
