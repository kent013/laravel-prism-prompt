<?php

declare(strict_types=1);

/**
 * LLM model pricing (per 1M tokens, USD).
 *
 * These defaults are shipped for convenience — publish with
 * `php artisan vendor:publish --tag=prism-prompt-pricing` and keep them
 * up-to-date in your application when vendor prices change.
 *
 * `pricing_source` is embedded into each PricingSnapshot so that historical
 * cost records remain auditable after the price table is updated.
 */
return [
    'pricing_source' => env('PRISM_PROMPT_PRICING_SOURCE', 'defaults_shipped'),

    'unknown_model_behavior' => env('PRISM_PROMPT_UNKNOWN_MODEL_BEHAVIOR', 'zero'),

    'models' => [
        'anthropic' => [
            'claude-opus-4-6' => [
                'input' => 15.00,
                'output' => 75.00,
                'cache_write' => 18.75,
                'cache_read' => 1.50,
            ],
            'claude-sonnet-4-6' => [
                'input' => 3.00,
                'output' => 15.00,
                'cache_write' => 3.75,
                'cache_read' => 0.30,
            ],
            // Sonnet 5.5 (2026-11-06 に提供元の models overview を実読)。cache_write は 5 分 TTL (1.25x)、
            // cache_read は基本の入力単価の 5% (Opus 5.5 / Sonnet 5.5 の規定)。引退する Sonnet 4.5 の後継 (T2188)。
            'claude-sonnet-5-5' => [
                'input' => 2.00,
                'output' => 10.00,
                'cache_write' => 2.50,
                'cache_read' => 0.10,
            ],
            'claude-haiku-4-5-20251001' => [
                'input' => 1.00,
                'output' => 5.00,
                'cache_write' => 1.25,
                'cache_read' => 0.10,
            ],
        ],
    ],
];
