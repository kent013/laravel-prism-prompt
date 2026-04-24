<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when PromptPool::executeWithWarmup() fails for a specific prompt.
 *
 * Carries the index of the failing prompt in the original input list so that
 * callers can map it back to their own domain identifier (e.g. which axis in
 * a 6-axis heuristic evaluation). The package itself intentionally stays
 * domain-agnostic — the index is all we know at this layer.
 *
 * The underlying cause (HTTP failure, response parse error, etc.) is always
 * attached as the `$previous` throwable so upstream classifiers can inspect
 * HTTP status / provider error codes / request IDs.
 */
final class PoolExecutionException extends RuntimeException
{
    public function __construct(
        private readonly int $promptIndex,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Prompt pool execution failed at index %d: %s', $promptIndex, $previous->getMessage()),
            0,
            $previous,
        );
    }

    public function getPromptIndex(): int
    {
        return $this->promptIndex;
    }
}
