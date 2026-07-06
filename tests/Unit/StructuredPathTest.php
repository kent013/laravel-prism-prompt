<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;
use Kent013\PrismPrompt\Exceptions\InvalidJsonResponseException;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake as PromptTextResponseFake;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake as PrismTextResponseFake;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Usage;

afterEach(function () {
    // Prompt::fake() を使うケースで test 失敗時に static $fake が leak しないよう、
    // afterEach で defensive cleanup する。
    Prompt::stopFaking();
});

/**
 * @extends Prompt<array<string, mixed>>
 */
class StructuredTestPrompt extends Prompt
{
    public function __construct(public readonly string $name)
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
            name: 'user_info',
            description: 'a user info object',
            properties: [
                new StringSchema('greeting', 'a greeting message'),
                new NumberSchema(name: 'score', description: '0-1 score', minimum: 0.0, maximum: 1.0),
            ],
            requiredFields: ['greeting', 'score'],
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

/**
 * @extends Prompt<array<string, mixed>>
 */
class LegacyTestPrompt extends Prompt
{
    public function __construct(public readonly string $name)
    {
        parent::__construct();
    }

    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/common/structured_test.yaml';
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseResponse(string $responseText): array
    {
        return $this->extractJson($responseText);
    }
}

it('case 1: schema 宣言時 executeSync は Prism::structured() 経路を通る', function () {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['greeting' => 'hi taro', 'score' => 0.9])
            ->withUsage(new Usage(10, 20))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $result = (new StructuredTestPrompt('taro'))->executeSync();

    expect($result)->toBe(['greeting' => 'hi taro', 'score' => 0.9]);
});

it('case 2: schema 宣言時 execute() (async) も Prism::structured() 経路を通る', function () {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['greeting' => 'hi taro', 'score' => 0.9])
            ->withUsage(new Usage(10, 20))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $promise = (new StructuredTestPrompt('taro'))->execute();
    $result = \React\Async\await($promise);

    expect($result)->toBe(['greeting' => 'hi taro', 'score' => 0.9]);
});

it('case 3: schema 宣言時に PromptExecutionCompleted event が発火する', function () {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['greeting' => 'hi', 'score' => 0.5])
            ->withUsage(new Usage(5, 10))
            ->withFinishReason(FinishReason::Stop),
    ]);
    Event::fake([PromptExecutionCompleted::class]);

    (new StructuredTestPrompt('taro'))->executeSync();

    Event::assertDispatched(PromptExecutionCompleted::class, function (PromptExecutionCompleted $e): bool {
        return $e->promptClass === StructuredTestPrompt::class
            && $e->totalUsage->promptTokens === 5
            && $e->totalUsage->completionTokens === 10;
    });
});

it('case 4: schema 宣言時に Prism 例外発生で PromptExecutionFailed event が発火する', function () {
    // Prism::fake は queue が消費し切られると例外を投げる。1 回成功させた後の 2 回目で
    // structured 経路の executePrismStructured が catch (Throwable) → PromptExecutionFailed
    // を発火するパスを gate する。
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['greeting' => 'first', 'score' => 0.5])
            ->withUsage(new Usage(1, 1))
            ->withFinishReason(FinishReason::Stop),
    ]);
    Event::fake([PromptExecutionFailed::class]);

    (new StructuredTestPrompt('taro'))->executeSync(); // 1st call: consume response

    try {
        (new StructuredTestPrompt('taro'))->executeSync(); // 2nd call: queue exhausted → throw
    } catch (Throwable) {
        // expected
    }

    Event::assertDispatched(PromptExecutionFailed::class, function (PromptExecutionFailed $e): bool {
        return $e->promptClass === StructuredTestPrompt::class;
    });
});

