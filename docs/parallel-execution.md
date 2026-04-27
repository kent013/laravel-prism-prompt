# Parallel Execution with Prompt Caching

For batch workloads where several prompts share most of their context
(6-axis heuristic evaluation, per-page SEO passes, multi-rubric grading),
sequential `executeSync()` calls reissue the entire prompt N times and
pay full input-token cost for each call.

`PromptPool::executeWithWarmup()` solves this by:

1. **Warmup** — sending the first prompt alone so its cache-flagged shared
   section is written to the Anthropic prompt cache.
2. **Parallel** — fanning out the remaining prompts via `Http::pool`.
   Each call reads the cached section instead of re-uploading it.

> **Anthropic only.** `PromptPool` uses the Anthropic Messages API
> directly. Non-Anthropic providers should keep using `Prompt::executeSync()`.

## Declare cacheable sections in YAML

Split the system prompt into named `sections`, with the heaviest, most
stable text under a name you'll mark cacheable:

```yaml
# resources/prompts/heuristic/useful.yaml
name: heuristic-useful
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 8192

system_prompt: |
  You are a UX auditor. Evaluate the UX Honeycomb "Useful" axis.

sections:
  shared: |
    Page context: {{ $pageName }} ({{ $pageUrl }})
    Site: {{ $siteName }}
    (... 4-8 KB of stable instructions, rubrics, examples ...)
  axis: |
    Focus axis: useful

prompt: |
  Return a JSON report: ...
```

`shared` is byte-stable across the whole batch; `axis` differs per call.

## Run the pool

```php
use Kent013\PrismPrompt\PromptPool;
use Kent013\PrismPrompt\Values\CacheType;

$prompts = collect(['useful', 'usable', 'findable'])
    ->map(fn (string $axis) => HeuristicAxisPrompt::load("heuristic/{$axis}", $shared)
        ->withCacheBreakpoints(['shared' => CacheType::Ephemeral]))
    ->all();

$results = PromptPool::executeWithWarmup($prompts, concurrency: 5);
```

The builder is shared between the warmup single-shot and each parallel
call, so the request bodies are byte-identical up to the per-prompt
differences. Cache-key hits register only when the shared section is
byte-stable.

## Knobs

| Argument | Default | Description |
|----------|---------|-------------|
| `$prompts` | required | Array of fully-built `Prompt` instances |
| `$concurrency` | `config('prism-prompt.pool.concurrency')` (env `PRISM_PROMPT_POOL_CONCURRENCY`) | Max in-flight HTTP requests |
| `$builder` | `null` | Pre-configured `MessagesRequestBuilder` (test double, tenant-specific key, etc.) |

## Failure handling

Any prompt failure throws `PoolExecutionException`. Use `getPromptIndex()`
to map back to your domain:

```php
try {
    $results = PromptPool::executeWithWarmup($prompts);
} catch (PoolExecutionException $e) {
    Log::error('axis_failed', [
        'axis' => $axes[$e->getPromptIndex()],
        'previous' => $e->getPrevious(),
    ]);
    throw $e;
}
```

The underlying provider exception is attached as `$previous`.

## Internal hooks (`@internal`)

`Prompt::renderUserPromptForPool()` and `parseResponseForPool()` are
`public` but marked `@internal` — they exist so `PromptPool` and direct-HTTP
request builders can bridge to the subclass's protected `render()` /
`parseResponse()` without reflection. **They are not covered by SemVer
BC promises**; end-user code should continue using `executeSync()` /
`execute()`.

See [`examples/09-prompt-pool-parallel.php`](../examples/09-prompt-pool-parallel.php)
for a runnable rubric-grading example.
