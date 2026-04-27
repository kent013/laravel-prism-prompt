<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kent013\PrismPrompt\Exceptions\PoolExecutionException;
use Kent013\PrismPrompt\Providers\Anthropic\MessagesRequestBuilder;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * Execute a batch of {@see Prompt} instances against the Anthropic Messages
 * API with a warmup-first-then-parallel strategy:
 *
 *   1. The first prompt is sent by itself so that any `cache_control:
 *      ephemeral` breakpoints get written to the provider cache.
 *   2. The remaining prompts are fired in parallel chunks of up to
 *      `concurrency` requests at a time, so each of them reads the warm
 *      cache entry populated by step 1.
 *
 * Warmup and parallel paths use the same {@see MessagesRequestBuilder} so
 * the request bodies are byte-identical up to the content that differs per
 * prompt — a requirement for cache key hits to register at the provider.
 *
 * The class intentionally bypasses Prism's builder (retry, trace, etc.) so
 * HTTP failures bubble up to the caller's retry strategy (e.g. Laravel job
 * $tries). Domain mapping (index → axis / category / etc.) happens upstream
 * via {@see PoolExecutionException::getPromptIndex()}.
 */
final class PromptPool
{
    /**
     * Execute the prompt batch with warmup-then-parallel semantics.
     *
     * The optional `$builder` parameter lets callers inject a preconfigured
     * {@see MessagesRequestBuilder} (e.g. with a tenant-specific API key) or
     * a test double. When omitted the container default is resolved.
     *
     * @template TResponse
     *
     * @param  list<Prompt<TResponse>>  $prompts
     * @return list<TResponse>
     */
    public static function executeWithWarmup(
        array $prompts,
        ?int $concurrency = null,
        ?MessagesRequestBuilder $builder = null,
    ): array {
        Assert::notEmpty($prompts, 'executeWithWarmup requires at least one prompt');

        $builder ??= app(MessagesRequestBuilder::class);

        if (count($prompts) === 1) {
            return [self::executeSingle($prompts[0], $builder, 0)];
        }

        // count($prompts) - 1 is guaranteed >= 1 here because the single-prompt
        // shortcut above returned. Narrow for PHPStan.
        $maxConcurrency = count($prompts) - 1;
        if ($maxConcurrency < 1) {
            throw new RuntimeException('unreachable: prompt count must be >= 2 past the single-prompt guard');
        }
        $effectiveConcurrency = self::resolveConcurrency($concurrency, $maxConcurrency);

        // 1. warmup — use the same builder so the request body byte-matches
        //    the parallel path's first chunk.
        $results = [self::executeSingle($prompts[0], $builder, 0)];

        // 2. remaining prompts, fan out in chunks of $effectiveConcurrency.
        $remaining = array_slice($prompts, 1);
        $globalIndex = 1;
        foreach (array_chunk($remaining, $effectiveConcurrency) as $chunk) {
            /** @var list<Response> $responses */
            $responses = Http::pool(static function (Pool $pool) use ($chunk, $builder): array {
                $pooled = [];
                foreach ($chunk as $prompt) {
                    $req = $builder->build($prompt);
                    $pooled[] = $pool
                        ->withHeaders($req['headers'])
                        ->timeout($req['timeout'])
                        ->withBody(
                            (string) json_encode($req['body'], JSON_THROW_ON_ERROR),
                            'application/json',
                        )
                        ->post($req['url']);
                }

                return $pooled;
            });

            foreach ($responses as $i => $response) {
                $prompt = $chunk[$i];

                try {
                    self::assertSuccessful($response);
                    $results[] = self::parse($prompt, $response);
                } catch (Throwable $e) {
                    throw new PoolExecutionException($globalIndex, $e);
                }
                $globalIndex++;
            }
        }

        return $results;
    }

    /**
     * Warmup / single-shot common path. Failures are always wrapped in
     * PoolExecutionException so callers get a uniform exception type.
     *
     * @template TResponse
     *
     * @param  Prompt<TResponse>  $prompt
     * @return TResponse
     */
    private static function executeSingle(Prompt $prompt, MessagesRequestBuilder $builder, int $index): mixed
    {
        try {
            $req = $builder->build($prompt);
            $response = Http::withHeaders($req['headers'])
                ->timeout($req['timeout'])
                ->withBody(
                    (string) json_encode($req['body'], JSON_THROW_ON_ERROR),
                    'application/json',
                )
                ->post($req['url']);

            self::assertSuccessful($response);

            return self::parse($prompt, $response);
        } catch (Throwable $e) {
            throw new PoolExecutionException($index, $e);
        }
    }

    /**
     * Delegate to the Prompt's public hook so the caller's DTO contract is
     * honoured whether the prompt was executed via executeSync() or through
     * this pool.
     *
     * @template TResponse
     *
     * @param  Prompt<TResponse>  $prompt
     * @return TResponse
     */
    private static function parse(Prompt $prompt, Response $response): mixed
    {
        $text = $response->json('content.0.text');
        Assert::string($text, 'Anthropic response missing content[0].text');

        return $prompt->parseResponseForPool($text);
    }

    private static function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Anthropic HTTP %d: %s',
            $response->status(),
            (string) $response->body(),
        ));
    }

    /**
     * @param  int<1, max>  $max
     * @return int<1, max>
     *
     * @throws InvalidArgumentException
     */
    private static function resolveConcurrency(?int $requested, int $max): int
    {
        if ($requested !== null) {
            if ($requested < 1 || $requested > $max) {
                throw new InvalidArgumentException("concurrency must be in 1..{$max}, got {$requested}");
            }

            return $requested;
        }

        $configured = config('prism-prompt.pool.concurrency');
        if ($configured === null || $configured === '') {
            return $max;
        }

        // Accept both int and numeric strings so that the default config
        // entry `env('PRISM_PROMPT_POOL_CONCURRENCY')` (which returns
        // strings from .env) works out of the box, while still rejecting
        // obviously-wrong values like booleans, negatives, or non-numeric
        // strings.
        if (is_int($configured)) {
            $value = $configured;
        } elseif (is_string($configured) && ctype_digit($configured)) {
            $value = (int) $configured;
        } else {
            throw new InvalidArgumentException(
                'prism-prompt.pool.concurrency must be null or a positive integer, got: '
                .var_export($configured, true)
            );
        }

        if ($value < 1) {
            throw new InvalidArgumentException(
                'prism-prompt.pool.concurrency must be null or a positive integer, got: '
                .var_export($configured, true)
            );
        }

        return $value <= $max ? $value : $max;
    }
}
