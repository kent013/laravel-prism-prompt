# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.14.2] - 2026-04-28

### Added — lease semantic drift 検出 (`LeaseSemanticMismatchException`)

`PromptOperationBuilder::claimOrFollow()` で、既存 PromptJob と claim 時の
`serialization_group` / `heartbeat_ttl_seconds` が一致しない場合に
`LeaseSemanticMismatchException` を throw する。

#### 修正の背景

監査で「lease semantic drift を検出していない」と指摘された。例:
- 既存 owner が `heartbeat_ttl=180s` で動いているのに、新 claim が `heartbeat_ttl=90s` で
  follower 待機 → leader 健全でも 90s で stale 判定して takeover してしまう
- 既存 Job が `serialization_group="encounter-write:abc"` で row lock 取っているのに、
  新 claim が違う group を指定 → 並行 LLM 呼び出しが起きうる

これらは subtle で、phase manifest 整合性検査だけでは捕まえられない。

#### 動作

- INSERT 直後 (`serialization_group === null && heartbeat_ttl_seconds === 0`) は
  claim 値で upgrade される (既存挙動維持)
- 既存値と新 claim 値が異なる場合 `LeaseSemanticMismatchException` を throw

#### Migration

- 既存 deployment で claim パラメータを変更する場合は `operationVersion` を bump する
- 既存 Job の retention を待ってから新 semantics に切り替える

#### Migration code path 例

```php
// 旧: heartbeat_ttl=90 で運用中
PromptOperation::for($scope, 'op', $key)->withHeartbeatTtl(90)->claimOrFollow();

// 新: 180 に変えたい場合は operationVersion を bump
PromptOperation::for($scope, 'op', $key)
    ->withOperationVersion(2) // 旧 v1 Job と namespace が分かれる
    ->withHeartbeatTtl(180)
    ->claimOrFollow();
```

## [0.14.1] - 2026-04-28

### Fixed — `streamingPhase()` の generic 型を厳格化 (PHPStan 型伝播)

`streamingPhase()` の `@param` / `@return` を `@template TKey, TYield` ベースの generic 化。

#### 修正の背景

v0.14.0 のシグネチャは `Closure(...): \Generator<mixed, mixed, mixed, mixed>` /
`@return \Generator<mixed, mixed, mixed, mixed>` と loose だったため、app 側で
`\Generator<int, array{event: string, data: array<string, mixed>}>` のような厳格な戻り値型を
持つ method 内で `yield from $handle->streamingPhase(...)` を書くと PHPStan が
`generator.keyType` / `generator.valueType` エラーを出していた。

#### 動作

- `body` の yield 型 (`TKey`, `TYield`) を `@template` で取り出し、`@return` に伝播
- 既存の v0.14.0 利用箇所は変更不要 (シグネチャ互換)

#### Migration

```php
// Before (PHPStan で yield from の戻り値が mixed 扱い)
yield from $handle->streamingPhase('phase', function () {
    yield ['event' => 'x', 'data' => []];
});

// After (yield 型が body から推論され caller の Generator 型と整合)
// → app 側コード変更不要
```

## [0.14.0] - 2026-04-28

### Added — `streamingPhase()` API for SSE/streaming pipelines inside phase scope

`PromptOperationHandle::streamingPhase()` を追加。`phase()` と異なり body は Generator を
返し、その yield が caller に forward される。SSE / streaming pipeline を phase scope に
取り込むための新 API。

#### 修正の背景

v0.13.0 までは `phase()` body 内で `yield` できず、SSE pipeline (Generator) を phase scope
に取り込めなかった。app 側で「phase は no-op marker、pipeline は phase の外で yield」
パターンで回避していたが、これは:

- 実 work が lease (heartbeat-aware) の外で走る
- TTL 切れ / reset で別 owner が takeover した後、旧 owner が message を再永続化できる
- 監査 (該当 app の comprehensive-audit) で Critical 1 として指摘された構造的問題

#### 使用例

```php
yield from $handle->streamingPhase('send-message-pipeline', function ($phase) {
    // SSE event を caller に forward しつつ、phase scope 内で実 work が走る
    yield from $pipeline->stream();
});
$handle->complete();
```

