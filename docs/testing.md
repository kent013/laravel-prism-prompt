# Testing

Mock LLM calls with `Prompt::fake()`, similar to `Prism::fake()`. The fake
records every dispatched message so you can assert what was sent — both
the system message and the user message can be inspected separately.

## Setup and teardown

```php
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;

$fake = Prompt::fake([
    TextResponseFake::make()->withText('{"message": "Hello!", "tone": "friendly"}'),
    TextResponseFake::make()->withText('{"message": "Goodbye!", "tone": "warm"}'),
]);

(new GreetingPrompt('Alice'))->executeSync(); // returns the first fake
(new GreetingPrompt('Bob'))->executeSync();   // returns the second fake

Prompt::stopFaking();
```

`Prompt::fake([])` (empty array) puts the package in a no-LLM mode and
returns empty responses for every call.

## Assertions

| Method | Description |
|--------|-------------|
| `assertCallCount(int $count)` | Number of prompt executions |
| `assertPromptContains(string $text)` | Any message contains text (cross-message search) |
| `assertSystemMessageContains(string $text)` | System message contains text |
| `assertUserMessageContains(string $text)` | User message contains text |
| `assertHasSystemMessage()` | A system message was sent |
| `assertMessageCount(int $count)` | Number of messages per call (system + user, etc.) |
| `assertPrompt(string $prompt)` | Exact prompt text was sent |
| `assertPromptClass(string $class)` | Specific prompt class was used |
| `assertProvider(string $provider)` | Provider was used |
| `assertModel(string $model)` | Model was used |
| `assertRequest(Closure $fn)` | Custom assertion with the recorded request array |

## `TextResponseFake` builder

```php
TextResponseFake::make()
    ->withText('response text')
    ->withUsage(promptTokens: 100, completionTokens: 50);
```

## Subclass-targeted fakes

`SubclassPrompt::fake(...)` only intercepts that subclass — useful when
several prompt classes coexist in one test.

```php
$fake = HintGenerationPrompt::fake([
    TextResponseFake::make()->withText('{"hint": "...", "examples": []}'),
]);

// Other Prompt subclasses still hit Prism (or their own fakes).
HintGenerationPrompt::stopFaking();
```

## Testing `EmbeddingPrompt`

```php
use Kent013\PrismPrompt\EmbeddingPrompt;
use Kent013\PrismPrompt\Testing\EmbeddingResponseFake;

$fake = EmbeddingPrompt::fake([
    EmbeddingResponseFake::make()->withEmbedding([0.1, 0.2, 0.3]),
]);

EmbeddingPrompt::load('document-embedding')->executeSync('test');

$fake->assertCallCount(1);
$fake->assertTextContains('test');
$fake->assertProvider('openai');

EmbeddingPrompt::stopFaking();
```

## Pest example

```php
it('sends system prompt and user message separately', function (): void {
    $fake = Prompt::fake([
        TextResponseFake::make()->withText('{"message": "Hello Alice!"}'),
    ]);

    Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();

    $fake->assertHasSystemMessage();
    $fake->assertSystemMessageContains('friendly greeting assistant');
    $fake->assertUserMessageContains('Say hello to Alice');
    $fake->assertMessageCount(2);

    Prompt::stopFaking();
});
```

See [`examples/04-testing.php`](../examples/04-testing.php) for full
runnable scenarios.
