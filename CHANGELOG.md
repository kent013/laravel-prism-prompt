# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.0] - 2026-02-06

### Added

- **`Prompt::load()`**: YAML名を指定するだけでPromptを実行可能（PHPクラス不要）
  - `Prompt::load('greeting', ['userName' => 'Alice'])->executeSync()`
  - Returns raw text via `TextPrompt` (concrete `Prompt<string>`)
- **`EmbeddingPrompt::load()`**: YAML名を指定するだけでEmbeddingを実行可能
  - `EmbeddingPrompt::load('document-embedding')->executeSync('text')`
- **`TextPrompt`**: `Prompt<string>` の具象クラス（`load()` ファクトリで使用）

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
