<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kent013\PrismPrompt\Exceptions\PoolExecutionException;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\PromptPool;
use Kent013\PrismPrompt\Values\CacheType;

class PoolPrompt extends Prompt
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
     * @return array<string, string>
     */
    protected function parseResponse(string $responseText): array
    {
        return ['text' => $responseText];
    }
}

function fakeAnthropic(string $text = 'ok'): array
{
    return [
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ];
}

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'test-api-key');
    config()->set('prism-prompt.pool.concurrency', null);
});

it('returns single DTO when given a single prompt', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(fakeAnthropic('solo'), 200),
    ]);

    $results = PromptPool::executeWithWarmup([new PoolPrompt(axis: 'useful')]);

    expect($results)->toHaveCount(1);
    expect($results[0])->toBe(['text' => 'solo']);
});

it('executes warmup first then parallel for multiple prompts and preserves order', function (): void {
    $call = 0;
    Http::fake([
        'api.anthropic.com/v1/messages' => function () use (&$call) {
            $call++;

            return Http::response(fakeAnthropic("r{$call}"), 200);
        },
    ]);

    $prompts = [
        new PoolPrompt(axis: 'useful'),
        new PoolPrompt(axis: 'usable'),
        new PoolPrompt(axis: 'findable'),
    ];

    $results = PromptPool::executeWithWarmup($prompts);

    expect($results)->toHaveCount(3);
    // Warmup (index 0) fires alone; remaining two fire in parallel pool.
    expect($results[0])->toBe(['text' => 'r1']);
    // r2 and r3 correspond to pool chunk, order matches input order.
    expect($results[1]['text'])->toBeIn(['r2', 'r3']);
    expect($results[2]['text'])->toBeIn(['r2', 'r3']);
    expect($results[1]['text'])->not->toBe($results[2]['text']);
});

it('attaches cache_control=ephemeral blocks to the breakpoint sections', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(fakeAnthropic(), 200),
    ]);

    $prompt = (new PoolPrompt(topic: 'seo', axis: 'useful'))
        ->withCacheBreakpoints(['shared' => CacheType::Ephemeral]);

    PromptPool::executeWithWarmup([$prompt]);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        expect($body)->toBeArray();
        $blocks = $body['messages'][0]['content'];
        // Expect the shared section to carry cache_control
        $cached = array_values(array_filter($blocks, static fn ($b) => isset($b['cache_control'])));
        expect($cached)->toHaveCount(1);
        expect($cached[0]['cache_control'])->toBe(['type' => 'ephemeral']);
        expect($cached[0]['text'])->toContain('Shared context: seo.');
        // axis block should not carry cache_control
        $uncached = array_values(array_filter(
            $blocks,
            static fn ($b) => ! isset($b['cache_control']) && ($b['type'] ?? null) === 'text',
        ));
        expect($uncached)->toHaveCount(1);
        expect($uncached[0]['text'])->toContain('Focus axis: useful.');

        return true;
    });
});

it('sends x-api-key, anthropic-version headers and model in body', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(fakeAnthropic(), 200),
    ]);

    PromptPool::executeWithWarmup([new PoolPrompt]);

    Http::assertSent(function (Request $request): bool {
        expect($request->header('x-api-key'))->toBe(['test-api-key']);
        expect($request->header('anthropic-version'))->toBe(['2023-06-01']);
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        expect($body['model'])->toBe('claude-sonnet-4-5-20250929');
        expect($body['max_tokens'])->toBe(1024);

        return true;
    });
});

it('wraps HTTP 500 into PoolExecutionException with the failing index', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response('boom', 500),
    ]);

    try {
        PromptPool::executeWithWarmup([new PoolPrompt, new PoolPrompt]);
        fail('expected PoolExecutionException');
    } catch (PoolExecutionException $e) {
        // Warmup (index 0) is the first to fail in this stub.
        expect($e->getPromptIndex())->toBe(0);
        expect($e->getPrevious())->not->toBeNull();
    }
});

it('rejects concurrency arguments outside [1, count-1]', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(fakeAnthropic(), 200),
    ]);

    expect(fn () => PromptPool::executeWithWarmup(
        [new PoolPrompt, new PoolPrompt, new PoolPrompt],
        concurrency: 99,
    ))->toThrow(InvalidArgumentException::class);
});