#### 動作

1. body が Generator を返す
2. 各 yield 値を caller に forward
3. body 完了後に commit transaction (phase row insert / llm_call_log 紐付け / heartbeat)
4. body 内で例外 → recordPhaseError + PromptJobPhaseFailed event 発火
5. 既に completed な phase は body を呼ばず onSkipped 起動

#### 互換性

- `phase()` API は不変 (内部で commit transaction を共通 helper `commitPhase()` に切り出し)
- `phase()` 利用 app は無変更で動く
- `streamingPhase()` 利用は app 側の opt-in
- migration 不要 (DB schema 不変)

#### Tests

`tests/Operation/PromptOperationTest.php` に 4 件追加:
- yield forward + commit transaction
- body が Generator を返さないと TypeError
- body 内 throw で fail event 発火 + recordPhaseError
- completed phase スキップ + onSkipped

---

## [0.13.0] - 2026-04-27

### Changed — `scope_id` を string 化 (BREAKING for users of v0.12.0)

`PromptOperation` の scope モデルとして `HasUlids` 等の string 主キーを持つ
モデルもサポートするため、`scope_id` を `unsignedBigInteger` から `string(255)` に変更。

#### 修正の背景 (v0.12.0 のバグ)

v0.12.0 では `PromptOperationBuilder` が `(int) $this->scope->getKey()` でキャストして
`scope_id` を確定していた。これは `HasUlids` 等の string 主キー (例: ULID
"01kq6hd3yytp6r4jdz7hfst5y4") を持つモデルに対して致命的な collision を引き起こした:

- ULID は時刻接頭辞のため、同じ年内のすべての ULID が `01...` で始まる
- PHP の `(int) "01kq..."` は `1` を返す (先頭の数字部分のみ parse)
- 結果として **同じ年に作られた全 Encounter が `scope_id=1` に collision** していた
- 同じ idempotency_key を別 Encounter で使うと UNIQUE 制約違反、または誤った dedup

#### 変更点

- `prism_prompt_jobs.scope_id`: `unsignedBigInteger` → `string(255)`
- `prism_prompt_serialization_locks.scope_id`: 同上
- `PromptOperationBuilder`: `(int) $scope->getKey()` → `(string) $scope->getKey()`
- `PromptJob` model: cast `'scope_id' => 'integer'` → `'string'`
- `PromptSerializationLock` model: 同上
- `PromptOperationHandle` / `BlockedBySerialization` / `InternalPromptJobPhase` /
  `PromptMetadataBuilder`: コンストラクタ引数の `int $scopeId` → `string $scopeId`

#### 影響

- v0.12.0 で integer 主キー (autoincrement / `bigInteger`) のモデルを使っていたユーザー:
  PHP は integer も `(string)` cast で動くため、コードレベルでは互換。ただし migration
  を再適用する必要がある (既存データは migration で型変更が必要)
- v0.12.0 で string 主キー (HasUlids 等) のモデルを使っていたユーザー: 上記 collision バグから救済される
- migration: 既存テーブルの型変更が必要 (`Schema::table` で `string` への ALTER COLUMN)。
  すでに本番投入してデータがある環境では schema 変更時のデータ migration が必要

#### Test

`tests/Operation/PromptOperationTest.php` に `v0.13.0: ULID 主キーの scope モデルでも
(int) cast collision なく 1 model = 1 scope_id で識別される` を追加。

---

## [0.12.0] - 2026-04-26

### Added — Prompt Operation 基盤 (opt-in)

LLM 呼び出しを含む operation を妨害 (リロード / LLM 失敗 / プロセスクラッシュ /
2 タブ並行) に対して堅牢化し、途中から再開可能にする Job 基盤。
`Prompt` クラスは無変更。`PromptOperation` を独立 coordinator として追加。

