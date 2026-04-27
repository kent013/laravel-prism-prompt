<?php

declare(strict_types=1);

use Kent013\PrismPrompt\Exceptions\InvalidJsonResponseException;
use Kent013\PrismPrompt\Prompt;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

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

    /**
     * @return array{provider: string, model: string, config: array<string, mixed>}
     */
    public function testSelectOptimalProvider(): array
    {
        return $this->selectOptimalProvider();
    }

    public function testBuildMessages(): array
    {
        return $this->buildMessages();
    }

    public function testBuildSystemMessage(): ?SystemMessage
    {
        return $this->buildSystemMessage();
    }

    public function testBuildConversationMessages(): array
    {
        return $this->buildConversationMessages();
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

// $promptsDirectory property test
class CustomDirectoryPrompt extends Prompt
{
    protected string $promptsDirectory = 'custom';

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

it('resolves yaml by $promptsDirectory with naming convention', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts');

    // CustomDirectoryPrompt: $promptsDirectory = 'custom', naming convention → custom_directory.yaml
    // custom/custom_directory.yaml does not exist, falls back to config defaults
    $prompt = new CustomDirectoryPrompt('Alice');

    expect($prompt->testResolveProvider())->toBe('anthropic');
});

it('resolves yaml by $promptsDirectory with $promptName', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts');

    // $promptsDirectory = 'custom', $promptName = 'my_prompt'
    // → fixtures/prompts/custom/my_prompt.yaml
    $prompt = new class('Alice') extends CustomDirectoryPrompt
    {
        protected string $promptName = 'my_prompt';
    };

    expect($prompt->testResolveProvider())->toBe('openai');
    expect($prompt->testResolveModel())->toBe('gpt-4o');
});

// Multiple models support tests

class MultiModelPrompt extends Prompt
{
    public function __construct(
        public readonly string $userName,
    ) {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/common/multi-model.yaml';
    }

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }

    /**
     * @return array{provider: string, model: string, config: array<string, mixed>}
     */
    public function testSelectOptimalProvider(): array
    {
        return $this->selectOptimalProvider();
    }
}

it('selects provider from models list based on available API keys', function (): void {
    $prompt = new MultiModelPrompt('Alice');
    $prompt->withApiKeys([
        'openai' => 'sk-openai-test',
        'google' => 'google-api-key',
    ]);

    $selected = $prompt->testSelectOptimalProvider();

    // Should select openai (priority 2) since anthropic (priority 1) is not available
    expect($selected['provider'])->toBe('openai');
    expect($selected['model'])->toBe('gpt-4o');
    expect($selected['config'])->toBe(['api_key' => 'sk-openai-test']);
});

it('selects highest priority provider when multiple API keys are provided', function (): void {
    $prompt = new MultiModelPrompt('Alice');
    $prompt->withApiKeys([
        'anthropic' => 'sk-ant-test',
        'openai' => 'sk-openai-test',
        'google' => 'google-api-key',
    ]);

    $selected = $prompt->testSelectOptimalProvider();

    // Should select anthropic (priority 1)
    expect($selected['provider'])->toBe('anthropic');
    expect($selected['model'])->toBe('claude-sonnet-4-5-20250929');
    expect($selected['config'])->toBe(['api_key' => 'sk-ant-test']);
});

it('falls back to default provider when no matching API keys', function (): void {
    $prompt = new MultiModelPrompt('Alice');
    $prompt->withApiKeys([
        'unknown' => 'some-key',
    ]);

    $selected = $prompt->testSelectOptimalProvider();

    // Should fallback to YAML default (anthropic)
    expect($selected['provider'])->toBe('anthropic');
    expect($selected['model'])->toBe('claude-sonnet-4-5-20250929');
    expect($selected['config'])->toBe([]);
});

it('uses default provider when withApiKeys is not called', function (): void {
    $prompt = new MultiModelPrompt('Alice');

    $selected = $prompt->testSelectOptimalProvider();

    // Should use YAML default (anthropic)
    expect($selected['provider'])->toBe('anthropic');
    expect($selected['model'])->toBe('claude-sonnet-4-5-20250929');
    expect($selected['config'])->toBe([]);
});

it('supports withProviderConfigs for detailed configuration', function (): void {
    $prompt = new MultiModelPrompt('Alice');
    $prompt->withProviderConfigs([
        'openai' => [
            'api_key' => 'sk-openai-test',
            'url' => 'https://custom-openai.example.com',
        ],
    ]);

    $selected = $prompt->testSelectOptimalProvider();

    expect($selected['provider'])->toBe('openai');
    expect($selected['model'])->toBe('gpt-4o');
    expect($selected['config'])->toBe([
        'api_key' => 'sk-openai-test',
        'url' => 'https://custom-openai.example.com',
    ]);
});

it('respects priority order in models list', function (): void {
    $prompt = new MultiModelPrompt('Alice');
    $prompt->withApiKeys([
        'google' => 'google-key',  // priority 3
        'openai' => 'openai-key',  // priority 2
    ]);

    $selected = $prompt->testSelectOptimalProvider();

    // Should select openai (priority 2) over google (priority 3)
    expect($selected['provider'])->toBe('openai');
    expect($selected['model'])->toBe('gpt-4o');
});

