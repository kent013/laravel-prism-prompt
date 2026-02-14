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

system_prompt: |
  You are a friendly greeting assistant.
  Always respond in JSON format with "message" and "tone" fields.

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

### System Prompt and Message Structure

YAML templates support a `system_prompt` field that is sent as a separate system-role message to the LLM, distinct from the user-role `prompt`. This enables proper role separation for better instruction following.

```yaml
system_prompt: |
  You are {{ $npcName }}, a {{ $npcRole }}.
  Always respond in character.

prompt: |
  {{ $conversationHistory }}

  User: {{ $userMessage }}
```

Both `system_prompt` and `prompt` support Blade syntax with the same template variables.

When sent to the LLM via Prism's `withMessages()`, this becomes:

| Role | Content |
|------|---------|
| `SystemMessage` | Rendered `system_prompt` |
| `UserMessage` | Rendered `prompt` |

If `system_prompt` is omitted, only a `UserMessage` is sent (backward compatible).

#### Customizing Message Structure

Override these methods in your `Prompt` subclass for fine-grained control:

```php
class MyPrompt extends Prompt
{
    // Full control over all messages
    protected function buildMessages(): array
    {
        return [
            new SystemMessage('You are a helpful assistant.'),
            new UserMessage($previousQuestion),
            new AssistantMessage($previousAnswer),
            new UserMessage($this->render()),
        ];
    }

    // Or override just the system message
    protected function buildSystemMessage(): ?SystemMessage
    {
        return new SystemMessage('Custom system prompt');
    }

    // Or override just the conversation messages
    protected function buildConversationMessages(): array
    {
        return [
            new UserMessage($this->previousQuestion),
            new AssistantMessage($this->previousAnswer),
            new UserMessage($this->render()),
        ];
    }
}
```

**Override hierarchy:**

| Method | Scope | Default behavior |
|--------|-------|------------------|
| `buildMessages()` | Full message array | Calls `buildSystemMessage()` + `buildConversationMessages()` |
| `buildSystemMessage()` | System message only | Renders `system_prompt` from YAML |
| `buildConversationMessages()` | User/assistant messages | Returns `[new UserMessage($this->render())]` |

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

### Multiple Provider Fallback

You can configure multiple models with automatic selection based on available API keys.

#### YAML Configuration

Add `models` field to specify available models in priority order:

```yaml
name: greeting
# System default (used when no user API keys provided)
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 1024
temperature: 0.7

# Available models (used when multiple API keys provided via withApiKeys)
models:
  - provider: anthropic
    model: claude-sonnet-4-5-20250929
    priority: 1
  - provider: openai
    model: gpt-4o
    priority: 2
  - provider: google
    model: gemini-2.0-flash-exp
    priority: 3

prompt: |
  Say hello to {{ $userName }}.
```

#### Runtime Usage

**System use (no user API keys):**
```php
// Uses provider/model from YAML
$result = Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();
```

**Single user API key:**
```php
// Uses provider/model from YAML with provided key
$result = Prompt::load('greeting', ['userName' => 'Alice'])
    ->withApiKey($userApiKey)
    ->executeSync();
```

**Multiple user API keys (automatic selection):**
```php
use Kent013\PrismPrompt\Prompt;

// Method 1: withApiKeys (simple)
$result = Prompt::load('greeting', ['userName' => 'Alice'])
    ->withApiKeys([
        'anthropic' => 'sk-ant-...',
        'openai' => 'sk-...',
        'google' => 'API_KEY...',
    ])
    ->executeSync();

// Method 2: withProviderConfigs (with additional options)
$result = Prompt::load('greeting', ['userName' => 'Alice'])
    ->withProviderConfigs([
        'anthropic' => ['api_key' => 'sk-ant-...'],
        'openai' => [
            'api_key' => 'sk-...',
            'url' => 'https://custom-openai-endpoint.com',
        ],
    ])
    ->executeSync();
```

When multiple API keys are provided, the package automatically selects the highest-priority model from the `models` list for which you have provided an API key. If `anthropic` key is provided, it will be used. If not, it will fallback to `openai`, and so on.

#### Use Cases

**User-provided API Keys**
When users provide their own API keys, you may not know which provider they prefer. By specifying `models`, the system will automatically select the best available option.

```php
// User has only OpenAI key, but prompt prefers Anthropic
$result = Prompt::load('greeting', ['userName' => $userName])
    ->withApiKeys([
        'openai' => $userApiKey,  // Only OpenAI key available
    ])
    ->executeSync();
// Automatically uses OpenAI since Anthropic key is not available
```

**Provider Redundancy**
If you want to ensure high availability, configure fallback models in case the primary provider is unavailable.

