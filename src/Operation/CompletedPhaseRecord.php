<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Carbon\CarbonImmutable;

/**
 * Phase が既に完了済みで skip された際に onSkipped callback に渡される情報。
 */
final readonly class CompletedPhaseRecord
{
    public function __construct(
        public string $name,
        public ?string $outputReference,
        public CarbonImmutable $completedAt,
    ) {}
}
