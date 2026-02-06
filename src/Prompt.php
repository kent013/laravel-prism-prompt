<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use JsonException;
use Kent013\PrismPrompt\Contracts\PromptInterface;
use Kent013\PrismPrompt\Exceptions\InvalidJsonResponseException;
use Kent013\PrismPrompt\Testing\PromptFake;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Kent013\PrismPrompt\Traits\ResolvesProviderConfig;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use React\Promise\PromiseInterface;
use Webmozart\Assert\Assert;

use function React\Async\async;

/**
 * Base class for LLM prompts (Mailable-like API)
 *
 * Settings priority (high to low):
 * - Class property
 * - YAML template
 * - Config default
 *
 * @template TResponse
 *
 * @implements PromptInterface<TResponse>
 */
abstract class Prompt implements PromptInterface
{
    use ResolvesProviderConfig;

    protected static ?PromptFake $fake = null;

    protected ?int $maxTokens = null;

    protected ?float $temperature = null;

    /** @var array<string, mixed> */
    protected array $templateVariables = [];

    public function __construct()
    {
        $this->loadMetadata();
    }

    /**
     * Create a TextPrompt instance from YAML template name
     *
     * @param  array<string, mixed>  $variables
     *
     * @return TextPrompt
     */
    public static function load(string $name, array $variables = []): self
    {
        $instance = new TextPrompt;
        $instance->templatePath = $instance->getPromptsBasePath().'/'.$name.'.yaml';
        $instance->templateVariables = $variables;
        $instance->loadMetadata();

        return $instance;
    }

    /**
     * Start faking prompt executions for testing
     *
     * @param  array<int, TextResponseFake>  $responses
     */
    public static function fake(array $responses = []): PromptFake
    {
        static::$fake = new PromptFake($responses);

        return static::$fake;
    }

    /**
     * Get the current fake instance
     */
    public static function getFake(): ?PromptFake
    {
        return static::$fake;
    }

    /**
     * Check if currently faking
     */
    public static function isFaking(): bool
    {
        return static::$fake !== null;
    }

    /**
     * Stop faking and restore normal behavior
     */
    public static function stopFaking(): void
    {
        static::$fake = null;
    }

    /**
     * Execute prompt asynchronously and return DTO
     *
     * @return PromiseInterface<TResponse>
     */
    public function execute(): PromiseInterface
    {
        return async(function (): mixed {
            $responseText = $this->executePrism();

            return $this->parseResponse($responseText);
        })();
    }

    /**
     * Execute prompt synchronously and return DTO
     *
     * @return TResponse
     */
    public function executeSync(): mixed
    {
        $responseText = $this->executePrism();

        return $this->parseResponse($responseText);
    }

    /**
     * Parse response text into DTO (implement in each Prompt subclass)
     *
     * @return TResponse
     */
    abstract protected function parseResponse(string $responseText): mixed;

    /**
     * Extract JSON from LLM response
     *
     * @return array<string, mixed>
     *
     * @throws InvalidJsonResponseException
     */
    protected function extractJson(string $content): array
    {
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = $matches[0];
        } else {
            throw new InvalidJsonResponseException('No JSON found in response');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            Assert::isArray($data);

            /** @var array<string, mixed> $data */
            return $data;
        } catch (JsonException $e) {
            Log::error('[PrismPrompt] JSON parse error', [
                'content' => $content,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidJsonResponseException(
                'Failed to parse JSON: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Render Blade template with variables
     */
    protected function render(): string
    {
        $promptTemplate = $this->metadata['prompt'] ?? '';
        Assert::stringNotEmpty($promptTemplate, 'Prompt template is empty in metadata');

        $variables = $this->templateVariables !== [] ? $this->templateVariables : get_object_vars($this);

        return Blade::render($promptTemplate, $variables);
    }

    /**
     * Execute Prism LLM call
     */
    protected function executePrism(): string
    {
        $prompt = $this->render();
        $provider = $this->resolveProvider();
        $model = $this->resolveModel();

        if (static::isFaking() && static::$fake !== null) {
            static::$fake->record(static::class, $prompt, $provider, $model);
            $fakeResponse = static::$fake->nextResponse();

            return $fakeResponse->getText();
        }

        $logger = $this->getPerformanceLogger();
        $executionId = $logger?->startExecution(static::class, $provider, $model, $prompt);
        $startTime = microtime(true);

        $result = Prism::text()
            ->using($provider, $model, $this->providerConfig)
            ->withPrompt($prompt)
            ->withMaxTokens($this->resolveMaxTokens())
            ->asText();

        Assert::isInstanceOf($result, TextResponse::class);

        if ($logger && $executionId) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $logger->completeExecution(
                $executionId,
                $result->text,
                $durationMs,
                $result->usage->promptTokens,
                $result->usage->completionTokens
            );
        }

        return $result->text;
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

    /**
     * Resolve max tokens (class property > YAML > config)
     */
    protected function resolveMaxTokens(): int
    {
        if ($this->maxTokens !== null) {
            return $this->maxTokens;
        }

        $yamlMaxTokens = $this->metadata['max_tokens'] ?? null;
        if (is_int($yamlMaxTokens)) {
            return $yamlMaxTokens;
        }

        $configMaxTokens = config('prism-prompt.default_max_tokens', 4096);
        Assert::integer($configMaxTokens);

        return $configMaxTokens;
    }

    /**
     * Resolve temperature (class property > YAML > config)
     */
    protected function resolveTemperature(): float
    {
        if ($this->temperature !== null) {
            return $this->temperature;
        }

        $yamlTemperature = $this->metadata['temperature'] ?? null;
        if (is_numeric($yamlTemperature)) {
            return (float) $yamlTemperature;
        }

        $configTemperature = config('prism-prompt.default_temperature', 0.7);
        Assert::numeric($configTemperature);

        return (float) $configTemperature;
    }
}
