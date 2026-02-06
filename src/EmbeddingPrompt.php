<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Kent013\PrismPrompt\Contracts\EmbeddingPromptInterface;
use Kent013\PrismPrompt\Testing\EmbeddingPromptFake;
use Kent013\PrismPrompt\Testing\EmbeddingResponseFake;
use Kent013\PrismPrompt\Traits\ResolvesProviderConfig;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Facades\Prism;
use React\Promise\PromiseInterface;
use Webmozart\Assert\Assert;

use function React\Async\async;

/**
 * Base class for embedding prompts
 *
 * @implements EmbeddingPromptInterface<array<int, float>>
 *
 * @phpstan-consistent-constructor
 */
class EmbeddingPrompt implements EmbeddingPromptInterface
{
    use ResolvesProviderConfig;

    protected static ?EmbeddingPromptFake $fake = null;

    public function __construct()
    {
        $this->loadMetadata();
    }

    /**
     * Create instance from YAML template name
     */
    public static function load(string $name): static
    {
        $basePath = config('prism-prompt.prompts_path', resource_path('prompts'));
        Assert::string($basePath);

        $instance = new static;
        $instance->templatePath = $basePath.'/'.$name.'.yaml';
        $instance->loadMetadata();

        return $instance;
    }

    /**
     * @param  array<int, EmbeddingResponseFake>  $responses
     */
    public static function fake(array $responses = []): EmbeddingPromptFake
    {
        static::$fake = new EmbeddingPromptFake($responses);

        return static::$fake;
    }

    public static function getFake(): ?EmbeddingPromptFake
    {
        return static::$fake;
    }

    public static function isFaking(): bool
    {
        return static::$fake !== null;
    }

    public static function stopFaking(): void
    {
        static::$fake = null;
    }

    /**
     * @return PromiseInterface<array<int, float>>
     */
    public function execute(string $text): PromiseInterface
    {
        /** @var PromiseInterface<array<int, float>> */
        return async(function () use ($text): array {
            return $this->executePrismEmbedding($text);
        })();
    }

    /**
     * @return array<int, float>
     */
    public function executeSync(string $text): array
    {
        return $this->executePrismEmbedding($text);
    }

    protected function getDefaultProviderConfigKey(): string
    {
        return 'prism-prompt.default_embedding_provider';
    }

    protected function getDefaultModelConfigKey(): string
    {
        return 'prism-prompt.default_embedding_model';
    }

    protected function getDefaultProviderFallback(): string
    {
        return 'openai';
    }

    protected function getDefaultModelFallback(): string
    {
        return 'text-embedding-3-small';
    }

    /**
     * @return array<int, float>
     */
    protected function executePrismEmbedding(string $text): array
    {
        $provider = $this->resolveProvider();
        $model = $this->resolveModel();

        if (static::isFaking() && static::$fake !== null) {
            static::$fake->record(static::class, $text, $provider, $model);
            $fakeResponse = static::$fake->nextResponse();

            return $fakeResponse->getEmbedding();
        }

        $logger = $this->getPerformanceLogger();
        $executionId = $logger?->startExecution(static::class, $provider, $model, $text);
        $startTime = microtime(true);

        $result = Prism::embeddings()
            ->using($provider, $model, $this->providerConfig)
            ->fromInput($text)
            ->asEmbeddings();

        Assert::isInstanceOf($result, EmbeddingsResponse::class);
        Assert::notEmpty($result->embeddings, 'No embeddings returned');

        /** @var array<int, float> $embedding */
        $embedding = $result->embeddings[0]->embedding;

        if ($logger && $executionId) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $logger->completeExecution(
                $executionId,
                json_encode($embedding, JSON_THROW_ON_ERROR),
                $durationMs,
                $result->usage->tokens,
                null
            );
        }

        return $embedding;
    }

    /**
     * Get the performance logger instance
     */
    protected function getPerformanceLogger(): ?Contracts\PerformanceLoggerInterface
    {
        if (! app()->bound(PerformanceLogger::class)) {
            return null;
        }

        $logger = app(PerformanceLogger::class);

        return $logger->isEnabled() ? $logger : null;
    }
}
