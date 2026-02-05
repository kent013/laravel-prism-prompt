<?php

declare(strict_types=1);

namespace Because\PrismPrompt\Contracts;

use React\Promise\PromiseInterface;

/**
 * @template TResponse
 */
interface PromptInterface
{
    /**
     * Execute prompt asynchronously
     *
     * @return PromiseInterface<TResponse>
     */
    public function execute(): PromiseInterface;

    /**
     * Execute prompt synchronously
     *
     * @return TResponse
     */
    public function executeSync(): mixed;
}
