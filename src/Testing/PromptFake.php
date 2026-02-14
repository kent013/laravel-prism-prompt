<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Testing;

use Closure;
use Exception;
use PHPUnit\Framework\Assert as PHPUnit;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PromptFake
{
    protected int $responseSequence = 0;

    /** @var array<int, array{prompt_class: string, messages: array<int, Message>, provider: string, model: string}> */
    protected array $recorded = [];

    /**
     * @param  array<int, TextResponseFake>  $responses
     */
    public function __construct(protected array $responses = []) {}

    /**
     * Record a prompt execution
     *
     * @param  array<int, Message>  $messages
     */
    public function record(string $promptClass, array $messages, string $provider, string $model): void
    {
        $this->recorded[] = [
            'prompt_class' => $promptClass,
            'messages' => $messages,
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
     * @param  Closure(array<int, array{prompt_class: string, messages: array<int, Message>, provider: string, model: string}>):void  $fn
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
     * Assert any message contains specific text
     */
    public function assertPromptContains(string $text): void
    {
        $found = collect($this->recorded)
            ->contains(function (array $record) use ($text): bool {
                foreach ($record['messages'] as $message) {
                    if (str_contains($message->content, $text)) {
                        return true;
                    }
                }

                return false;
            });

        PHPUnit::assertTrue(
            $found,
            "Could not find text '{$text}' in any recorded message"
        );
    }

    /**
     * Assert system message contains specific text
     */
    public function assertSystemMessageContains(string $text): void
    {
        $found = collect($this->recorded)
            ->contains(function (array $record) use ($text): bool {
                foreach ($record['messages'] as $message) {
                    if ($message instanceof SystemMessage && str_contains($message->content, $text)) {
                        return true;
                    }
                }

                return false;
            });

        PHPUnit::assertTrue(
            $found,
            "Could not find text '{$text}' in any recorded system message"
        );
    }

    /**
     * Assert user message contains specific text
     */
    public function assertUserMessageContains(string $text): void
    {
        $found = collect($this->recorded)
            ->contains(function (array $record) use ($text): bool {
                foreach ($record['messages'] as $message) {
                    if ($message instanceof UserMessage && str_contains($message->content, $text)) {
                        return true;
                    }
                }

                return false;
            });

        PHPUnit::assertTrue(
            $found,
            "Could not find text '{$text}' in any recorded user message"
        );
    }

    /**
     * Assert message count for the latest recorded request
     */
    public function assertMessageCount(int $expectedCount): void
    {
        $lastRecord = end($this->recorded);
        PHPUnit::assertNotFalse($lastRecord, 'No recorded requests found');

        $actualCount = count($lastRecord['messages']);

        PHPUnit::assertSame(
            $expectedCount,
            $actualCount,
            "Expected {$expectedCount} messages, got {$actualCount}"
        );
    }

    /**
     * Assert that a system message exists in the recorded requests
     */
    public function assertHasSystemMessage(): void
    {
        $found = collect($this->recorded)
            ->contains(function (array $record): bool {
                foreach ($record['messages'] as $message) {
                    if ($message instanceof SystemMessage) {
                        return true;
                    }
                }

                return false;
            });

        PHPUnit::assertTrue(
            $found,
            'No system message found in any recorded request'
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
     * @return array<int, array{prompt_class: string, messages: array<int, Message>, provider: string, model: string}>
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
