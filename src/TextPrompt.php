<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

/**
 * Concrete Prompt that returns raw text (used by Prompt::load())
 *
 * @extends Prompt<string>
 */
class TextPrompt extends Prompt
{
    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }
}