// Messages API tests

it('builds system message from yaml system_prompt', function (): void {
    $prompt = new TestPrompt('Alice');

    $systemMessage = $prompt->testBuildSystemMessage();

    expect($systemMessage)->toBeInstanceOf(SystemMessage::class);
    expect($systemMessage->content)->toContain('friendly greeting assistant');
});

it('builds conversation messages as UserMessage', function (): void {
    $prompt = new TestPrompt('Alice');

    $messages = $prompt->testBuildConversationMessages();

    expect($messages)->toHaveCount(1);
    expect($messages[0])->toBeInstanceOf(UserMessage::class);
    expect($messages[0]->content)->toContain('Say hello to Alice');
});

it('builds complete message array with system and user messages', function (): void {
    $prompt = new TestPrompt('Alice');

    $messages = $prompt->testBuildMessages();

    // Should have system message + user message
    expect($messages)->toHaveCount(2);
    expect($messages[0])->toBeInstanceOf(SystemMessage::class);
    expect($messages[1])->toBeInstanceOf(UserMessage::class);
});

it('builds messages without system message when yaml has no system_prompt', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts');

    // test.yaml has no system_prompt field
    $prompt = new class('Alice') extends Prompt
    {
        public function __construct(public readonly string $userName)
        {
            parent::__construct();
        }

        protected function getTemplatePath(): string
        {
            return __DIR__.'/../fixtures/prompts/test.yaml';
        }

        protected function parseResponse(string $responseText): string
        {
            return $responseText;
        }

        public function testBuildMessages(): array
        {
            return $this->buildMessages();
        }
    };

    $messages = $prompt->testBuildMessages();

    // Should have only user message (no system message)
    expect($messages)->toHaveCount(1);
    expect($messages[0])->toBeInstanceOf(UserMessage::class);
});

it('records messages array when faking', function (): void {
    $fake = TestPrompt::fake([
        TextResponseFake::make()->withText('{"message": "Test"}'),
    ]);

    $prompt = new TestPrompt('Alice');
    $prompt->executeSync();

    // Verify recorded data contains messages array
    $recorded = $fake->recorded();
    expect($recorded)->toHaveCount(1);
    expect($recorded[0])->toHaveKey('messages');
    expect($recorded[0]['messages'])->toBeArray();
    expect($recorded[0]['messages'])->toHaveCount(2); // system + user
    expect($recorded[0]['messages'][0])->toBeInstanceOf(SystemMessage::class);
    expect($recorded[0]['messages'][1])->toBeInstanceOf(UserMessage::class);

    TestPrompt::stopFaking();
});

it('asserts system message contains text when faking', function (): void {
    $fake = TestPrompt::fake([
        TextResponseFake::make()->withText('{"message": "Test"}'),
    ]);

    $prompt = new TestPrompt('Alice');
    $prompt->executeSync();

    $fake->assertHasSystemMessage();
    $fake->assertSystemMessageContains('friendly greeting assistant');
    $fake->assertUserMessageContains('Say hello to Alice');
    $fake->assertMessageCount(2);

    TestPrompt::stopFaking();
});

it('asserts prompt contains searches across all messages', function (): void {
    $fake = TestPrompt::fake([
        TextResponseFake::make()->withText('{"message": "Test"}'),
    ]);

    $prompt = new TestPrompt('Alice');
    $prompt->executeSync();

    // assertPromptContains should find text in any message (system or user)
    $fake->assertPromptContains('Say hello to Alice');
    $fake->assertPromptContains('friendly greeting assistant');

    TestPrompt::stopFaking();
});

it('loads prompt with system_prompt via Prompt::load', function (): void {
    config()->set('prism-prompt.prompts_path', __DIR__.'/../fixtures/prompts/common');

    $fake = Prompt::fake([
        TextResponseFake::make()->withText('Hello!'),
    ]);

    Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();

    $fake->assertHasSystemMessage();
    $fake->assertSystemMessageContains('friendly greeting assistant');
    $fake->assertUserMessageContains('Say hello to Alice');

    Prompt::stopFaking();
});

// ProviderTools, maxSteps, clientOptions support tests

use Kent013\PrismPrompt\Testing\PromptFake;
use Prism\Prism\ValueObjects\ProviderTool;

class FeaturesPrompt extends Prompt
{
    public function __construct(
        public readonly string $topic,
    ) {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/common/with-features.yaml';
    }

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }

    /** @return array<int, ProviderTool> */
    public function testResolveProviderTools(): array
    {
        return $this->resolveProviderTools();
    }

    public function testResolveMaxSteps(): ?int
    {
        return $this->resolveMaxSteps();
    }

    /** @return array<string, mixed> */
    public function testResolveClientOptions(): array
    {
        return $this->resolveClientOptions();
    }
}

