<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Providers\Anthropic;

use Kent013\PrismPrompt\Exceptions\InvalidCacheBreakpointException;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\PromptPool;
use Kent013\PrismPrompt\Values\CacheType;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * Build an Anthropic Messages API request payload (url/headers/body) from a
 * {@see Prompt} instance, optionally flagging `cache_control: ephemeral`
 * blocks on sections that the caller declared via
 * {@see Prompt::withCacheBreakpoints()}.
 *
 * The same builder is used for both the warmup single-shot call and each
 * subsequent parallel call in {@see PromptPool} so that
 * the cache key hashes produced by the provider are byte-identical between
 * the two paths (required for cache hits to register).
 *
 * Provider-scoped: only Anthropic Messages API is supported in v0.11.0.
 */
final class MessagesRequestBuilder
{
    private const MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

    private const DEFAULT_TIMEOUT_SECONDS = 300;

    /**
     * Anthropic Messages API version. `cache_control` is GA on the 2023-06-01
     * API family and does not require the former `anthropic-beta:
     * prompt-caching-2024-07-31` header, so we keep the set of headers
     * minimal and callers don't need to opt into a beta.
     */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** @var list<string> */
    private const ALLOWED_IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * @param  ?string  $apiKey  Explicit Anthropic API key. When null the
     *                           builder reads `config('prism.providers.anthropic.api_key')`
     *                           at request-build time so that Prism's existing
     *                           integration keeps working out of the box.
     */
    public function __construct(
        private readonly ?string $apiKey = null,
    ) {}

    /**
     * @param  Prompt<mixed>  $prompt
     * @return array{
     *     url: string,
     *     headers: array<string, string>,
     *     body: array<string, mixed>,
     *     timeout: int,
     * }
     */
    public function build(Prompt $prompt): array
    {
        $model = $prompt->getModel();
        Assert::stringNotEmpty($model, 'prompt must resolve to a non-empty model');

        $contentBlocks = $this->buildContentBlocks($prompt);

        $body = [
            'model' => $model,
            'max_tokens' => $prompt->getMaxTokens(),
            'messages' => [[
                'role' => 'user',
                'content' => $contentBlocks,
            ]],
        ];

        $systemPrompt = $prompt->getRenderedSystemPrompt();
        if ($systemPrompt !== '') {
            // `__system` breakpoint: emit the system prompt as a text-block
            // array with cache_control so it joins the shared cache prefix.
            // Without the breakpoint the legacy string shape is kept so the
            // request body stays byte-identical (existing cache keys intact).
            $body['system'] = $this->hasEphemeralBreakpoint($prompt, Prompt::CACHE_BREAKPOINT_SYSTEM)
                ? [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]]
                : $systemPrompt;
        }

        // Forced tool-use (structured output) for prompts that opt in. When the
        // prompt returns null (default) no `tools`/`tool_choice` keys are added,
        // so the request body stays byte-identical to the pre-tool-use shape and
        // existing pool cache keys are unaffected.
        $toolConfig = $prompt->getPoolToolConfig();
        if ($toolConfig !== null) {
            Assert::keyExists($toolConfig, 'tools', 'pool tool config must define tools');
            Assert::keyExists($toolConfig, 'tool_choice', 'pool tool config must define tool_choice');
            Assert::isList($toolConfig['tools'], 'pool tools must be a list');
            Assert::isArray($toolConfig['tool_choice'], 'pool tool_choice must be an array');
            $body['tools'] = $toolConfig['tools'];
            $body['tool_choice'] = $toolConfig['tool_choice'];
        }

