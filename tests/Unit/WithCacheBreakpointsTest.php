<?php

declare(strict_types=1);

use Kent013\PrismPrompt\Exceptions\InvalidCacheBreakpointException;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Values\CacheType;

class SectionsPrompt extends Prompt
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

    /**
     * @return array<string, mixed>
     */
    protected function parseResponse(string $responseText): array
    {
        return ['raw' => $responseText];
    }
}

it('stores cache breakpoints for known sections', function (): void {
    $prompt = new SectionsPrompt;
    $prompt->withCacheBreakpoints(['shared' => CacheType::Ephemeral]);

    expect($prompt->getCacheBreakpoints())->toBe(['shared' => CacheType::Ephemeral]);
});

it('throws when breakpoint section is not declared in YAML', function (): void {
    $prompt = new SectionsPrompt;

    expect(fn () => $prompt->withCacheBreakpoints(['nope' => CacheType::Ephemeral]))
        ->toThrow(InvalidCacheBreakpointException::class, "Section 'nope' is not defined");
});

it('renders each section through Blade using template variables', function (): void {
    $prompt = new SectionsPrompt(topic: 'accessibility', axis: 'credible');

    $sections = $prompt->getRenderedSections();

    expect($sections)->toHaveKeys(['shared', 'axis']);
    expect($sections['shared'])->toContain('Shared context: accessibility.');
    expect($sections['axis'])->toContain('Focus axis: credible.');
});

it('returns empty array when YAML has no sections', function (): void {
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

    expect($prompt->getRenderedSections())->toBe([]);
    expect($prompt->getCacheBreakpoints())->toBe([]);
});

it('returns rendered system prompt as a definite string', function (): void {
    $prompt = new SectionsPrompt;

    expect($prompt->getRenderedSystemPrompt())->toContain('You are a helpful assistant.');
});

it('exposes resolved model and max_tokens via public accessors', function (): void {
    $prompt = new SectionsPrompt;

    expect($prompt->getModel())->toBe('claude-sonnet-4-5-20250929');
    expect($prompt->getMaxTokens())->toBe(1024);
});

it('exposes client options via getClientOptions()', function (): void {
    $prompt = (new SectionsPrompt)->withClientOptions(['timeout' => 60]);

    expect($prompt->getClientOptions())->toBe(['timeout' => 60]);
});

it('returns empty list for getImagePaths() by default', function (): void {
    $prompt = new SectionsPrompt;

    expect($prompt->getImagePaths())->toBe([]);
});