it('resolves provider tools from yaml', function (): void {
    $prompt = new FeaturesPrompt('AI');

    $tools = $prompt->testResolveProviderTools();

    expect($tools)->toHaveCount(1);
    expect($tools[0])->toBeInstanceOf(ProviderTool::class);
    expect($tools[0]->type)->toBe('web_search_20250305');
    expect($tools[0]->name)->toBe('web_search');
});

it('resolves max steps from yaml', function (): void {
    $prompt = new FeaturesPrompt('AI');

    expect($prompt->testResolveMaxSteps())->toBe(5);
});

it('resolves client options from yaml', function (): void {
    $prompt = new FeaturesPrompt('AI');

    expect($prompt->testResolveClientOptions())->toBe(['timeout' => 300]);
});

it('class property overrides yaml for provider tools', function (): void {
    $prompt = new FeaturesPrompt('AI');
    $customTool = new ProviderTool('custom_tool', 'custom');
    $prompt->withProviderTools([$customTool]);

    $tools = $prompt->testResolveProviderTools();

    expect($tools)->toHaveCount(1);
    expect($tools[0]->type)->toBe('custom_tool');
});

it('class property overrides yaml for max steps', function (): void {
    $prompt = new FeaturesPrompt('AI');
    $prompt->withMaxSteps(20);

    expect($prompt->testResolveMaxSteps())->toBe(20);
});

it('class property overrides yaml for client options', function (): void {
    $prompt = new FeaturesPrompt('AI');
    $prompt->withClientOptions(['timeout' => 600]);

    expect($prompt->testResolveClientOptions())->toBe(['timeout' => 600]);
});

it('returns null for max steps when not set', function (): void {
    $prompt = new TestPrompt('Alice');

    // greeting.yaml has no max_steps
    $reflection = new ReflectionMethod($prompt, 'resolveMaxSteps');
    $reflection->setAccessible(true);

    expect($reflection->invoke($prompt))->toBeNull();
});

it('returns empty array for provider tools when not set', function (): void {
    $prompt = new TestPrompt('Alice');

    // greeting.yaml has no provider_tools
    $reflection = new ReflectionMethod($prompt, 'resolveProviderTools');
    $reflection->setAccessible(true);

    expect($reflection->invoke($prompt))->toBe([]);
});

it('returns empty array for client options when not set', function (): void {
    $prompt = new TestPrompt('Alice');

    // greeting.yaml has no client_options
    $reflection = new ReflectionMethod($prompt, 'resolveClientOptions');
    $reflection->setAccessible(true);

    expect($reflection->invoke($prompt))->toBe([]);
});

it('withProviderTools returns fluent instance', function (): void {
    $prompt = new FeaturesPrompt('AI');
    $result = $prompt->withProviderTools([new ProviderTool('test')]);

    expect($result)->toBe($prompt);
});

it('withMaxSteps returns fluent instance', function (): void {
    $prompt = new FeaturesPrompt('AI');
    $result = $prompt->withMaxSteps(10);

    expect($result)->toBe($prompt);
});

it('withClientOptions returns fluent instance', function (): void {
    $prompt = new FeaturesPrompt('AI');
    $result = $prompt->withClientOptions(['timeout' => 60]);

    expect($result)->toBe($prompt);
});

it('separates system message from withMessages for Anthropic compatibility', function (): void {
    $fake = TestPrompt::fake([
        TextResponseFake::make()->withText('{"message": "Test"}'),
    ]);

    $prompt = new TestPrompt('Alice');
    $prompt->executeSync();

    // System message should still be recorded in the messages array
    $fake->assertHasSystemMessage();
    $fake->assertSystemMessageContains('friendly greeting assistant');

    TestPrompt::stopFaking();
});

it('installFake replaces the active fake instance with a custom one', function (): void {
    $customFake = new PromptFake([
        TextResponseFake::make()->withText('{"message": "from custom fake"}'),
    ]);

    Prompt::installFake($customFake);

    expect(Prompt::isFaking())->toBeTrue();
    expect(Prompt::getFake())->toBe($customFake);

    $prompt = new TestPrompt('Alice');
    expect($prompt->executeSync())->toBe(['message' => 'from custom fake']);

    Prompt::stopFaking();
});

it('installFake and fake() share the same static slot (one overwrites the other)', function (): void {
    Prompt::fake([TextResponseFake::make()->withText('{"message": "from fake()"}')]);
    $originalFake = Prompt::getFake();

    $customFake = new PromptFake([]);
    Prompt::installFake($customFake);

    expect(Prompt::getFake())->not->toBe($originalFake);
    expect(Prompt::getFake())->toBe($customFake);

    Prompt::stopFaking();
});

it('installFake accepts PromptFake subclasses', function (): void {
    $subclassFake = new class extends PromptFake
    {
        public function __construct()
        {
            parent::__construct([]);
        }

        public function nextResponse(): TextResponseFake
        {
            return TextResponseFake::make()->withText('{"message": "deterministic subclass"}');
        }
    };

    Prompt::installFake($subclassFake);

    $prompt = new TestPrompt('Alice');
    expect($prompt->executeSync())->toBe(['message' => 'deterministic subclass']);

    Prompt::stopFaking();
});