#### Backward Compatibility

Existing YAML files without `models` continue to work as before. The feature is entirely opt-in.

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
$fake->assertPromptContains('Alice');         // Searches all messages
$fake->assertUserMessageContains('Alice');     // User message only
$fake->assertHasSystemMessage();               // System message exists
$fake->assertSystemMessageContains('greeting'); // System message content
$fake->assertMessageCount(2);                  // system + user
$fake->assertProvider('anthropic');
$fake->assertModel('claude-sonnet-4-5-20250929');

// Stop faking when done
Prompt::stopFaking();
```

### Available Assertions

| Method | Description |
|--------|-------------|
| `assertCallCount(int $count)` | Assert number of prompt executions |
| `assertPromptContains(string $text)` | Assert any message contains specific text |
| `assertSystemMessageContains(string $text)` | Assert system message contains specific text |
| `assertUserMessageContains(string $text)` | Assert user message contains specific text |
| `assertHasSystemMessage()` | Assert a system message was sent |
| `assertMessageCount(int $count)` | Assert number of messages sent |
| `assertPrompt(string $prompt)` | Assert exact prompt text was sent |
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

## YAML Template Reference

### Basic Fields

| Field | Required | Description |
|-------|----------|-------------|
| `name` | No | Template name (informational) |
| `version` | No | Template version (informational) |
| `description` | No | Template description (informational) |
| `provider` | No | Default LLM provider (e.g., `anthropic`, `openai`, `google`) |
| `model` | No | Default model name |
| `max_tokens` | No | Maximum tokens in response |
| `temperature` | No | Response randomness (0.0 - 1.0) |
| `system_prompt` | No | Blade template for the system-role message (instructions, role definitions, constraints) |
| `prompt` | Yes | Blade template for the user-role message (dynamic data, task description) |

### Multiple Models Support

The `models` field allows automatic selection when multiple API keys are provided:

```yaml
# System default
provider: anthropic
model: claude-sonnet-4-5-20250929

# Available models (for withApiKeys)
models:
  - provider: anthropic          # Provider name (required)
    model: claude-sonnet-4-5     # Model name (required)
    priority: 1                  # Priority (lower = higher priority, optional, default: 999)
  - provider: openai
    model: gpt-4o
    priority: 2
```

**`models` fields:**

| Field | Required | Description |
|-------|----------|-------------|
| `provider` | Yes | Provider name (e.g., `anthropic`, `openai`) |
| `model` | Yes | Model identifier |
| `priority` | No | Selection priority (lower number = higher priority, default: 999) |

**Priority behavior:**
- Lower values have higher priority (e.g., `priority: 1` is selected before `priority: 2`)
- If not specified, defaults to `999`
- When multiple API keys are provided via `withApiKeys()`, the system selects the available model with the lowest priority value
- When no API keys are provided or only single key via `withApiKey()`, the system uses `provider`/`model` fields

### Meta Section

The `meta` section supports custom application metadata:

```yaml
meta:
  # Custom metadata for your application
  variables:
    runtime:
      - userName
      - npcName
```

### Complete Example

```yaml
name: generate_greeting
version: 1.0.0
description: Generate personalized greeting message

# System default settings
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 500
temperature: 0.8

# Available models (for withApiKeys)
models:
  - provider: anthropic
    model: claude-sonnet-4-5-20250929
    priority: 1
  - provider: openai
    model: gpt-4o
    priority: 2

# Custom application metadata
meta:
  variables:
    runtime:
      - userName
      - userRole
      - scenarioTitle

# System-role message (instructions, constraints)
system_prompt: |
  You are a professional greeter for {{ $scenarioTitle }}.
  Always respond in JSON format with "message" and "tone" fields.
  Keep the tone warm and professional.

# User-role message (dynamic data, task)
prompt: |
  Generate a greeting for {{ $userName }} ({{ $userRole }}).
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

## Examples

The [`examples/`](examples/) directory contains runnable samples for common use cases:

| File | Description |
|------|-------------|
| [01-basic-system-prompt.php](examples/01-basic-system-prompt.php) | `Prompt::load()` with `system_prompt` — simplest pattern, no PHP class needed |
| [02-json-dto-response.php](examples/02-json-dto-response.php) | Subclass with `extractJson()` → DTO mapping, JSON schema in `system_prompt` |
| [03-conversation-history.php](examples/03-conversation-history.php) | Override `buildConversationMessages()` to send chat history as native `UserMessage`/`AssistantMessage` |
| [04-testing.php](examples/04-testing.php) | Testing patterns with message-aware assertions (`assertSystemMessageContains`, `assertUserMessageContains`, etc.) |

## License

MIT
