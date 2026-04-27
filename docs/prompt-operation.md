# PromptOperation — Durable LLM Operations (opt-in, v0.12+)

`PromptOperation` is a job coordinator for LLM workflows that need to
survive interruptions: page reload, LLM error, process crash, two-tab
race. The base `Prompt` class is unchanged — opt in only when the
robustness is worth the schema cost.

## When to use it

Reach for `PromptOperation` when **all** of the following are true:

- The user-visible result is the side effect of one or more LLM calls
  (a generated message, a graded report) and you cannot afford to "redo
  it from scratch on retry".
- The operation can take long enough that the client may disconnect mid-run
  (browser reload, mobile background, long-poll timeout).
- Two requests for the same logical operation (same user + same trigger)
  must converge instead of double-charging the user.

If your LLM call is fire-and-forget or already idempotent at the
business-logic layer, plain `Prompt::executeSync()` is the right tool.

## What it gives you

| Capability | Why it matters |
|------------|----------------|
| Atomic claim of a `prompt_jobs` row + heartbeat + phase manifest | Single owner per logical operation; stale ownership detected by TTL |
| `(scope, operation_name, idempotency_key)` deduplication | Same input = same job; duplicate calls become followers |
| Phase-level checkpoints with `onCommit` callbacks | Re-running a partially-completed operation skips finished phases |
| Phase ↔ `llm_call_log` N:N attachment | Audit trace by `correlation_id` |
| Serialization groups | Prevent two unrelated operations on the same scope from clobbering each other |

## Schema

The package ships migrations for:

- `prompt_jobs` — one row per logical operation
- `prompt_job_attempts` — owner attempts (with heartbeat)
- `prompt_job_phases` — completed phase markers
- `prompt_job_phase_llm_calls` — phase ↔ `llm_call_logs` join
- `prompt_serialization_locks` — scope-level mutex

Publish and run them as part of your Laravel migrate flow.

## Lifecycle outline

```
claimOrFollow()
  ├─ OwnerClaim          → run phase 1 → phase 2 → ... → complete()
  ├─ SameOperationFollower → follow() polls until owner finishes
  ├─ BlockedBySerialization → waitForLockRelease() then retry claim
  ├─ AlreadyCompleted    → replay persisted state
  ├─ AlreadyFailed       → return error
  └─ AlreadyCancelled    → return cancelled
```

Each branch returns a typed `ClaimResult` so the caller (e.g. an HTTP
controller) can decide how to respond.

## Minimal owner path

```php
$claim = PromptOperation::for($progress, 'training.initial-message', 'fixed')
    ->withPhases(['generate-initial-message', 'analyze-progress'])
    ->withSerializationGroup("training-write:{$progress->id}")
    ->withHeartbeatTtl(90)
    ->claimOrFollow();

if ($claim instanceof OwnerClaim) {
    $handle = $claim->handle();

    $handle->phase('generate-initial-message', function (PromptJobPhase $phase) use ($progress): void {
        $metadata = $phase->metadata()
            ->subjectFromScope()
            ->correlationIdFromPhase()
            ->toArguments();

        $response = (new GenerateInitialMessagePrompt)
            ->withMetadata($metadata)
            ->executeSync();

        $phase->attachLlmCallByCorrelationId($metadata['correlation_id']);
        // ... persist side effect ...
    });

    $handle->complete();
}
```

See [`examples/07-prompt-operation.php`](../examples/07-prompt-operation.php)
for a fully-fleshed example with `onCommit` (draft → active promote),
`onSkipped` (resume re-load), follower polling, and a
`BlockedBySerialization` retry loop.

## `phase()` vs `streamingPhase()` (v0.14+)

`phase()` body returns a value; `streamingPhase()` body is a `Generator`
whose yielded values are forwarded to the caller. Use `streamingPhase()`
when the LLM call is part of an SSE pipeline you want to forward
mid-flight while still keeping the phase scope (lease, heartbeat, audit
trace) intact.

```php
yield from $handle->streamingPhase('send-message-pipeline', function ($phase) {
    yield from $pipeline->stream();   // SSE events forwarded to caller
});
$handle->complete();
```

Both APIs share the same commit transaction; pre-existing `phase()`
callers need no changes.

## Operating concerns

- **Heartbeat TTL** controls how aggressively a stale owner is reclaimed.
  Tune to your worst-case LLM latency × safety factor.
- **Serialization group** is a coarse mutex. Use it when several distinct
  operations target the same scope and must not interleave (e.g. send-message
  vs end-encounter on the same training session).
- **Phase manifest is fixed at claim time.** Adding phases requires bumping
  the operation name (or schema migration); the runtime won't accept an
  unknown phase mid-flight.
- The whole feature is **opt-in**. If you don't import `PromptOperation`,
  none of the new tables are touched.
