# Debug Logging

Two opt-in listeners persist execution detail without you having to write
any subscription code yourself.

## Enabling

```env
PRISM_PROMPT_DEBUG=true
PRISM_PROMPT_LOG_CHANNEL=prism-prompt
PRISM_PROMPT_SAVE_FILES=true
```

When `debug.enabled` is true, the service provider auto-registers
`PerformanceLogListener` on `PromptExecutionCompleted`. It emits a JSON
log line per call containing:

- execution id
- prompt class / template
- provider / model
- duration
- token counts
- step count

When `debug.save_files` is also true, `PerformanceDebugFileListener`
additionally writes files to
`storage/prism-prompt-debug/{date}/{execution-id}/`:

| File | Content |
|------|---------|
| `response.txt` | Raw LLM response text |
| `metadata.json` | Structured metadata (same fields as the log line) |

## Replacing the listeners

Both listeners are plain classes you can swap out.

```php
Event::forget(PromptExecutionCompleted::class);
Event::listen(PromptExecutionCompleted::class, MyCustomLogger::class);
```

The package never forces you to use the bundled listeners — they are
just one ready-made implementation of the [event API](events-and-cost.md).

## Embedding caveat

`EmbeddingPrompt` has not been migrated to the event-driven architecture
yet. It still uses the internal `PerformanceLogger` (and the
`PerformanceLoggerInterface` contract) when `debug.enabled` is on. This
is a legacy surface that will move to events in a future release.
