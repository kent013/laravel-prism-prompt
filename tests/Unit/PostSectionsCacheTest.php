<?php

declare(strict_types=1);

use Kent013\PrismPrompt\Exceptions\InvalidCacheBreakpointException;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Providers\Anthropic\MessagesRequestBuilder;
use Kent013\PrismPrompt\Values\CacheType;

/**
 * v0.18.0: post_sections + special cache breakpoint keys
 * (`__system` / `__prefix_end`) — additive, backward compatible.
 *
 * Request layout target (measured trial, spirux T940):
 *   tools → system[{text, cache_control}] → user content:
 *     [sections(non-cached), images(last = prefix end), post_sections(suffix)]
 */
class PostSectionsPrompt extends Prompt
{
    /** @param list<string> $imagePaths */
    public function __construct(
        public readonly string $topic = 'seo',
        public readonly string $axis = 'useful',
        private readonly array $imagePaths = [],
    ) {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/post_sections_test.yaml';
    }

    /** @return list<string> */
    public function getImagePaths(): array
    {
        return $this->imagePaths;
    }

    /** @return array<string, mixed> */
    protected function parseResponse(string $responseText): array
    {
        return ['raw' => $responseText];
    }
}

/** Legacy fixture without post_sections — backward-compat baseline. */
class LegacySectionsPrompt extends Prompt
{
    public function __construct(
        public readonly string $topic = 'seo',
        public readonly string $axis = 'useful',
    ) {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/sections_test.yaml';
    }

    /** @return array<string, mixed> */
    protected function parseResponse(string $responseText): array
    {
        return ['raw' => $responseText];
    }
}

function makeTempPng(): string
{
    $path = tempnam(sys_get_temp_dir(), 'prism-prompt-test-').'.png';
    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    file_put_contents($path, $png);

    return $path;
}

it('keeps legacy build() byte-identical when no post_sections / special keys are used', function (): void {
    $builder = new MessagesRequestBuilder('test-key');
    $prompt = (new LegacySectionsPrompt)->withCacheBreakpoints(['shared' => CacheType::Ephemeral]);

    $body = $builder->build($prompt)['body'];

    // system stays a plain string (not a block array)
    expect($body['system'])->toBeString();
    // content blocks: exactly the two sections, shared carrying its section breakpoint
    expect($body['messages'][0]['content'])->toBe([
        ['type' => 'text', 'text' => "Shared context: seo.\n", 'cache_control' => ['type' => 'ephemeral']],
        ['type' => 'text', 'text' => "Focus axis: useful.\n"],
    ]);
});

it('emits system as a cache_control block array when __system breakpoint is set', function (): void {
    $builder = new MessagesRequestBuilder('test-key');
    $prompt = (new PostSectionsPrompt)->withCacheBreakpoints([
        Prompt::CACHE_BREAKPOINT_SYSTEM => CacheType::Ephemeral,
    ]);

    $body = $builder->build($prompt)['body'];

    expect($body['system'])->toBe([[
        'type' => 'text',
        'text' => "You are a helpful assistant.\n",
        'cache_control' => ['type' => 'ephemeral'],
    ]]);
});

it('appends post_sections after images in declaration order, outside the cache prefix', function (): void {
    $png = makeTempPng();
    $builder = new MessagesRequestBuilder('test-key');
    $prompt = (new PostSectionsPrompt(topic: 'perf', axis: 'usable', imagePaths: [$png]))
        ->withCacheBreakpoints([Prompt::CACHE_BREAKPOINT_PREFIX_END => CacheType::Ephemeral]);

    $content = $builder->build($prompt)['body']['messages'][0]['content'];
    @unlink($png);

    // [shared section, image(prefix end), axis post, closing post]
    expect($content)->toHaveCount(4);
    expect($content[0]['type'])->toBe('text');
    expect($content[0]['text'])->toBe("Shared context: perf.\n");
    expect($content[0])->not->toHaveKey('cache_control');
    expect($content[1]['type'])->toBe('image');
    expect($content[1]['cache_control'])->toBe(['type' => 'ephemeral']);
    expect($content[2])->toBe(['type' => 'text', 'text' => "Focus axis: usable.\n"]);
    expect($content[3])->toBe(['type' => 'text', 'text' => "Answer for topic perf on axis usable.\n"]);
});

it('flags the last section block as prefix end when there are no images', function (): void {
    $builder = new MessagesRequestBuilder('test-key');
    $prompt = (new PostSectionsPrompt)->withCacheBreakpoints([
        Prompt::CACHE_BREAKPOINT_PREFIX_END => CacheType::Ephemeral,
    ]);

    $content = $builder->build($prompt)['body']['messages'][0]['content'];

    expect($content[0]['cache_control'])->toBe(['type' => 'ephemeral']);
    // post sections stay outside the cache prefix
    expect($content[1])->not->toHaveKey('cache_control');
    expect($content[2])->not->toHaveKey('cache_control');
});

it('throws when __prefix_end is set but the prompt has neither sections nor images', function (): void {
    $prompt = new class extends Prompt
    {
        protected function getTemplatePath(): string
        {
            return __DIR__.'/../fixtures/prompts/test.yaml';
        }

        /** @return array<string, mixed> */
        protected function parseResponse(string $responseText): array
        {
            return [];
        }
    };
    $prompt->withCacheBreakpoints([Prompt::CACHE_BREAKPOINT_PREFIX_END => CacheType::Ephemeral]);

    $builder = new MessagesRequestBuilder('test-key');

    expect(fn () => $builder->build($prompt))
        ->toThrow(InvalidCacheBreakpointException::class, '__prefix_end requires at least one section or image block');
});

it('rejects a cache breakpoint on a post_sections name with a dedicated message', function (): void {
    $prompt = new PostSectionsPrompt;

    expect(fn () => $prompt->withCacheBreakpoints(['closing' => CacheType::Ephemeral]))
        ->toThrow(
            InvalidCacheBreakpointException::class,
            "post section 'closing' cannot carry a cache breakpoint (suffix must stay outside the cache prefix)",
        );
});

it('still rejects unknown section names', function (): void {
    $prompt = new PostSectionsPrompt;

    expect(fn () => $prompt->withCacheBreakpoints(['nope' => CacheType::Ephemeral]))
        ->toThrow(InvalidCacheBreakpointException::class, "Section 'nope' is not defined");
});

it('renders post_sections through Blade with template variables', function (): void {
    $prompt = new PostSectionsPrompt(topic: 'a11y', axis: 'credible');

    expect($prompt->getRenderedPostSections())->toBe([
        'axis' => "Focus axis: credible.\n",
        'closing' => "Answer for topic a11y on axis credible.\n",
    ]);
});

it('returns empty post_sections for YAML without post_sections', function (): void {
    expect((new LegacySectionsPrompt)->getRenderedPostSections())->toBe([]);
});