- **`Kent013\PrismPrompt\Operation\PromptOperation::for($scope, $operationName, $idempotencyKey, $version=1)`** — entry point。
  - `->withPhases(['phase-a', 'phase-b'])` で phase manifest を宣言
  - `->withSerializationGroup('training-write:42')` で同一 scope 内の別 operation との排他制御
  - `->withHeartbeatTtl(90)` で stale 検知の閾値秒数を設定
  - `->withRetryFailed(true)` (default) で `failed` 状態の Job も再 claim 可能
  - `->claimOrFollow()` で `ClaimResult` を返す
- **`ClaimResult` sealed 6 種** — `OwnerClaim` / `SameOperationFollower` / `BlockedBySerialization` / `AlreadyCompleted` / `AlreadyFailed` / `AlreadyCancelled`
- **`PromptOperationHandle`** — Owner 専用 API:
  - `->phase($name, $body, ?$onSkipped, ?$onCommit)` — phase 実行ラッパー。body 内で
    LLM 呼び出し + 副作用永続化を行い、`onCommit` が phase row insert と同一
    transaction 内で実行される (副作用の draft → active promote 等)
  - `->complete(?$onCommit)` — 全 phase 完了検証後に status='completed'
  - `->fail($e, ?$onFail)` — エラー記録
  - `->cancel($reason, ?$onCancel)` — 明示キャンセル
  - `->follow(): FollowResult` — Follower 専用、leader の完了を polling
  - `->metadata()` — `Prompt::withMetadata()` 用の metadata builder
- **`PromptJobPhase` interface** — body 内で受け取る phase handle:
  - `attachLlmCallLog(int $logId)` / `attachLlmCallByCorrelationId(string $cid)` で
    phase ↔ llm_call_log を紐付け (correlation_id は 2 段階解決で event listener 経由
    の遅延記録にも対応)
  - `setOutputReference(string)` / `heartbeat()` (long-running phase 用)
- **DB schema** — `prism_prompt_jobs` / `_job_attempts` / `_job_phases` /
  `_job_phase_llm_calls` / `_serialization_locks` / `_pending_llm_call_resolutions` の
  6 テーブル。`config('prism-prompt.jobs.table_prefix')` でプレフィックス変更可
- **Lifecycle events** (全て `ShouldDispatchAfterCommit`):
  `PromptJobClaimed` / `PromptJobPhaseStarted` / `PromptJobPhaseCompleted` /
  `PromptJobPhaseFailed` / `PromptJobCompleted` / `PromptJobFailed` /
  `PromptJobCancelled` / `PromptJobStaleDetected`
- **`ResolvePendingLlmCallReferences` listener** — `PromptExecutionCompleted` 受信時に
  pending 行を解決 (after-commit listener 等で llm_call_logs が後から記録されるケースに対応)
- **`prism:prompt-jobs:prune` artisan command** — retention 設定に従って古い行を削除
  (`completed: 30d` / `failed: 90d` / `cancelled: 90d` がデフォルト、env で上書き可)
- **`config/prism-prompt.php` の `jobs` セクション** — `enabled=true` (default) で migration が
  load される。`PRISM_PROMPT_JOBS_ENABLED=false` で無効化可能

### Notes

- 既存ユーザーは何もしなくても動作変化なし (Job 機能は opt-in: `enabled=true` がデフォルト
  だが、`PromptOperation::for()` を呼ばない限り何も起きない)
- 既存 `Prompt::execute()` / `executeSync()` / `Prompt::fake()` は完全互換
- migration は `loadMigrationsFrom` で自動 load される。テスト環境で disable したい場合は
  `prism-prompt.jobs.enabled=false` にする

## [0.11.0] - 2026-04-24

### Added

