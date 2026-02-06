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

### 1. Create a YAML Template

```yaml
# resources/prompts/greeting.yaml
name: greeting
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 1024
temperature: 0.7

prompt: |
  Say hello to {{ $userName }}.
  Return JSON: {"message": "...", "tone": "..."}
```

### 2. Create a Prompt Class

```php
use Kent013\PrismPrompt\Prompt;

class GreetingPrompt extends Prompt
{
    public function __construct(
        public readonly string $userName,
    ) {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return resource_path('prompts/greeting.yaml');
    }

    protected function parseResponse(string $text): GreetingResponse
    {
        $data = $this->extractJson($text);
        return new GreetingResponse($data['message'], $data['tone']);
    }
}
```

### 3. Execute

```php
// Synchronous
$result = (new GreetingPrompt('Alice'))->executeSync();

// Asynchronous (requires react/promise)
$promise = (new GreetingPrompt('Alice'))->execute();
```

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

`EmbeddingPrompt` provides a parallel base class for embedding generation via `Prism::embeddings()`.

### 1. Create an Embedding Class

```php
use Kent013\PrismPrompt\EmbeddingPrompt;

class DocumentEmbedding extends EmbeddingPrompt
{
    protected function getTemplatePath(): string
    {
        return resource_path('prompts/document-embedding.yaml');
    }
}
```

```yaml
# resources/prompts/document-embedding.yaml
provider: openai
model: text-embedding-3-small
```

### 2. Execute

```php
$embedding = (new DocumentEmbedding())
    ->withApiKey($userApiKey)
    ->executeSync('Text to embed');
// Returns array<int, float>
```

### 3. Testing

```php
use Kent013\PrismPrompt\Testing\EmbeddingResponseFake;

$fake = DocumentEmbedding::fake([
    EmbeddingResponseFake::make()->withEmbedding([0.1, 0.2, 0.3]),
]);

$result = (new DocumentEmbedding())->executeSync('test');

$fake->assertCallCount(1);
$fake->assertTextContains('test');
$fake->assertProvider('openai');

DocumentEmbedding::stopFaking();
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

### LoadsPromptTemplate

For loading templates with fallback resolution:

```php
use Kent013\PrismPrompt\Traits\LoadsPromptTemplate;

class MyService
{
    use LoadsPromptTemplate;

    public function process(array $prompts): void
    {
        // Tries: prompts/{$prompts['greeting']}.yaml, then prompts/common/greeting.yaml
        $template = $this->loadTemplate($prompts, 'greeting');
    }
}
```

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

```php
// config/prism-prompt.php
return [
    'default_provider' => env('PRISM_PROVIDER', 'anthropic'),
    'default_model' => env('PRISM_MODEL', 'claude-sonnet-4-5-20250929'),
    'default_max_tokens' => 4096,
    'default_temperature' => 0.7,

    'default_embedding_provider' => env('PRISM_EMBEDDING_PROVIDER', 'openai'),
    'default_embedding_model' => env('PRISM_EMBEDDING_MODEL', 'text-embedding-3-small'),

    'prompts_path' => resource_path('prompts'),

    'cache' => [
        'enabled' => env('PRISM_PROMPT_CACHE', true),
        'ttl' => 3600,
        'store' => null,
    ],

    'debug' => [
        'enabled' => env('PRISM_PROMPT_DEBUG', false),
        'log_channel' => env('PRISM_PROMPT_LOG_CHANNEL', 'prism-prompt'),
        'save_files' => env('PRISM_PROMPT_SAVE_FILES', false),
        'storage_path' => storage_path('prism-prompt-debug'),
    ],
];
```

## License

MIT