it('case 5: schema 未宣言 (legacy) executeSync は Prism::text() 経路を通り parseResponse が呼ばれる', function () {
    Prism::fake([
        PrismTextResponseFake::make()
            ->withText('{"greeting":"hi","score":0.5}')
            ->withUsage(new Usage(3, 4))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $result = (new LegacyTestPrompt('taro'))->executeSync();

    expect($result)->toBe(['greeting' => 'hi', 'score' => 0.5]);
});

it('case 6: schema 未宣言 (legacy) execute() (async) も Prism::text() 経路を通る', function () {
    Prism::fake([
        PrismTextResponseFake::make()
            ->withText('{"greeting":"hi","score":0.5}')
            ->withUsage(new Usage(3, 4))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $promise = (new LegacyTestPrompt('taro'))->execute();
    $result = \React\Async\await($promise);

    expect($result)->toBe(['greeting' => 'hi', 'score' => 0.5]);
});

it('case 7: legacy 経路の event は response が TextResponse である', function () {
    Prism::fake([
        PrismTextResponseFake::make()
            ->withText('{}')
            ->withUsage(new Usage(1, 1))
            ->withFinishReason(FinishReason::Stop),
    ]);
    Event::fake([PromptExecutionCompleted::class]);

    (new LegacyTestPrompt('taro'))->executeSync();

    Event::assertDispatched(PromptExecutionCompleted::class, function (PromptExecutionCompleted $e): bool {
        return $e->response instanceof TextResponse;
    });
});

it('case 8: schema 経路の event は response が StructuredResponse である', function () {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['greeting' => 'hi', 'score' => 0.5])
            ->withUsage(new Usage(1, 1))
            ->withFinishReason(FinishReason::Stop),
    ]);
    Event::fake([PromptExecutionCompleted::class]);

    (new StructuredTestPrompt('taro'))->executeSync();

    Event::assertDispatched(PromptExecutionCompleted::class, function (PromptExecutionCompleted $e): bool {
        return $e->response instanceof StructuredResponse;
    });
});

it('case 9: schema 未宣言 (legacy) でも PromptExecutionCompleted event が発火する', function () {
    Prism::fake([
        PrismTextResponseFake::make()
            ->withText('{}')
            ->withUsage(new Usage(1, 1))
            ->withFinishReason(FinishReason::Stop),
    ]);
    Event::fake([PromptExecutionCompleted::class]);

    (new LegacyTestPrompt('taro'))->executeSync();

    Event::assertDispatched(PromptExecutionCompleted::class);
});

it('case 10: Prompt::fake 経路は schema 経路でも動作する (TextResponseFake を JSON decode)', function () {
    Prompt::fake([
        PromptTextResponseFake::make()
            ->withText('{"greeting":"hi","score":0.5}'),
    ]);

    $result = (new StructuredTestPrompt('taro'))->executeSync();

    expect($result)->toBe(['greeting' => 'hi', 'score' => 0.5]);
});

it('case 11: withMetadata は schema 経路で event に伝播する (4 key 全部)', function () {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['greeting' => 'a', 'score' => 0.1])
            ->withUsage(new Usage(1, 1))
            ->withFinishReason(FinishReason::Stop),
    ]);
    Event::fake([PromptExecutionCompleted::class]);

    (new StructuredTestPrompt('taro'))
        ->withMetadata([
            'organization_id' => 99,
            'subject_type' => 'X',
            'subject_id' => 7,
            'correlation_id' => 'cid-1',
        ])
        ->executeSync();

    Event::assertDispatched(PromptExecutionCompleted::class, function (PromptExecutionCompleted $e): bool {
        return ($e->metadata['organization_id'] ?? null) === 99
            && ($e->metadata['subject_type'] ?? null) === 'X'
            && ($e->metadata['subject_id'] ?? null) === 7
            && ($e->metadata['correlation_id'] ?? null) === 'cid-1';
    });
});

it('case 12: withMetadata は legacy 経路でも event に伝播する (4 key 全部)', function () {
    Prism::fake([
        PrismTextResponseFake::make()
            ->withText('{}')
            ->withUsage(new Usage(1, 1))
            ->withFinishReason(FinishReason::Stop),
    ]);
    Event::fake([PromptExecutionCompleted::class]);

    (new LegacyTestPrompt('taro'))
        ->withMetadata([
            'organization_id' => 99,
            'subject_type' => 'X',
            'subject_id' => 7,
            'correlation_id' => 'cid-1',
        ])
        ->executeSync();

    Event::assertDispatched(PromptExecutionCompleted::class, function (PromptExecutionCompleted $e): bool {
        return ($e->metadata['organization_id'] ?? null) === 99
            && ($e->metadata['subject_type'] ?? null) === 'X'
            && ($e->metadata['subject_id'] ?? null) === 7
            && ($e->metadata['correlation_id'] ?? null) === 'cid-1';
    });
});

it('case 13: Prompt::fake が空文字を返した場合 InvalidJsonResponseException', function () {
    Prompt::fake([
        PromptTextResponseFake::make()->withText(''),
    ]);

    expect(fn () => (new StructuredTestPrompt('taro'))->executeSync())
        ->toThrow(InvalidJsonResponseException::class, 'empty text');
});

it('case 14: Prompt::fake が list array を返した場合 InvalidJsonResponseException', function () {
    Prompt::fake([
        PromptTextResponseFake::make()->withText('["not-an-object"]'),
    ]);

    expect(fn () => (new StructuredTestPrompt('taro'))->executeSync())
        ->toThrow(InvalidJsonResponseException::class, 'list array');
});

it('case 15: Prompt::fake が JSON string scalar を返した場合 Assert 失敗 (object 期待契約)', function () {
    Prompt::fake([
        PromptTextResponseFake::make()->withText('"hello"'),
    ]);

    expect(fn () => (new StructuredTestPrompt('taro'))->executeSync())
        ->toThrow(InvalidJsonResponseException::class, 'non-object JSON value');
});

it('case 16: Prompt::fake が JSON int scalar を返した場合 Assert 失敗 (object 期待契約)', function () {
    Prompt::fake([
        PromptTextResponseFake::make()->withText('42'),
    ]);

    expect(fn () => (new StructuredTestPrompt('taro'))->executeSync())
        ->toThrow(InvalidJsonResponseException::class, 'non-object JSON value');
});

it('custom tools are mirrored onto the Prism structured request (withTools)', function () {
    $fake = Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['greeting' => 'hi taro', 'score' => 0.9])
            ->withUsage(new Usage(10, 20))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $tool = (new Tool)
        ->as('fixture_search')
        ->for('search fixtures')
        ->withStringParameter('query', 'search query')
        ->using(fn (string $query): string => 'result');

    $prompt = new StructuredTestPrompt('taro');
    $prompt->withTools([$tool]);
    $prompt->executeSync();

    $fake->assertRequest(function (array $requests) use ($tool): void {
        expect($requests)->toHaveCount(1);
        expect($requests[0]->tools())->toBe([$tool]);
    });
});
