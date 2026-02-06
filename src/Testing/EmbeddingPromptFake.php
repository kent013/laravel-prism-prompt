<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Testing;

use Closure;
use Exception;
use PHPUnit\Framework\Assert as PHPUnit;

class EmbeddingPromptFake
{
    protected int $responseSequence = 0;

    /** @var array<int, array{prompt_class: string, text: string, provider: string, model: string}> */
    protected array $recorded = [];

    /**
     * @param  array<int, EmbeddingResponseFake>  $responses
     */
    public function __construct(protected array $responses = []) {}

    public function record(string $promptClass, string $text, string $provider, string $model): void
    {
        $this->recorded[] = [
            'prompt_class' => $promptClass,
            'text' => $text,
            'provider' => $provider,
            'model' => $model,
        ];
    }

    public function nextResponse(): EmbeddingResponseFake
    {
        if ($this->responses === []) {
            return EmbeddingResponseFake::make()->withEmbedding([]);
        }

        $sequence = $this->responseSequence;

        if (! isset($this->responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $this->responses[$sequence];
    }

    /**
     * @param  Closure(array<int, array{prompt_class: string, text: string, provider: string, model: string}>):void  $fn
     */
    public function assertRequest(Closure $fn): void
    {
        $fn($this->recorded);
    }

    public function assertCallCount(int $expectedCount): void
    {
        $actualCount = count($this->recorded);

        PHPUnit::assertSame(
            $expectedCount,
            $actualCount,
            "Expected {$expectedCount} calls, got {$actualCount}"
        );
    }

    public function assertTextContains(string $text): void
    {
        $found = collect($this->recorded)
            ->pluck('text')
            ->contains(fn (string $input) => str_contains($input, $text));

        PHPUnit::assertTrue(
            $found,
            "Could not find text '{$text}' in any recorded input"
        );
    }

    public function assertProvider(string $provider): void
    {
        $providers = collect($this->recorded)->pluck('provider');

        PHPUnit::assertTrue(
            $providers->contains($provider),
            "Could not find provider '{$provider}' in the recorded requests"
        );
    }

    public function assertModel(string $model): void
    {
        $models = collect($this->recorded)->pluck('model');

        PHPUnit::assertTrue(
            $models->contains($model),
            "Could not find model '{$model}' in the recorded requests"
        );
    }

    /**
     * @return array<int, array{prompt_class: string, text: string, provider: string, model: string}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public function reset(): void
    {
        $this->recorded = [];
        $this->responseSequence = 0;
    }
}
