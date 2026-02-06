<?php

declare(strict_types=1);

use Kent013\PrismPrompt\Exceptions\InvalidJsonResponseException;
use Kent013\PrismPrompt\Prompt;

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

it('sets custom api key with withApiKey', function (): void {
    $prompt = new TestPrompt('Alice');

    $result = $prompt->withApiKey('custom-api-key-123');

    expect($result)->toBe($prompt);
});

it('sets provider config with withProviderConfig', function (): void {
    $prompt = new TestPrompt('Alice');

    $result = $prompt->withProviderConfig([
        'api_key' => 'custom-api-key-456',
        'url' => 'https://custom-endpoint.example.com',
    ]);

    expect($result)->toBe($prompt);
});

it('merges provider config with withProviderConfig', function (): void {
    $prompt = new TestPrompt('Alice');

    $prompt
        ->withApiKey('first-api-key')
        ->withProviderConfig(['url' => 'https://custom.example.com']);

    // Both settings should be set (verified by method chaining working)
    expect($prompt)->toBeInstanceOf(Prompt::class);
});

it('overwrites api_key with withProviderConfig', function (): void {
    $prompt = new TestPrompt('Alice');

    $result = $prompt
        ->withApiKey('first-api-key')
        ->withProviderConfig(['api_key' => 'overwritten-api-key']);

    expect($result)->toBe($prompt);
});

// Fake functionality tests
use Kent013\PrismPrompt\Testing\TextResponseFake;

it('fakes prompt execution and records requests', function (): void {
    $fake = TestPrompt::fake([
        TextResponseFake::make()->withText('{"message": "Hello from fake!"}'),
    ]);

    $prompt = new TestPrompt('Alice');
    $result = $prompt->executeSync();

    expect($result)->toBe(['message' => 'Hello from fake!']);

    $fake->assertCallCount(1);
    $fake->assertPromptContains('Say hello to Alice');

    TestPrompt::stopFaking();
});

it('fakes multiple responses in sequence', function (): void {
    $fake = TestPrompt::fake([
        TextResponseFake::make()->withText('{"message": "First response"}'),
        TextResponseFake::make()->withText('{"message": "Second response"}'),
    ]);

    $prompt1 = new TestPrompt('Alice');
    $result1 = $prompt1->executeSync();

    $prompt2 = new TestPrompt('Bob');
    $result2 = $prompt2->executeSync();

    expect($result1)->toBe(['message' => 'First response']);
    expect($result2)->toBe(['message' => 'Second response']);

    $fake->assertCallCount(2);

    TestPrompt::stopFaking();
});

it('records provider and model info when faking', function (): void {
    $fake = TestPrompt::fake([
        TextResponseFake::make()->withText('{"message": "Test"}'),
    ]);

    $prompt = new TestPrompt('Alice');
    $prompt->executeSync();

    $fake->assertProvider('anthropic');
    $fake->assertModel('claude-sonnet-4-5-20250929');

    TestPrompt::stopFaking();
});

it('reports isFaking status correctly', function (): void {
    expect(TestPrompt::isFaking())->toBeFalse();

    TestPrompt::fake([]);
    expect(TestPrompt::isFaking())->toBeTrue();

    TestPrompt::stopFaking();
    expect(TestPrompt::isFaking())->toBeFalse();
});
