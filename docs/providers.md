# Providers, API Keys & Fallback

`Prompt` can be pointed at any Prism-supported provider, given a runtime
API key, or configured to fall back through a list of models depending on
which API keys the caller has.

## Runtime API key

The simplest case: override the API key for a single call.

```php
$result = (new GreetingPrompt('Alice'))
    ->withApiKey('user-provided-api-key')
    ->executeSync();
```

For more knobs (custom URL, organization id, etc.) use `withProviderConfig()`:

```php
$result = (new GreetingPrompt('Alice'))
    ->withProviderConfig([
        'api_key' => 'custom-api-key',
        'url' => 'https://custom-endpoint.example.com',
    ])
    ->executeSync();
```

> ⚠️ Do not reuse a `Prompt` instance after calling these methods. Use one
> instance per request.

## Multiple providers with automatic fallback

Declare a priority-ordered list in YAML:

```yaml
provider: anthropic                # system default (no user keys)
model: claude-sonnet-4-5-20250929

models:
  - { provider: anthropic, model: claude-sonnet-4-5-20250929, priority: 1 }
  - { provider: openai,   model: gpt-4o,                      priority: 2 }
  - { provider: google,   model: gemini-2.0-flash-exp,        priority: 3 }
```

`models` field reference:

| Field | Required | Description |
|-------|----------|-------------|
| `provider` | Yes | `anthropic`, `openai`, `google`, … |
| `model` | Yes | Provider-specific model id |
| `priority` | No | Lower = higher priority. Default `999` |

Pass user-supplied keys at runtime; the highest-priority provider that has
a key wins.

```php
// Method 1: simple keys
$result = Prompt::load('greeting', ['userName' => 'Alice'])
    ->withApiKeys([
        'anthropic' => 'sk-ant-...',
        'openai'    => 'sk-...',
    ])
    ->executeSync();

// Method 2: keys + provider config
$result = Prompt::load('greeting', ['userName' => 'Alice'])
    ->withProviderConfigs([
        'anthropic' => ['api_key' => 'sk-ant-...'],
        'openai'    => ['api_key' => 'sk-...', 'url' => 'https://custom.example.com'],
    ])
    ->executeSync();
```

If only `openai` is provided in the example above, the resolver skips
`anthropic` (priority 1) and picks `openai` (priority 2).

## Resolution rules

| Caller | Resolution |
|--------|------------|
| No keys provided | YAML `provider` / `model` (system default) |
| `withApiKey()` (single) | YAML `provider` / `model` with the supplied key |
| `withApiKeys()` / `withProviderConfigs()` (multi) | Highest-priority `models[]` entry whose provider is in the supplied keys |

## Use cases

**Bring-your-own-key (BYOK)** — Your users plug in their own keys and you
don't know which provider they prefer.

```php
// User has only OpenAI; YAML prefers Anthropic
Prompt::load('greeting', ['userName' => $userName])
    ->withApiKeys(['openai' => $userApiKey])
    ->executeSync();
// Falls back to OpenAI automatically.
```

**Provider redundancy** — Configure secondary models so a vendor outage
on the primary doesn't drop the call.

## Backward compatibility

YAML files without a `models` field continue to work. The feature is
entirely opt-in.

See [`examples/08-multi-provider-fallback.php`](../examples/08-multi-provider-fallback.php)
for runnable code.
