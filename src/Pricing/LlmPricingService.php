<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Pricing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * Computes USD cost from model name + token counts.
 *
 * Prices are resolved from the `prism-prompt-pricing.models.{provider}.{model}`
 * config path. Applications should publish `config/prism-prompt-pricing.php`
 * and keep it updated as vendor prices change.
 */
final readonly class LlmPricingService
{
    public function calculate(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?int $cacheWriteInputTokens = null,
        ?int $cacheReadInputTokens = null,
        ?int $thoughtTokens = null,
    ): CostCalculation {
        $pricing = $this->resolvePricing($provider, $model);

        $inputCost = $inputTokens * $pricing->inputPerMillion / 1_000_000;

        // Reasoning/thought tokens are billed at the output rate.
        $effectiveOutputTokens = $outputTokens + ($thoughtTokens ?? 0);
        $outputCost = $effectiveOutputTokens * $pricing->outputPerMillion / 1_000_000;

        $cacheWriteCost = null;
        if ($cacheWriteInputTokens !== null && $pricing->cacheWritePerMillion !== null) {
            $cacheWriteCost = $cacheWriteInputTokens * $pricing->cacheWritePerMillion / 1_000_000;
        }

        $cacheReadCost = null;
        if ($cacheReadInputTokens !== null && $pricing->cacheReadPerMillion !== null) {
            $cacheReadCost = $cacheReadInputTokens * $pricing->cacheReadPerMillion / 1_000_000;
        }

        $total = $inputCost + $outputCost + ($cacheWriteCost ?? 0) + ($cacheReadCost ?? 0);

        return new CostCalculation(
            inputCostUsd: $inputCost,
            outputCostUsd: $outputCost,
            cacheWriteCostUsd: $cacheWriteCost,
            cacheReadCostUsd: $cacheReadCost,
            totalCostUsd: $total,
            snapshot: $pricing,
        );
    }

    private function resolvePricing(string $provider, string $model): PricingSnapshot
    {
        $modelsConfig = config("prism-prompt-pricing.models.{$provider}.{$model}");

        if (! is_array($modelsConfig)) {
            $behavior = config('prism-prompt-pricing.unknown_model_behavior', 'zero');
            Assert::string($behavior);

            if ($behavior === 'throw') {
                throw new InvalidArgumentException(
                    "Unknown LLM model: {$provider}/{$model}. Add pricing to config/prism-prompt-pricing.php."
                );
            }

            $throttleKey = 'prism_prompt_unknown_model_'.$provider.'_'.$model.'_'.now()->toDateString();
            if (Cache::add($throttleKey, 1, now()->endOfDay())) {
                Log::warning('Unknown LLM model in pricing config, using zero fallback', [
                    'provider' => $provider,
                    'model' => $model,
                ]);
            }

            return new PricingSnapshot(
                inputPerMillion: 0.0,
                outputPerMillion: 0.0,
                cacheWritePerMillion: null,
                cacheReadPerMillion: null,
                unit: 'per_1m_tokens',
                currency: 'USD',
                source: "unknown_model:{$provider}/{$model}",
            );
        }

        Assert::keyExists($modelsConfig, 'input');
        Assert::keyExists($modelsConfig, 'output');
        Assert::numeric($modelsConfig['input']);
        Assert::numeric($modelsConfig['output']);
        Assert::greaterThanEq($modelsConfig['input'], 0);
        Assert::greaterThanEq($modelsConfig['output'], 0);

        $cacheWrite = null;
        if (isset($modelsConfig['cache_write'])) {
            Assert::numeric($modelsConfig['cache_write']);
            Assert::greaterThanEq($modelsConfig['cache_write'], 0);
            $cacheWrite = (float) $modelsConfig['cache_write'];
        }

        $cacheRead = null;
        if (isset($modelsConfig['cache_read'])) {
            Assert::numeric($modelsConfig['cache_read']);
            Assert::greaterThanEq($modelsConfig['cache_read'], 0);
            $cacheRead = (float) $modelsConfig['cache_read'];
        }

        $source = config('prism-prompt-pricing.pricing_source', 'unknown');
        Assert::string($source);

        return new PricingSnapshot(
            inputPerMillion: (float) $modelsConfig['input'],
            outputPerMillion: (float) $modelsConfig['output'],
            cacheWritePerMillion: $cacheWrite,
            cacheReadPerMillion: $cacheRead,
            unit: 'per_1m_tokens',
            currency: 'USD',
            source: $source,
        );
    }
}