- **`Prompt::withCacheBreakpoints(array $sectionToCacheType): static`** — mark YAML `sections:` entries whose rendered content should be emitted with a provider cache-control marker (e.g. Anthropic `cache_control: {type: ephemeral}`). Unknown section names fail fast with `InvalidCacheBreakpointException` so typos do not silently disable caching.
- **`CacheType` enum** (`Kent013\PrismPrompt\Values\CacheType`) — currently declares the `Ephemeral` case; the enum string value matches the Anthropic API value verbatim so callers can serialize directly.
- **`PromptPool::executeWithWarmup(array $prompts, ?int $concurrency = null, ?MessagesRequestBuilder $builder = null): array`** — execute a batch of prompts against the Anthropic Messages API with **warmup-then-parallel** semantics:
  1. The first prompt is sent alone so any `cache_control: ephemeral` breakpoints get written to the provider cache.
  2. The remaining prompts are fired in parallel chunks of up to `concurrency` requests at a time, so each of them reads the warm cache entry populated by step 1.
  - Bypasses Prism's builder (retry, trace, etc.) so HTTP failures surface to the caller's retry strategy (e.g. Laravel job `$tries`). Domain mapping (index → axis / category / etc.) happens upstream via `PoolExecutionException::getPromptIndex()`.
  - `concurrency` defaults to `config('prism-prompt.pool.concurrency')` (env `PRISM_PROMPT_POOL_CONCURRENCY`; accepts both int and numeric strings from `.env`; null = no cap beyond the remaining-prompt count).
  - Optional `$builder` parameter accepts a preconfigured `MessagesRequestBuilder` (e.g. with a tenant-specific API key) or a test double; defaults to `app(MessagesRequestBuilder::class)`.
- **`MessagesRequestBuilder`** (`Kent013\PrismPrompt\Providers\Anthropic\MessagesRequestBuilder`) — builds a byte-stable Anthropic Messages API payload (url / headers / body) from a `Prompt` instance. Used by `PromptPool` for both the warmup single-shot and each parallel call so the provider sees byte-identical request bodies (required for cache key hits to register). Constructor accepts optional `?string $apiKey` for explicit runtime override; falls back to `config('prism.providers.anthropic.api_key')` when null. `cache_control` is GA on the 2023-06-01 API family and does **not** require the former `anthropic-beta: prompt-caching-2024-07-31` header, so the builder keeps its header set minimal.
- **`PoolExecutionException`** — thrown on any single-prompt failure inside `PromptPool::executeWithWarmup`. Carries `getPromptIndex(): int` (position in the input list; warmup = 0) and the underlying `Throwable` as `$previous`, so callers can map back to their own domain identifier.
- **`InvalidCacheBreakpointException`** — thrown from `withCacheBreakpoints()` when a section name is not declared under YAML `sections:`.
- **Public hooks on `Prompt`** for direct-HTTP request builders that bypass `executePrism()`:
  - `getRenderedSections(): array<string,string>` — Blade-render each YAML `sections:` entry at call-time.
  - `getRenderedSystemPrompt(): string` — Blade-render `system_prompt:` (`''` when absent).
  - `getImagePaths(): list<string>` — base returns `[]`; subclasses override when they support image inputs.
  - `getCacheBreakpoints(): array<string,CacheType>`, `getClientOptions(): array<string,mixed>`, `getModel(): string`, `getMaxTokens(): int` — expose resolved runtime config for the HTTP layer.
  - `renderUserPromptForPool(): string`, `parseResponseForPool(string): mixed` — **`@internal` public hooks** used by `PromptPool` to bridge to the subclass's protected `render()` / `parseResponse()`. **Not covered by SemVer BC promises; end-user code should continue using `executeSync()` / `execute()`.**

### Config

- **`prism-prompt.pool.concurrency`** (`env('PRISM_PROMPT_POOL_CONCURRENCY')`) — default concurrency for `PromptPool::executeWithWarmup`. Accepts `null` / `""` / int / positive numeric-string; any other value (negative, float, non-numeric string, bool) raises `InvalidArgumentException` at execution time.

### Testing

- 22 regression tests added across two new suites:
  - `tests/Unit/WithCacheBreakpointsTest.php` (8): known/unknown section names, section rendering with template variables, no-sections default, system-prompt accessor, model / max_tokens / client_options / image accessors.
  - `tests/Unit/PromptPoolTest.php` (14): single-prompt shortcut, warmup-then-parallel order, `cache_control` attachment, header/body shape, HTTP 500 → `PoolExecutionException(index=0)`, concurrency-arg validation, config-capped concurrency, empty-input rejection, second-chunk index preservation (`getPromptIndex` = 2), API-key non-leak into exception messages, builder injection override, numeric-string config acceptance (`.env`), non-numeric-string rejection.
