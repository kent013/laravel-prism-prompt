<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Testing;

use Closure;
use Exception;
use PHPUnit\Framework\Assert as PHPUnit;

class PromptFake
{
    protected int $responseSequence = 0;

    /** @var array<int, array{prompt_class: string, prompt: string, provider: string, model: string}> */
    protected array $recorded = [];

    /**
     * @param  array<int, TextResponseFake>  $responses
     */
    public function __construct(protected array $responses = []) {}

    /**
     * Record a prompt execution
     */
    public function record(string $promptClass, string $prompt, string $provider, string $model): void
    {
        $this->recorded[] = [
            'prompt_class' => $promptClass,
            'prompt' => $prompt,
            'provider' => $provider,
            'model' => $model,
        ];
    }

    /**
     * Get the next response in sequence
     */
    public function nextResponse(): TextResponseFake
    {
        if ($this->responses === []) {
            return TextResponseFake::make()->withText('');
        }

        $sequence = $this->responseSequence;

        if (! isset($this->responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $this->responses[$sequence];
    }

    /**
     * Assert with a callback
     *
     * @param  Closure(array<int, array{prompt_class: string, prompt: string, provider: string, model: string}>):void  $fn
     */
    public function assertRequest(Closure $fn): void
    {
        $fn($this->recorded);
    }

    /**
     * Assert a prompt was called with specific class
     */
    public function assertPromptClass(string $promptClass): void
    {
        $classes = collect($this->recorded)
            ->pluck('prompt_class');

        PHPUnit::assertTrue(
            $classes->contains($promptClass),
            "Could not find prompt class {$promptClass} in the recorded requests"
        );
    }

    /**
     * Assert a specific prompt text was sent
     */
    public function assertPrompt(string $prompt): void
    {
        $prompts = collect($this->recorded)
            ->pluck('prompt');

        PHPUnit::assertTrue(
            $prompts->contains($prompt),
            "Could not find prompt '{$prompt}' in the recorded requests"
        );
    }

    /**
     * Assert prompt contains specific text
     */
    public function assertPromptContains(string $text): void
    {
        $found = collect($this->recorded)
            ->pluck('prompt')
            ->contains(fn (string $prompt) => str_contains($prompt, $text));

        PHPUnit::assertTrue(
            $found,
            "Could not find text '{$text}' in any recorded prompt"
        );
    }

    /**
     * Assert number of calls made
     */
    public function assertCallCount(int $expectedCount): void
    {
        $actualCount = count($this->recorded);

        PHPUnit::assertSame(
            $expectedCount,
            $actualCount,
            "Expected {$expectedCount} calls, got {$actualCount}"
        );
    }

    /**
     * Assert provider was used
     */
    public function assertProvider(string $provider): void
    {
        $providers = collect($this->recorded)
            ->pluck('provider');

        PHPUnit::assertTrue(
            $providers->contains($provider),
            "Could not find provider '{$provider}' in the recorded requests"
        );
    }

    /**
     * Assert model was used
     */
    public function assertModel(string $model): void
    {
        $models = collect($this->recorded)
            ->pluck('model');

        PHPUnit::assertTrue(
            $models->contains($model),
            "Could not find model '{$model}' in the recorded requests"
        );
    }

    /**
     * Get all recorded requests
     *
     * @return array<int, array{prompt_class: string, prompt: string, provider: string, model: string}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * Reset the fake state
     */
    public function reset(): void
    {
        $this->recorded = [];
        $this->responseSequence = 0;
    }
}
