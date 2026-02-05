<?php

declare(strict_types=1);

use Because\PrismPrompt\Exceptions\InvalidJsonResponseException;
use Because\PrismPrompt\Prompt;

// Create a concrete test implementation
class TestPrompt extends Prompt
{
    public function __construct(
        public readonly string $userName,
    ) {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/common/greeting.yaml';
    }

    protected function parseResponse(string $responseText): array
    {
        return $this->extractJson($responseText);
    }

    // Expose protected methods for testing
    public function testRender(): string
    {
        return $this->render();
    }

    public function testExtractJson(string $content): array
    {
        return $this->extractJson($content);
    }

    public function testResolveProvider(): string
    {
        return $this->resolveProvider();
    }

    public function testResolveModel(): string
    {
        return $this->resolveModel();
    }

    public function testResolveMaxTokens(): int
    {
        return $this->resolveMaxTokens();
    }
}

it('renders blade template with object properties', function (): void {
    $prompt = new TestPrompt('Alice');

    $rendered = $prompt->testRender();

    expect($rendered)->toContain('Say hello to Alice');
});

it('extracts json from response with code block', function (): void {
    $prompt = new TestPrompt('Alice');

    $response = <<<'TEXT'
Sure, here's the response:
```json
{"message": "Hello Alice!", "tone": "friendly"}
```
TEXT;

    $data = $prompt->testExtractJson($response);

    expect($data)->toBe([
        'message' => 'Hello Alice!',
        'tone' => 'friendly',
    ]);
});

it('extracts json from response without code block', function (): void {
    $prompt = new TestPrompt('Alice');

    $response = '{"message": "Hello Alice!", "tone": "friendly"}';

    $data = $prompt->testExtractJson($response);

    expect($data)->toBe([
        'message' => 'Hello Alice!',
        'tone' => 'friendly',
    ]);
});

it('throws exception when no json found', function (): void {
    $prompt = new TestPrompt('Alice');

    $prompt->testExtractJson('No JSON here');
})->throws(InvalidJsonResponseException::class, 'No JSON found');

it('throws exception for invalid json', function (): void {
    $prompt = new TestPrompt('Alice');

    $prompt->testExtractJson('{invalid json}');
})->throws(InvalidJsonResponseException::class, 'Failed to parse JSON');

it('resolves settings from yaml', function (): void {
    $prompt = new TestPrompt('Alice');

    expect($prompt->testResolveProvider())->toBe('anthropic');
    expect($prompt->testResolveModel())->toBe('claude-sonnet-4-5-20250929');
    expect($prompt->testResolveMaxTokens())->toBe(1024);
});
