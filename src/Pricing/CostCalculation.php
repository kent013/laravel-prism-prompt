<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Pricing;

/**
 * USD cost breakdown computed from token usage + a PricingSnapshot.
 *
 * Totals are in USD; downstream consumers that need another currency
 * (e.g. JPY for accounting) should convert using their own FX source.
 */
final readonly class CostCalculation
{
    public function __construct(
        public float $inputCostUsd,
        public float $outputCostUsd,
        public ?float $cacheWriteCostUsd,
        public ?float $cacheReadCostUsd,
        public float $totalCostUsd,
        public PricingSnapshot $snapshot,
    ) {}
}
