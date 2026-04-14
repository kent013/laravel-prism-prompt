<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

function makeTextResponse(string $text = 'hi', int $promptTokens = 10, int $completionTokens = 20): TextResponse
{
    return new TextResponse(
        steps: new Collection([]),
        text: $text,
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(promptTokens: $promptTokens, completionTokens: $completionTokens),
        meta: new Meta(id: 'resp_123', model: 'fake-model'),
        messages: new Collection([]),
    );
}

describe('PromptExecutionCompleted', function () {
    it('exposes all constructor-supplied fields as public readonly', function () {
        $response = makeTextResponse('hello', 5, 7);

        $event = new PromptExecutionCompleted(
            executionId: 'exec-1',
            promptClass: 'App\\Prompts\\TestPrompt',
            promptTemplate: 'test',
            provider: 'openai',
            model: 'gpt-4o',
            finishReason: FinishReason::Stop,
            stepCount: 1,
            totalUsage: $response->usage,
            durationMs: 123.45,
            requestId: 'req_xyz',
            response: $response,
            metadata: ['evaluation_id' => 42],
        );

        expect($event->executionId)->toBe('exec-1');
        expect($event->promptClass)->toBe('App\\Prompts\\TestPrompt');
        expect($event->promptTemplate)->toBe('test');
        expect($event->provider)->toBe('openai');
        expect($event->model)->toBe('gpt-4o');
        expect($event->finishReason)->toBe(FinishReason::Stop);
        expect($event->stepCount)->toBe(1);
        expect($event->totalUsage->promptTokens)->toBe(5);
        expect($event->totalUsage->completionTokens)->toBe(7);
        expect($event->durationMs)->toBe(123.45);
        expect($event->requestId)->toBe('req_xyz');
        expect($event->response)->toBe($response);
        expect($event->metadata)->toBe(['evaluation_id' => 42]);
    });

    it('accepts nullable promptTemplate and requestId', function () {
        $response = makeTextResponse();

        $event = new PromptExecutionCompleted(
            executionId: 'exec-2',
            promptClass: 'X',
            promptTemplate: null,
            provider: 'p',
            model: 'm',
            finishReason: FinishReason::Stop,
            stepCount: 0,
            totalUsage: $response->usage,
            durationMs: 1.0,
            requestId: null,
            response: $response,
            metadata: [],
        );

        expect($event->promptTemplate)->toBeNull();
        expect($event->requestId)->toBeNull();
        expect($event->metadata)->toBe([]);
    });
});

describe('PromptExecutionFailed', function () {
    it('exposes exception and metadata alongside request context', function () {
        $exception = new RuntimeException('API error');

        $event = new PromptExecutionFailed(
            executionId: 'exec-3',
            promptClass: 'App\\Prompts\\TestPrompt',
            promptTemplate: 'test',
            provider: 'openai',
            model: 'gpt-4o',
            durationMs: 50.0,
            exception: $exception,
            metadata: ['evaluation_id' => 42],
        );

        expect($event->executionId)->toBe('exec-3');
        expect($event->provider)->toBe('openai');
        expect($event->durationMs)->toBe(50.0);
        expect($event->exception)->toBe($exception);
        expect($event->exception->getMessage())->toBe('API error');
        expect($event->metadata)->toBe(['evaluation_id' => 42]);
    });

    it('accepts nullable promptTemplate', function () {
        $event = new PromptExecutionFailed(
            executionId: 'exec-4',
            promptClass: 'X',
            promptTemplate: null,
            provider: 'p',
            model: 'm',
            durationMs: 1.0,
            exception: new RuntimeException('fail'),
            metadata: [],
        );

        expect($event->promptTemplate)->toBeNull();
    });
});
