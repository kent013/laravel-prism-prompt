<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Testing;

/**
 * Fluent builder for fake text responses
 */
class TextResponseFake
{
    private string $text = '';

    private ?int $promptTokens = null;

    private ?int $completionTokens = null;

    public static function make(): self
    {
        return new self;
    }

    public function withText(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function withUsage(int $promptTokens, int $completionTokens): self
    {
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }
}
