<?php

declare(strict_types=1);

namespace Because\PrismPrompt;

use Because\PrismPrompt\Contracts\PromptInterface;
use Because\PrismPrompt\Exceptions\InvalidJsonResponseException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use React\Promise\PromiseInterface;
use Symfony\Component\Yaml\Yaml;
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
    protected ?string $provider = null;

    protected ?string $model = null;

    protected ?int $maxTokens = null;

    protected ?float $temperature = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    public function __construct()
    {
        $this->loadMetadata();
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
     * Parse response text into DTO (implement in each Prompt class)
     *
     * @return TResponse
     */
    abstract protected function parseResponse(string $responseText): mixed;

    /**
     * Get the path to the metadata YAML file
     */
    abstract protected function getTemplatePath(): string;

    /**
     * Extract JSON from LLM response
     *
     * @return array<string, mixed>
     *
     * @throws InvalidJsonResponseException
     */
    protected function extractJson(string $content): array
    {
        // Try to extract ```json ... ``` block first
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            // Fallback to raw JSON object
            $json = $matches[0];
        } else {
            throw new InvalidJsonResponseException('No JSON found in response');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            Assert::isArray($data);

            /** @var array<string, mixed> $data */
            return $data;
        } catch (\JsonException $e) {
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
     * Render Blade template with object properties
     */
    protected function render(): string
    {
        $promptTemplate = $this->metadata['prompt'] ?? '';
        Assert::stringNotEmpty($promptTemplate, 'Prompt template is empty in metadata');

        // Use get_object_vars() to get public and protected properties (same as Mailable)
        return Blade::render($promptTemplate, get_object_vars($this));
    }

    /**
     * Load YAML metadata
     */
    protected function loadMetadata(): void
    {
        $metadataPath = $this->getTemplatePath();

        if (! file_exists($metadataPath)) {
            return;
        }

        $content = file_get_contents($metadataPath);
        Assert::string($content, "Failed to read metadata file: {$metadataPath}");

        $metadata = Yaml::parse($content);
        Assert::isArray($metadata, "Invalid YAML format in: {$metadataPath}");

        // Ensure it's an associative array
        if (array_keys($metadata) !== range(0, count($metadata) - 1)) {
            /** @var array<string, mixed> $metadata */
            $this->metadata = $metadata;
        } else {
            throw new \InvalidArgumentException("Metadata must be an associative array: {$metadataPath}");
        }
    }

    /**
     * Execute Prism LLM call
     */
    protected function executePrism(): string
    {
        $prompt = $this->render();

        $result = Prism::text()
            ->using($this->resolveProvider(), $this->resolveModel())
            ->withPrompt($prompt)
            ->withMaxTokens($this->resolveMaxTokens())
            ->asText();

        Assert::isInstanceOf($result, TextResponse::class);

        return $result->text;
    }

    /**
     * Resolve provider (class property > YAML > config)
     */
    protected function resolveProvider(): string
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $yamlProvider = $this->metadata['provider'] ?? null;
        if (is_string($yamlProvider)) {
            return $yamlProvider;
        }

        $configProvider = config('prism-prompt.default_provider', 'anthropic');
        Assert::string($configProvider);

        return $configProvider;
    }

    /**
     * Resolve model (class property > YAML > config)
     */
    protected function resolveModel(): string
    {
        if ($this->model !== null) {
            return $this->model;
        }

        $yamlModel = $this->metadata['model'] ?? null;
        if (is_string($yamlModel)) {
            return $yamlModel;
        }

        $configModel = config('prism-prompt.default_model', 'claude-sonnet-4-5-20250929');
        Assert::string($configModel);

        return $configModel;
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
