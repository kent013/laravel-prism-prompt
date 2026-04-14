# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
