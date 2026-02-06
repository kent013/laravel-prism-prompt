<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Contracts;

use React\Promise\PromiseInterface;

/**
 * @template TResponse
 */
interface EmbeddingPromptInterface
{
    /**
     * @return PromiseInterface<array<int, float>>
     */
    public function execute(string $text): PromiseInterface;

    /**
     * @return array<int, float>
     */
    public function executeSync(string $text): array;
}
