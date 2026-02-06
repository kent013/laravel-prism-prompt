<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Contracts;

interface PerformanceLoggerInterface
{
    public function isEnabled(): bool;

    public function startExecution(
        string $promptClass,
        string $provider,
        string $model,
        string $prompt
    ): ?string;

    public function completeExecution(
        ?string $executionId,
        string $response,
        float $durationMs,
        ?int $promptTokens = null,
        ?int $completionTokens = null
    ): void;
}