it('caps concurrency via config when argument is omitted', function (): void {
    config()->set('prism-prompt.pool.concurrency', 1);

    $seen = 0;
    Http::fake([
        'api.anthropic.com/v1/messages' => function () use (&$seen) {
            $seen++;

            return Http::response(fakeAnthropic("r{$seen}"), 200);
        },
    ]);

    $prompts = [
        new PoolPrompt(axis: 'useful'),
        new PoolPrompt(axis: 'usable'),
        new PoolPrompt(axis: 'findable'),
        new PoolPrompt(axis: 'credible'),
    ];

    $results = PromptPool::executeWithWarmup($prompts);

    expect($results)->toHaveCount(4);
    // Config-capped concurrency still produces the same number of calls.
    expect($seen)->toBe(4);
});

it('throws when the input list is empty', function (): void {
    expect(fn () => PromptPool::executeWithWarmup([]))
        ->toThrow(InvalidArgumentException::class);
});

it('preserves global index when failure occurs in the second chunk', function (): void {
    // With concurrency=1, warmup = index 0, chunk-1 = index 1, chunk-2 = index 2.
    config()->set('prism-prompt.pool.concurrency', 1);

    $call = 0;
    Http::fake([
        'api.anthropic.com/v1/messages' => function () use (&$call) {
            $call++;
            // First two calls succeed (warmup + first chunk); third fails.
            if ($call >= 3) {
                return Http::response('boom', 500);
            }

            return Http::response(fakeAnthropic("r{$call}"), 200);
        },
    ]);

    try {
        PromptPool::executeWithWarmup([new PoolPrompt, new PoolPrompt, new PoolPrompt]);
        fail('expected PoolExecutionException');
    } catch (PoolExecutionException $e) {
        expect($e->getPromptIndex())->toBe(2);
    }
});

it('rejects invalid prism-prompt.pool.concurrency config values', function (): void {
    config()->set('prism-prompt.pool.concurrency', 0);
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(fakeAnthropic(), 200),
    ]);

    expect(fn () => PromptPool::executeWithWarmup([new PoolPrompt, new PoolPrompt]))
        ->toThrow(InvalidArgumentException::class, 'prism-prompt.pool.concurrency');
});

it('accepts numeric-string concurrency from .env config', function (): void {
    // env('PRISM_PROMPT_POOL_CONCURRENCY') returns strings; make sure the
    // happy-path config "2" is treated as int 2 rather than rejected.
    config()->set('prism-prompt.pool.concurrency', '2');

    $seen = 0;
    Http::fake([
        'api.anthropic.com/v1/messages' => function () use (&$seen) {
            $seen++;

            return Http::response(fakeAnthropic("r{$seen}"), 200);
        },
    ]);

    $results = PromptPool::executeWithWarmup([
        new PoolPrompt, new PoolPrompt, new PoolPrompt, new PoolPrompt,
    ]);

    expect($results)->toHaveCount(4);
});

it('rejects non-numeric-string concurrency config', function (): void {
    config()->set('prism-prompt.pool.concurrency', 'many');

    expect(fn () => PromptPool::executeWithWarmup([new PoolPrompt, new PoolPrompt]))
        ->toThrow(InvalidArgumentException::class, 'prism-prompt.pool.concurrency');
});

it('does not leak the Anthropic API key into PoolExecutionException messages', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response('internal error', 500),
    ]);

    try {
        PromptPool::executeWithWarmup([new PoolPrompt]);
        fail('expected PoolExecutionException');
    } catch (PoolExecutionException $e) {
        expect($e->getMessage())->not->toContain('test-api-key');
        $prev = $e->getPrevious();
        if ($prev !== null) {
            expect($prev->getMessage())->not->toContain('test-api-key');
        }
    }
});

it('accepts an injected MessagesRequestBuilder override', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(fakeAnthropic(), 200),
    ]);

    $builder = new Kent013\PrismPrompt\Providers\Anthropic\MessagesRequestBuilder(
        apiKey: 'tenant-specific-key',
    );

    PromptPool::executeWithWarmup([new PoolPrompt], builder: $builder);

    Http::assertSent(function (Request $request): bool {
        return $request->header('x-api-key') === ['tenant-specific-key'];
    });
});