        return [
            'url' => self::MESSAGES_URL,
            'headers' => $this->buildHeaders(),
            'body' => $body,
            'timeout' => $this->resolveTimeout($prompt),
        ];
    }

    /**
     * @param  Prompt<mixed>  $prompt
     * @return list<array<string, mixed>>
     */
    private function buildContentBlocks(Prompt $prompt): array
    {
        $sections = $prompt->getRenderedSections();
        $breakpoints = $prompt->getCacheBreakpoints();
        $prefixEnd = $this->hasEphemeralBreakpoint($prompt, Prompt::CACHE_BREAKPOINT_PREFIX_END);

        // `__prefix_end` is only meaningful for prompts composed of sections
        // and/or images. A silent no-op (or flagging the fallback prompt body)
        // would hide a missing cache prefix, so fail fast instead.
        if ($prefixEnd && $sections === [] && $prompt->getImagePaths() === []) {
            throw new InvalidCacheBreakpointException(
                '__prefix_end requires at least one section or image block'
            );
        }

        if ($sections === []) {
            // Fall back to the rendered prompt body so that callers who
            // don't use sections still produce a valid request.
            $blocks = [[
                'type' => 'text',
                'text' => $this->renderFallbackUserText($prompt),
            ]];
        } else {
            $blocks = [];
            foreach ($sections as $name => $text) {
                $block = ['type' => 'text', 'text' => $text];
                if (($breakpoints[$name] ?? null) === CacheType::Ephemeral) {
                    $block['cache_control'] = ['type' => 'ephemeral'];
                }
                $blocks[] = $block;
            }
        }

        foreach ($prompt->getImagePaths() as $imagePath) {
            $blocks[] = $this->buildImageBlock($imagePath);
        }

        // `__prefix_end` breakpoint: flag the end of the shared prefix — the
        // last image block when images exist, otherwise the last regular
        // section block. Post sections are appended after this boundary so a
        // per-prompt suffix never fragments the shared cache prefix.
        if ($prefixEnd) {
            $blocks[count($blocks) - 1]['cache_control'] = ['type' => 'ephemeral'];
        }

        foreach ($prompt->getRenderedPostSections() as $text) {
            $blocks[] = ['type' => 'text', 'text' => $text]; // outside the cache prefix
        }

        return $blocks;
    }

    /** @param  Prompt<mixed>  $prompt */
    private function hasEphemeralBreakpoint(Prompt $prompt, string $key): bool
    {
        return ($prompt->getCacheBreakpoints()[$key] ?? null) === CacheType::Ephemeral;
    }

    /** @return array<string, string> */
    private function buildHeaders(): array
    {
        $apiKey = $this->resolveApiKey();

        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ];
    }

    private function resolveApiKey(): string
    {
        if ($this->apiKey !== null && $this->apiKey !== '') {
            return $this->apiKey;
        }

        $configured = config('prism.providers.anthropic.api_key');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        throw new RuntimeException(
            'Anthropic API key not configured. '
            .'Set config(prism.providers.anthropic.api_key) or pass a key to MessagesRequestBuilder::__construct().'
        );
    }

    /** @param  Prompt<mixed>  $prompt */
    private function resolveTimeout(Prompt $prompt): int
    {
        $options = $prompt->getClientOptions();
        $timeout = $options['timeout'] ?? null;
        if (is_int($timeout) && $timeout > 0) {
            return $timeout;
        }

        return self::DEFAULT_TIMEOUT_SECONDS;
    }

    /** @param  Prompt<mixed>  $prompt */
    private function renderFallbackUserText(Prompt $prompt): string
    {
        return $prompt->renderUserPromptForPool();
    }

    /** @return array<string, mixed> */
    private function buildImageBlock(string $imagePath): array
    {
        Assert::fileExists($imagePath, "image not found: {$imagePath}");
        $mime = mime_content_type($imagePath);
        Assert::string($mime, "unable to detect mime for {$imagePath}");
        Assert::inArray(
            $mime,
            self::ALLOWED_IMAGE_MIME,
            sprintf('unsupported image MIME %s for %s', $mime, $imagePath),
        );

        $contents = file_get_contents($imagePath);
        Assert::string($contents, "failed to read image {$imagePath}");

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mime,
                'data' => base64_encode($contents),
            ],
        ];
    }
}