- Package total: 153 → 175 tests (+22); 382 → 430 assertions.

### Notes

- `PromptPool` is Anthropic-specific in this release. Non-Anthropic providers should continue calling `Prompt::executeSync()` / `execute()` via Prism.
- The `@internal` hooks (`renderUserPromptForPool` / `parseResponseForPool`) are public because PHP requires public visibility for cross-class access without reflection; they are not part of the end-user API surface.

## [0.10.0] - 2026-04-20

### Added

- **`Prompt::installFake(PromptFake $fake)` static method** — public injection point for custom `PromptFake` instances (typically subclasses with specialized `nextResponse()` behaviour). Useful for browser/E2E tests that need a deterministic fake keyed by prompt class, where the default sequence-based `PromptFake` returned by `Prompt::fake($responses)` is insufficient.
  - Backwards compatible: does not modify `fake()` / `getFake()` / `isFaking()` / `stopFaking()` semantics.
  - Shares the same static `$fake` slot as `fake()` — calling one overrides the other.

### Testing

- 3 regression tests added in `tests/Unit/PromptTest.php`:
  - `installFake` replaces the active fake instance with a custom one
  - `installFake` and `fake()` share the same static slot
  - `installFake` accepts `PromptFake` subclasses

## [0.9.0] - 2026-04-18

### Added

- **`UserInput` value object for prompt-injection mitigation** (`Kent013\PrismPrompt\Values\UserInput`):
  - Wrap untrusted end-user-supplied strings before injecting them into a prompt template.
  - `UserInput::from($value)` factory or `UserInput::withTag($value, 'user_query')` for a custom tag name.
  - On render, content is wrapped in `<user_input> ... </user_input>` delimiters so the model can be instructed to treat it as data, not instructions.
  - **Breakout escape** is **case-insensitive and whitespace-tolerant** via regex `/<\s*\/?\s*TAG\s*>/i`: closing/opening variants such as `</user_input>`, `</USER_INPUT>`, `</User_Input>`, `</user_input >`, `<\n/user_input\n>`, `</  user_input  >` are all rewritten to `<user_input_escaped>` / `</user_input_escaped>`, so an adversarial user cannot close the boundary and inject at the surrounding prompt level.
  - Implements `Illuminate\Contracts\Support\Htmlable` so the default Blade `{{ $var }}` syntax emits the tagged content verbatim without `htmlspecialchars` mangling. No YAML/template changes are required to adopt it — only the caller side switches from `$raw` to `UserInput::from($raw)`.
- **`DefensiveInstructions` helper** (`Kent013\PrismPrompt\Values\DefensiveInstructions`):
  - `forUserInput(string $tag = 'user_input')` returns an English paragraph (as `Illuminate\Support\HtmlString`) explaining the `<user_input>` security boundary to the model (forbids treating tagged content as instructions, persona overrides, system-prompt disclosure, tool calls, etc.).
  - `forUserInputJa()` returns the same guidance in Japanese for localised system prompts.
  - Returns `HtmlString` so Blade's default `{{ $var }}` syntax keeps the `<user_input>` tag visible in the rendered system prompt (no `htmlspecialchars` mangling).
  - Intended to be prepended in `system_prompt` YAML or in a subclass's `buildSystemMessage()`.
  - **Not a security guarantee** — combine with output constraints, authorisation checks, and output validation in the application layer.

### Changed

- Documentation-only: README gains a **"Prompt Injection Mitigation"** section describing `UserInput` + `DefensiveInstructions`, plus a new example file [`examples/06-user-input-defense.php`](examples/06-user-input-defense.php) showing the typical call pattern and breakout-escape behaviour.

### Testing

