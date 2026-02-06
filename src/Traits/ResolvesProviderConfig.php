<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Traits;

use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

/**
 * Provider/model resolution and YAML metadata loading
 *
 * Shared by Prompt and EmbeddingPrompt.
 * Resolution priority: class property > YAML > config
 */
trait ResolvesProviderConfig
{
    protected ?string $provider = null;

    protected ?string $model = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    /** @var array<string, mixed> */
    protected array $providerConfig = [];

    protected string $templatePath = '';

    protected string $promptName = '';

    /**
     * Get the path to the metadata YAML file
     *
     * Resolution: templatePath (full) > promptName (relative) > naming convention (class name)
     */
    protected function getTemplatePath(): string
    {
        if ($this->templatePath !== '') {
            return $this->templatePath;
        }

        $name = $this->promptName !== '' ? $this->promptName : $this->derivePromptName();

        return $this->resolvePromptPath($name);
    }

    private function resolvePromptPath(string $name): string
    {
        return $this->getPromptsBasePath().'/'.$name.'.yaml';
    }

    /**
     * Get the base directory for prompt YAML files
     *
     * Override in subclasses to specify a custom directory.
     */
    protected function getPromptsBasePath(): string
    {
        $basePath = config('prism-prompt.prompts_path', resource_path('prompts'));
        Assert::string($basePath);

        return $basePath;
    }

    /**
     * Derive prompt name from class name (e.g. HintGenerationPrompt → hint_generation)
     */
    private function derivePromptName(): string
    {
        $shortName = (new ReflectionClass($this))->getShortName();
        $name = (string) preg_replace('/Prompt$/', '', $shortName);

        return Str::snake($name);
    }

    /**
     * Get the config key for the default provider
     */
    protected function getDefaultProviderConfigKey(): string
    {
        return 'prism-prompt.default_provider';
    }

    /**
     * Get the config key for the default model
     */
    protected function getDefaultModelConfigKey(): string
    {
        return 'prism-prompt.default_model';
    }

    /**
     * Get the fallback value for the default provider
     */
    protected function getDefaultProviderFallback(): string
    {
        return 'anthropic';
    }

    /**
     * Get the fallback value for the default model
     */
    protected function getDefaultModelFallback(): string
    {
        return 'claude-sonnet-4-5-20250929';
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

        if (array_keys($metadata) !== range(0, count($metadata) - 1)) {
            /** @var array<string, mixed> $metadata */
            $this->metadata = $metadata;
        } else {
            throw new InvalidArgumentException("Metadata must be an associative array: {$metadataPath}");
        }
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

        $configProvider = config($this->getDefaultProviderConfigKey(), $this->getDefaultProviderFallback());
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

        $configModel = config($this->getDefaultModelConfigKey(), $this->getDefaultModelFallback());
        Assert::string($configModel);

        return $configModel;
    }

    /**
     * Set custom API key
     */
    public function withApiKey(string $apiKey): static
    {
        $this->providerConfig['api_key'] = $apiKey;

        return $this;
    }

    /**
     * Add provider configuration
     *
     * @param  array<string, mixed>  $config
     */
    public function withProviderConfig(array $config): static
    {
        $this->providerConfig = array_merge($this->providerConfig, $config);

        return $this;
    }
}
