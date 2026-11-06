<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;
use Kent013\PrismPrompt\Exceptions\PromptRefusedException;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Values\CacheType;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

/**
 * v0.21.0: thinking effort (adaptive thinking) and refusal handling.
 */
class EffortTextPrompt extends Prompt
{
    public function __construct(
        public readonly string $userName = 'Alice',
        private readonly string $fixturePath = __DIR__.'/../fixtures/prompts/common/greeting.yaml',
        ?string $effort = null,
    ) {
        parent::__construct();
        $this->effort = $effort;
    }

    protected function getTemplatePath(): string
    {
        return $this->fixturePath;
    }

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }

    public function effortForTest(): ?string
    {
        return $this->resolveEffort();
    }
}

/**
 * @extends Prompt<array<string, mixed>>
 */
class EffortStructuredPrompt extends Prompt
{
    public function __construct(public readonly string $name = 'taro')
    {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/common/structured_test.yaml';
    }

    protected function getJsonSchema(): ?Schema
    {
        return new ObjectSchema(
            name: 'greeting',
            description: 'a greeting',
            properties: [new StringSchema('greeting', 'a greeting message')],
            requiredFields: ['greeting'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function parseStructured(array $data): array
    {
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseResponse(string $responseText): array
    {
        return $this->extractJson($responseText);
    }
}

class EffortSectionsPrompt extends Prompt
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

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }
}

function effortTextOk(): TextResponseFake
{
    return TextResponseFake::make()->withText('hello')->withUsage(new Usage(5, 10))->withFinishReason(FinishReason::Stop);
}

afterEach(function (): void {
    config()->set('prism-prompt.default_effort', null);
});

it('resolveEffort: class property > YAML > config > null', function (): void {
    $yaml = __DIR__.'/../fixtures/prompts/common/with-effort.yaml';
    $plain = __DIR__.'/../fixtures/prompts/common/greeting.yaml';

    config()->set('prism-prompt.default_effort', 'low');

    expect((new EffortTextPrompt(fixturePath: $yaml, effort: 'high'))->effortForTest())->toBe('high')
        ->and((new EffortTextPrompt(fixturePath: $yaml))->effortForTest())->toBe('medium')
        ->and((new EffortTextPrompt(fixturePath: $plain))->effortForTest())->toBe('low');

    config()->set('prism-prompt.default_effort', null);
    expect((new EffortTextPrompt(fixturePath: $plain))->effortForTest())->toBeNull();
});

it('resolveEffort treats an empty string as unset and rejects a non-string YAML value', function (): void {
    config()->set('prism-prompt.default_effort', '');
    expect((new EffortTextPrompt(effort: ''))->effortForTest())->toBeNull();

    $invalid = __DIR__.'/../fixtures/prompts/common/with-invalid-effort.yaml';
    expect(fn () => (new EffortTextPrompt(fixturePath: $invalid))->effortForTest())
        ->toThrow(InvalidArgumentException::class);
});

it('sends no thinking or effort provider options when effort is unset (request unchanged)', function (): void {
    $fake = Prism::fake([effortTextOk()]);

    (new EffortTextPrompt)->executeSync();

    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1)
            ->and($requests[0]->providerOptions('thinking'))->toBeNull()
            ->and($requests[0]->providerOptions('effort'))->toBeNull();
    });
});

it('sends adaptive thinking and the effort on the text path', function (): void {
    config()->set('prism-prompt.default_effort', 'low');
    $fake = Prism::fake([effortTextOk()]);

    (new EffortTextPrompt)->executeSync();

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->providerOptions('thinking'))->toBe(['type' => 'adaptive'])
            ->and($requests[0]->providerOptions('effort'))->toBe('low');
    });
});

it('sends adaptive thinking and the effort on the structured path', function (): void {
    config()->set('prism-prompt.default_effort', 'low');
    $fake = Prism::fake([
        StructuredResponseFake::make()->withStructured(['greeting' => 'hi'])->withUsage(new Usage(5, 10))->withFinishReason(FinishReason::Stop),
    ]);

    (new EffortStructuredPrompt)->executeSync();

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->providerOptions('thinking'))->toBe(['type' => 'adaptive'])
            ->and($requests[0]->providerOptions('effort'))->toBe('low');
    });
});

it('keeps the message-level cache option alongside the request-level effort', function (): void {
    config()->set('prism-prompt.default_effort', 'low');
    $fake = Prism::fake([effortTextOk()]);

    $prompt = new EffortSectionsPrompt;
    $prompt->withCacheBreakpoints(['shared' => CacheType::Ephemeral]);
    $prompt->executeSync();

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->providerOptions('effort'))->toBe('low')
            ->and($requests[0]->systemPrompts()[0]->providerOptions('cacheType'))->toBe('ephemeral');
    });
});

it('throws PromptRefusedException (a PrismException) and dispatches the failure event on a text refusal, even with text', function (): void {
    Event::fake([PromptExecutionCompleted::class, PromptExecutionFailed::class]);
    Prism::fake([
        TextResponseFake::make()->withText('I cannot help with that.')->withUsage(new Usage(5, 3))->withFinishReason(FinishReason::Refusal),
    ]);

    expect(fn () => (new EffortTextPrompt)->executeSync())
        ->toThrow(PromptRefusedException::class);

    expect(new PromptRefusedException(EffortTextPrompt::class))->toBeInstanceOf(PrismException::class);
    Event::assertDispatched(PromptExecutionFailed::class, fn (PromptExecutionFailed $e): bool => $e->exception instanceof PromptRefusedException);
    Event::assertNotDispatched(PromptExecutionCompleted::class);
});

it('throws PromptRefusedException on a structured refusal', function (): void {
    Prism::fake([
        StructuredResponseFake::make()->withStructured([])->withUsage(new Usage(5, 0))->withFinishReason(FinishReason::Refusal),
    ]);

    expect(fn () => (new EffortStructuredPrompt)->executeSync())
        ->toThrow(PromptRefusedException::class);
});