- **43 dedicated injection-attack tests** (package total: 94 → 150 tests, +56, 376 assertions):
  - Close-tag breakouts in 10 variants (lowercase / uppercase / mixed-case / random-case / leading-whitespace / trailing-whitespace / inner-whitespace / multi-space / newline-separated / stacked).
  - Open-tag attack variants and nested `<user_input>` attempts.
  - Already-escaped `</user_input_escaped>` in legitimate content is left untouched (no re-escape loop).
  - Multi-slot isolation: `user_query` and `user_document` regions do not cross-contaminate.
  - 7 real-world jailbreak payloads via Pest `dataset()`: delimiter-closure / markdown-wrapped breaks / stacked-break-reopen / long-prefix decoys / unicode-combined / mixed-case close / whitespace-padded close.
  - Edge cases: 1MB content, whitespace-only content, binary bytes (`\x00..\xff`).
  - Documented limitations (non-goals): fullwidth homoglyph `＜/user_input＞`, HTML-entity form `&lt;/user_input&gt;`, and social-engineering style injections are intentionally NOT neutralised — the guarantee is purely structural (delimiter integrity).
  - Blade integration: `{{ $var }}` + `{!! $var !!}` + concatenation + idempotency across multiple `toHtml()` calls.
  - Structural immutability via `ReflectionClass::isReadOnly()`.
  - End-to-end `Prompt::fake()` flow verifying the LLM receives exactly one real delimiter pair per `UserInput` slot regardless of attack shape, and that `DefensiveInstructions` actually lands in the system message via YAML `{{ ::forUserInput() }}`.

### Notes

- Backward compatible. Existing YAML templates and Prompt subclasses continue to work unchanged; `UserInput` is entirely opt-in on the caller side.

## [0.8.1] - 2026-04-14

### Changed

- Documentation-only release. No code changes.
- **README rewrite** for v0.7 / v0.8 surface:
  - Features list: added event-driven hooks, `withMetadata()`, built-in USD cost calculation, and listener-based debug logging.
  - New **"Events & Metadata"** section documenting `PromptExecutionCompleted` / `PromptExecutionFailed` payloads and `withMetadata()` merge behavior.
  - New **"USD Cost Calculation"** section covering the pricing table, snapshot audit trail, `cost === null` vs `unknown_model:...` zero-cost snapshot distinction, and the deliberate out-of-scope status of FX conversion and persistence.
  - Rewrote **"Debug Logging"** to describe the listener-based architecture (`PerformanceLogListener`, `PerformanceDebugFileListener`) instead of the in-line logger removed in v0.7.
  - Removed the stale **"Custom Logger"** subsection whose `getPerformanceLogger()` override hook no longer exists on `Prompt`. Added a note that `EmbeddingPrompt` still uses the `PerformanceLoggerInterface` contract.
  - **Configuration Reference** split into `config/prism-prompt.php` and `config/prism-prompt-pricing.php` tables, plus a second `vendor:publish` command for the pricing tag.
- **New example**: [`examples/05-events-and-cost.php`](examples/05-events-and-cost.php) — subscribing to events, attaching caller-side metadata, reading `CostCalculation`, and handling the `cost === null` failure path vs the `unknown_model:...` zero-cost snapshot.

## [0.8.0] - 2026-04-14

### Added

- **Built-in USD cost calculation**: `PromptExecutionCompleted` now carries a `cost: ?CostCalculation` field populated automatically from the configured pricing table. Consumers no longer need to re-implement per-model pricing.
- **`Kent013\PrismPrompt\Pricing\LlmPricingService`**: Resolves per-model prices from `config/prism-prompt-pricing.php` and produces a `CostCalculation` (input / output / cache-write / cache-read / total USD) along with an immutable `PricingSnapshot` for audit.
  - Bills reasoning/thought tokens at the output rate.
  - Unknown models fall back to a zero-cost snapshot (with throttled warning) by default; switch to `unknown_model_behavior = throw` to fail loudly.
- **`Kent013\PrismPrompt\Pricing\CostCalculation`** and **`PricingSnapshot`** DTOs. `PricingSnapshot` round-trips via `toArray()` / `fromArray()` for persistence.
- **Shipped default pricing** (`config/prism-prompt-pricing.php`) for current Anthropic Claude models. Publish with `php artisan vendor:publish --tag=prism-prompt-pricing` and override in your app to keep up with vendor price changes.

### Changed

