<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Testing;

class EmbeddingResponseFake
{
    /** @var array<int, float> */
    private array $embedding = [];

    private ?int $tokens = null;

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<int, float>  $embedding
     */
    public function withEmbedding(array $embedding): self
    {
        $this->embedding = $embedding;

        return $this;
    }

    public function withUsage(int $tokens): self
    {
        $this->tokens = $tokens;

        return $this;
    }

    /**
     * @return array<int, float>
     */
    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    public function getTokens(): ?int
    {
        return $this->tokens;
    }
}
