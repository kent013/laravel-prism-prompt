<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

/**
 * Prompt template value object
 *
 * Loads metadata and template from YAML files and holds them as immutable object.
 *
 * @phpstan-type VariableDefinition array{name: string, path?: string, type?: string, description?: string}|string
 * @phpstan-type MetaVariables array{fromScenario?: array<int, VariableDefinition>, runtime?: array<int, VariableDefinition>}
 * @phpstan-type MetaStructure array{scenarioType?: string, label?: string, features?: array<string, mixed>, tags?: array<int, string>, variables?: MetaVariables}
 *
 * @phpstan-consistent-constructor
 */
readonly class PromptTemplate
{
    /**
     * @param  MetaStructure  $meta
     */
    public function __construct(
        public string $name,
        public string $template,
        public array $meta,
        public string $provider,
        public string $model,
        public int $maxTokens,
        public float $temperature,
    ) {}

    /**
     * Create PromptTemplate from YAML file
     */
    public static function fromYaml(string $path): static
    {
        Assert::fileExists($path, "Prompt template file not found: {$path}");

        // Check cache
        if (self::isCacheEnabled()) {
            $cacheKey = self::getCacheKey($path);
            $cached = Cache::store(self::getCacheStore())->get($cacheKey);
            if ($cached instanceof static) {
                return $cached;
            }
        }

        $content = file_get_contents($path);
        Assert::string($content, "Failed to read prompt template file: {$path}");

        $data = Yaml::parse($content);
        Assert::isArray($data, "Invalid YAML format in: {$path}");

        Assert::keyExists($data, 'name', "Missing 'name' in prompt template: {$path}");
        Assert::keyExists($data, 'prompt', "Missing 'prompt' in prompt template: {$path}");

        $name = $data['name'];
        Assert::string($name);

        $template = $data['prompt'];
        Assert::string($template);

        /** @var MetaStructure $meta */
        $meta = $data['meta'] ?? [];

        $provider = $data['provider'] ?? config('prism-prompt.default_provider', 'anthropic');
        Assert::string($provider);

        $model = $data['model'] ?? config('prism-prompt.default_model', 'claude-sonnet-4-5-20250929');
        Assert::string($model);

        $maxTokens = $data['max_tokens'] ?? config('prism-prompt.default_max_tokens', 4096);
        Assert::integer($maxTokens);

        $temperature = $data['temperature'] ?? config('prism-prompt.default_temperature', 0.7);
        Assert::numeric($temperature);

        $instance = new static(
            name: $name,
            template: $template,
            meta: $meta,
            provider: $provider,
            model: $model,
            maxTokens: $maxTokens,
            temperature: (float) $temperature,
        );

        // Store in cache
        if (self::isCacheEnabled()) {
            $cacheKey = self::getCacheKey($path);
            $ttl = config('prism-prompt.cache.ttl', 3600);
            Assert::integer($ttl);
            Cache::store(self::getCacheStore())->put($cacheKey, $instance, $ttl);
        }

        return $instance;
    }

    /**
     * Get fromScenario variable definitions
     *
     * @return array<int, VariableDefinition>
     */
    public function getFromScenarioVariables(): array
    {
        return $this->meta['variables']['fromScenario'] ?? [];
    }

    /**
     * Get runtime variable definitions
     *
     * @return array<int, VariableDefinition>
     */
    public function getRuntimeVariables(): array
    {
        return $this->meta['variables']['runtime'] ?? [];
    }

    /**
     * Check if cache is enabled
     */
    private static function isCacheEnabled(): bool
    {
        return (bool) config('prism-prompt.cache.enabled', true);
    }

    /**
     * Get cache store name
     */
    private static function getCacheStore(): ?string
    {
        $store = config('prism-prompt.cache.store');

        return is_string($store) ? $store : null;
    }

    /**
     * Generate cache key for a template path
     */
    private static function getCacheKey(string $path): string
    {
        $pathHash = md5($path);
        // Use md5_file for content-based invalidation, fallback to mtime
        $contentHash = md5_file($path);
        if ($contentHash === false) {
            $contentHash = (string) filemtime($path);
        }

        return "prism_prompt:{$pathHash}:{$contentHash}";
    }
}