- `Prompt::executePrism()` now computes cost via the `LlmPricingService` singleton (bound in `PromptServiceProvider`) before dispatching `PromptExecutionCompleted`. If the service is unavailable or pricing resolution throws unexpectedly, `cost` is left `null` and the error is logged — the LLM call itself is never affected.
- `PromptExecutionCompleted::$cost` is a non-breaking additive field (defaults to `null`). Existing listeners continue to work unchanged.

### Notes

- FX conversion (e.g. USD→JPY) and cost persistence are intentionally **not** in the package. Apps that need non-USD accounting or historical cost logs should subscribe to `PromptExecutionCompleted` and handle those concerns locally.

## [0.7.0] - 2026-04-14

### Added

- **Event-driven execution hooks**: `Prompt::executeSync()` now dispatches Laravel events so that callers (apps, other packages) can hook into successful completions and failures without subclassing.
  - `PromptExecutionCompleted` — carries `executionId`, `promptClass`, `promptTemplate`, `provider`, `model`, `finishReason`, `stepCount`, `totalUsage`, `durationMs`, `requestId`, `response`, and caller-supplied `metadata`.
  - `PromptExecutionFailed` — carries the failing `exception` plus timing/provider info. Failed calls may still have incurred API cost, so observers can still record them.
- **`Prompt::withMetadata(array $metadata)`**: Attach arbitrary caller context (e.g. `evaluation_id`, `persona_id`) that flows through into both events. Multiple calls merge.
- **Listener-based debug logging**: `PerformanceLogListener` and `PerformanceDebugFileListener` replace the in-line `PerformanceLogger` calls previously embedded in `Prompt::executeSync()`. They are auto-wired when `prism-prompt.debug.enabled` / `prism-prompt.debug.save_files` are on — behavior is unchanged for existing users.

### Changed

- `Prompt::executeSync()` no longer calls `PerformanceLogger` directly; logging happens via `PromptExecutionCompleted` → `PerformanceLogListener`. Debug-file output moves to `PerformanceDebugFileListener`. No user-visible config changes.
- Event dispatch and listener errors are caught and logged (not re-thrown), so logging failures cannot break user-facing LLM calls.

### Notes

- `PerformanceLogger` and `PerformanceLoggerInterface` are retained for `EmbeddingPrompt`, which has **not** been migrated to events in this release. Its behavior is unchanged.

## [0.6.0] - 2026-02-14

### Added

- **Message-based API (`withMessages`)**: Replaced internal `withPrompt()` with `withMessages()` for proper system/user/assistant role separation
  - `buildMessages()`: Full control over the message array (override for custom structures)
  - `buildSystemMessage()`: Override to customize the system message
  - `buildConversationMessages()`: Override to customize user/assistant messages
  - `renderSystemPrompt()`: Renders the `system_prompt` field from YAML with Blade
  - `validateMessages()`: Validates message constraints (non-empty, last must be UserMessage, single SystemMessage at position 0)
- **YAML `system_prompt` field**: New field in YAML templates for system-role content, rendered via Blade with template variables
- **`PromptTemplate::$systemPrompt`**: New property loaded from YAML `system_prompt` field
- **New PromptFake assertion methods**:
  - `assertSystemMessageContains(string $text)`: Assert system message contains text
  - `assertUserMessageContains(string $text)`: Assert user message contains text
  - `assertMessageCount(int $expectedCount)`: Assert number of messages
  - `assertHasSystemMessage()`: Assert a system message exists

### Changed

- **Breaking**: `Prompt::executePrism()` now uses `withMessages()` instead of `withPrompt()`
- **Breaking**: `PromptFake::record()` now accepts `array $messages` instead of `string $prompt`
- `assertPromptContains()` now searches across all message contents (backward compatible in usage)

## [0.5.0] - 2026-02-14

### Added

- **Multiple Provider Fallback**: Automatic provider/model selection based on available API keys
  - `withApiKeys(array $apiKeys)`: Set multiple API keys for automatic selection
  - `withProviderConfigs(array $configs)`: Set multiple provider configurations with additional options
  - `models` field in YAML: Specify available models with priority order
  - Automatic selection from `models` list when multiple API keys are provided
  - Falls back to default `provider`/`model` when no match found
