# Embedding

`EmbeddingPrompt` provides vector generation via Prism's `embeddings()`.
Same `load()`-then-execute ergonomics as `Prompt`, but configured for
embedding models and returning `array<int, float>`.

## Quick start

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

There is no `system_prompt` or `prompt` template — embedding is a
single-input operation, so you pass the text directly to `executeSync()`.

## Provider config

```php
EmbeddingPrompt::load('document-embedding')
    ->withProviderConfig([
        'api_key' => $apiKey,
        'url'     => 'https://custom-endpoint.example.com',
    ])
    ->executeSync($text);
```

## Defaults

`config/prism-prompt.php` defines separate defaults for embeddings, since
not all providers support both text and embeddings:

| Key | Default |
|-----|---------|
| `default_embedding_provider` | `openai` |
| `default_embedding_model` | `text-embedding-3-small` |

## Testing

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

## Limitation: not on the event bus yet

`EmbeddingPrompt` has not been migrated to `PromptExecutionCompleted` /
`PromptExecutionFailed` events. Cost calculation and the event-based
listener pipeline therefore do not apply. Use the legacy
`PerformanceLogger` via `debug.enabled` for visibility, and track cost
out-of-band until the migration lands.

See [`examples/10-embedding-rag.php`](../examples/10-embedding-rag.php)
for a small RAG indexing flow.
