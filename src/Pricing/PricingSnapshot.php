<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Pricing;

use Illuminate\Contracts\Support\Arrayable;
use Webmozart\Assert\Assert;

/**
 * Immutable snapshot of per-model LLM pricing at the time of a call.
 *
 * Stored alongside cost records so that later price-table changes do not
 * retroactively alter historical cost figures.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PricingSnapshot implements Arrayable
{
    public function __construct(
        public float $inputPerMillion,
        public float $outputPerMillion,
        public ?float $cacheWritePerMillion,
        public ?float $cacheReadPerMillion,
        public string $unit,
        public string $currency,
        public string $source,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'input' => $this->inputPerMillion,
            'output' => $this->outputPerMillion,
            'cache_write' => $this->cacheWritePerMillion,
            'cache_read' => $this->cacheReadPerMillion,
            'unit' => $this->unit,
            'currency' => $this->currency,
            'source' => $this->source,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Assert::keyExists($data, 'input');
        Assert::keyExists($data, 'output');
        Assert::keyExists($data, 'unit');
        Assert::keyExists($data, 'currency');
        Assert::keyExists($data, 'source');
        Assert::numeric($data['input']);
        Assert::numeric($data['output']);
        Assert::greaterThanEq($data['input'], 0);
        Assert::greaterThanEq($data['output'], 0);
        Assert::stringNotEmpty($data['unit']);
        Assert::stringNotEmpty($data['currency']);
        Assert::stringNotEmpty($data['source']);

        if (isset($data['cache_write'])) {
            Assert::numeric($data['cache_write']);
            Assert::greaterThanEq($data['cache_write'], 0);
        }
        if (isset($data['cache_read'])) {
            Assert::numeric($data['cache_read']);
            Assert::greaterThanEq($data['cache_read'], 0);
        }

        $cacheWrite = $data['cache_write'] ?? null;
        $cacheRead = $data['cache_read'] ?? null;

        return new self(
            inputPerMillion: (float) $data['input'],
            outputPerMillion: (float) $data['output'],
            cacheWritePerMillion: is_numeric($cacheWrite) ? (float) $cacheWrite : null,
            cacheReadPerMillion: is_numeric($cacheRead) ? (float) $cacheRead : null,
            unit: (string) $data['unit'],
            currency: (string) $data['currency'],
            source: (string) $data['source'],
        );
    }
}