- **Priority-based Selection**: Lower priority number = higher priority (e.g., `priority: 1` before `priority: 2`)

### Changed

- `Prompt::executePrism()` now uses `selectOptimalProvider()` for provider selection
- `EmbeddingPrompt::executePrismEmbedding()` now uses `selectOptimalProvider()` for provider selection

### Internal

- Added `ResolvesProviderConfig::selectOptimalProvider()` method
- Added `ResolvesProviderConfig::selectFromModels()` method
- Added `$availableProviders` property to track multiple provider configurations

## [0.4.1] - 2026-02-06

### Changed

- `PromptTemplate` から `final` を削除（サブクラスで拡張可能に）
- `$promptsDirectory` プロパティを追加（YAMLサブディレクトリの指定をサポート）

### Removed

- `LoadsPromptTemplate` trait（不要なため削除）
- `PromptTemplateNotFoundException`, `InvalidTemplatePathException`（LoadsPromptTemplate削除に伴い）

## [0.4.0] - 2026-02-06

### Added

- **`Prompt::load()`**: YAML名を指定するだけでPromptを実行可能（PHPクラス不要）
  - `Prompt::load('greeting', ['userName' => 'Alice'])->executeSync()`
  - Returns raw text via `TextPrompt` (concrete `Prompt<string>`)
- **`EmbeddingPrompt::load()`**: YAML名を指定するだけでEmbeddingを実行可能
  - `EmbeddingPrompt::load('document-embedding')->executeSync('text')`
- **`TextPrompt`**: `Prompt<string>` の具象クラス（`load()` ファクトリで使用）
- **`$promptName` プロパティ**: サブクラスでYAML相対パスを指定（例: `'standard/greeting'`）
- **命名規則によるYAML解決**: クラス名から自動導出（`HintGenerationPrompt` → `hint_generation.yaml`）

### Changed

- `getTemplatePath()` を abstract から通常メソッドに変更（`ResolvesProviderConfig` trait）
- `EmbeddingPrompt` を abstract から具象クラスに変更

## [0.3.0] - 2026-02-06

### Added

- **EmbeddingPrompt**: Base class for embedding generation via `Prism::embeddings()`
  - `executeSync(string $text)` and `execute(string $text)` methods
  - Resolution priority: class property > YAML template > config default
  - Performance logging support
- **EmbeddingPrompt Testing**: `EmbeddingPrompt::fake()` API for mocking embedding executions
  - `EmbeddingResponseFake` builder with `withEmbedding()` and `withUsage()`
  - `EmbeddingPromptFake` with assertions (`assertCallCount`, `assertTextContains`, `assertProvider`, `assertModel`)
- **ResolvesProviderConfig trait**: Extracted shared provider/model resolution logic for reuse between Prompt and EmbeddingPrompt
- Config keys: `default_embedding_provider`, `default_embedding_model`

### Changed

- Refactored `Prompt` to use `ResolvesProviderConfig` trait (no breaking changes)

## [0.2.0] - 2026-02-06

### Added

- **Runtime API Key Configuration**: `withApiKey()` and `withProviderConfig()` methods for runtime provider configuration
- **Testing Support**: `Prompt::fake()` API similar to `Prism::fake()` for mocking prompt executions in tests
  - `TextResponseFake` builder for creating fake responses
  - `PromptFake` with assertion methods (`assertCallCount`, `assertPromptContains`, `assertProvider`, `assertModel`, etc.)
- **Performance Logging**: `PerformanceLogger` class for debugging LLM calls
  - Configurable via `prism-prompt.debug.*` config keys
  - Optional file saving for prompts, responses, and metadata
- **PerformanceLoggerInterface**: Contract for custom logger implementations

### Changed

- Namespace changed from `Because\PrismPrompt` to `Kent013\PrismPrompt`

## [0.1.0] - 2026-02-05

### Added

- Initial release
- `Prompt` base class with Mailable-like API
- `PromptTemplate` for YAML-based template loading
- `LoadsPromptTemplate` trait for template resolution
- `ValidatesPromptVariables` trait for variable validation
- Configuration for default provider, model, max tokens, and temperature
- YAML template caching support
