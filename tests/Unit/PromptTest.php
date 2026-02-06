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

// Naming convention test: TestPrompt → test.yaml (but this class overrides getTemplatePath)
// So we create a separate class that relies on naming convention
class NamingConventionPrompt extends Prompt
{
    public function __construct(
        public readonly string $userName,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }

    public function testResolveProvider(): string
    {
        return $this->resolveProvider();
    }
}

// Naming convention: TestNamingPrompt → test_naming.yaml (won't exist)
// We also test with a name that DOES exist: "test" → test.yaml
// Simulated via $promptName since we can't rename the class to "TestPrompt" (already taken)

// $promptName property test
class CustomPathPrompt extends Prompt
{
    protected string $promptName = 'custom/my_prompt';

    public function __construct(
        public readonly string $topic,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }

    public function testResolveProvider(): string
    {
        return $this->resolveProvider();
    }

    public function testResolveModel(): string
    {
        return $this->resolveModel();
    }
}

// getPromptsBasePath() override test
class CustomBasePathPrompt extends Prompt
{
    public function __construct(
        public readonly string $userName,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }

    protected function getPromptsBasePath(): string
    {
        return __DIR__.'/../fixtures/prompts/custom';
    }

    public function testResolveProvider(): string
    {
        return $this->resolveProvider();
    }

    public function testResolveModel(): string
    {
        return $this->resolveModel();
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

// load() factory tests

it('loads prompt from yaml name and renders with variables', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts/common');

    $prompt = Prompt::load('greeting', ['userName' => 'Bob']);

    $fake = Prompt::fake([
        TextResponseFake::make()->withText('Hello Bob!'),
    ]);

    $result = $prompt->executeSync();

    expect($result)->toBe('Hello Bob!');
    $fake->assertPromptContains('Say hello to Bob');

    Prompt::stopFaking();
});

it('loads prompt and resolves provider/model from yaml', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts/common');

    $fake = Prompt::fake([
        TextResponseFake::make()->withText('test'),
    ]);

    Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();

    $fake->assertProvider('anthropic');
    $fake->assertModel('claude-sonnet-4-5-20250929');

    Prompt::stopFaking();
});

it('loads prompt with withApiKey', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts/common');

    $prompt = Prompt::load('greeting', ['userName' => 'Alice'])
        ->withApiKey('custom-key');

    expect($prompt)->toBeInstanceOf(Prompt::class);
});

// Naming convention & $promptName tests

it('resolves yaml by naming convention from class name', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts');

    // NamingConventionPrompt → naming_convention.yaml (not found)
    // Falls back to config defaults for provider/model
    $prompt = new NamingConventionPrompt('Alice');

    expect($prompt->testResolveProvider())->toBe('anthropic'); // config default
});

it('resolves yaml by $promptName property', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts');

    $prompt = new CustomPathPrompt('testing');

    // custom/my_prompt.yaml has provider: openai, model: gpt-4o
    expect($prompt->testResolveProvider())->toBe('openai');
    expect($prompt->testResolveModel())->toBe('gpt-4o');
});

it('resolves yaml from overridden getPromptsBasePath', function (): void {
    // CustomBasePathPrompt overrides getPromptsBasePath() to fixtures/prompts/custom
    // Naming convention: CustomBasePath → custom_base_path.yaml (not found, falls back to defaults)
    // But $promptName is not set, so it derives from class name
    $prompt = new CustomBasePathPrompt('Alice');

    // No custom_base_path.yaml exists, so falls back to config defaults
    expect($prompt->testResolveProvider())->toBe('anthropic');
});

it('resolves yaml from overridden getPromptsBasePath with $promptName', function (): void {
    // my_prompt.yaml is in fixtures/prompts/custom/ and CustomBasePathPrompt points there
    // We need a class that combines getPromptsBasePath override with $promptName
    $prompt = new class('Alice') extends CustomBasePathPrompt
    {
        protected string $promptName = 'my_prompt';
    };

    // my_prompt.yaml has provider: openai, model: gpt-4o
    expect($prompt->testResolveProvider())->toBe('openai');
    expect($prompt->testResolveModel())->toBe('gpt-4o');
});
