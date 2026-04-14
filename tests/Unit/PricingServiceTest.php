<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Pricing\LlmPricingService;
use Kent013\PrismPrompt\Pricing\PricingSnapshot;

beforeEach(function () {
    config([
        'prism-prompt-pricing.pricing_source' => 'test_source',
        'prism-prompt-pricing.unknown_model_behavior' => 'zero',
        'prism-prompt-pricing.models' => [
            'anthropic' => [
                'claude-test' => [
                    'input' => 10.00,
                    'output' => 50.00,
                    'cache_write' => 12.50,
                    'cache_read' => 1.00,
                ],
                'claude-no-cache' => [
                    'input' => 2.00,
                    'output' => 8.00,
                ],
            ],
        ],
    ]);
});

describe('LlmPricingService::calculate', function () {
    it('computes USD cost from per-million rates', function () {
        $cost = (new LlmPricingService)->calculate(
            provider: 'anthropic',
            model: 'claude-test',
            inputTokens: 1_000_000,
            outputTokens: 500_000,
        );

        expect($cost->inputCostUsd)->toBe(10.0);
        expect($cost->outputCostUsd)->toBe(25.0);
        expect($cost->totalCostUsd)->toBe(35.0);
        expect($cost->cacheWriteCostUsd)->toBeNull();
        expect($cost->cacheReadCostUsd)->toBeNull();
    });

    it('bills thought tokens at the output rate', function () {
        $cost = (new LlmPricingService)->calculate(
            provider: 'anthropic',
            model: 'claude-test',
            inputTokens: 0,
            outputTokens: 100_000,
            thoughtTokens: 400_000,
        );

        // (100k + 400k) * 50 / 1M = 25.0
        expect($cost->outputCostUsd)->toBe(25.0);
        expect($cost->totalCostUsd)->toBe(25.0);
    });

    it('computes cache write/read costs when configured', function () {
        $cost = (new LlmPricingService)->calculate(
            provider: 'anthropic',
            model: 'claude-test',
            inputTokens: 1_000_000,
            outputTokens: 0,
            cacheWriteInputTokens: 200_000,
            cacheReadInputTokens: 1_000_000,
        );

        expect($cost->cacheWriteCostUsd)->toBe(2.5);
        expect($cost->cacheReadCostUsd)->toBe(1.0);
        expect($cost->totalCostUsd)->toBe(13.5);
    });

    it('skips cache costs when model has no cache pricing', function () {
        $cost = (new LlmPricingService)->calculate(
            provider: 'anthropic',
            model: 'claude-no-cache',
            inputTokens: 1_000_000,
            outputTokens: 0,
            cacheWriteInputTokens: 100_000,
            cacheReadInputTokens: 100_000,
        );

        expect($cost->cacheWriteCostUsd)->toBeNull();
        expect($cost->cacheReadCostUsd)->toBeNull();
    });

    it('populates the PricingSnapshot from config', function () {
        $cost = (new LlmPricingService)->calculate(
            provider: 'anthropic',
            model: 'claude-test',
            inputTokens: 1,
            outputTokens: 1,
        );

        expect($cost->snapshot)->toBeInstanceOf(PricingSnapshot::class);
        expect($cost->snapshot->inputPerMillion)->toBe(10.0);
        expect($cost->snapshot->outputPerMillion)->toBe(50.0);
        expect($cost->snapshot->source)->toBe('test_source');
        expect($cost->snapshot->currency)->toBe('USD');
    });

    it('returns zero-cost snapshot for unknown model when behavior is zero', function () {
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $cost = (new LlmPricingService)->calculate(
            provider: 'anthropic',
            model: 'nonexistent-model',
            inputTokens: 100,
            outputTokens: 100,
        );

        expect($cost->totalCostUsd)->toBe(0.0);
        expect($cost->snapshot->source)->toBe('unknown_model:anthropic/nonexistent-model');
    });

    it('throws for unknown model when behavior is throw', function () {
        config(['prism-prompt-pricing.unknown_model_behavior' => 'throw']);

        expect(fn () => (new LlmPricingService)->calculate(
            provider: 'anthropic',
            model: 'nonexistent-model',
            inputTokens: 1,
            outputTokens: 1,
        ))->toThrow(InvalidArgumentException::class, 'Unknown LLM model');
    });
});

describe('PricingSnapshot', function () {
    it('round-trips through toArray / fromArray', function () {
        $original = new PricingSnapshot(
            inputPerMillion: 3.0,
            outputPerMillion: 15.0,
            cacheWritePerMillion: 3.75,
            cacheReadPerMillion: 0.30,
            unit: 'per_1m_tokens',
            currency: 'USD',
            source: 'test',
        );

        $rebuilt = PricingSnapshot::fromArray($original->toArray());

        expect($rebuilt->inputPerMillion)->toBe(3.0);
        expect($rebuilt->outputPerMillion)->toBe(15.0);
        expect($rebuilt->cacheWritePerMillion)->toBe(3.75);
        expect($rebuilt->cacheReadPerMillion)->toBe(0.30);
        expect($rebuilt->source)->toBe('test');
    });

    it('accepts null cache rates from array', function () {
        $snap = PricingSnapshot::fromArray([
            'input' => 1.0,
            'output' => 2.0,
            'unit' => 'per_1m_tokens',
            'currency' => 'USD',
            'source' => 'x',
        ]);

        expect($snap->cacheWritePerMillion)->toBeNull();
        expect($snap->cacheReadPerMillion)->toBeNull();
    });
});
