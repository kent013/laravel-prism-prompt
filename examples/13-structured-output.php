<?php

/**
 * Example 13: Schema-enforced DTO via Prism::structured()
 *
 * Since v0.15.0. Declare the response shape as a Prism Schema in
 * getJsonSchema() and decode straight into a DTO in parseStructured().
 * The base routes the call through Prism::structured()->withSchema(),
 * so the provider enforces the contract — no extractJson() regex,
 * no YAML JSON example to drift out of sync.
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

// ── DTO ────────────────────────────────────────────

final readonly class GreetingResponse
{
    public function __construct(
        public string $message,
        public float $tone,
        public bool $polite,
    ) {}
}

// ── Prompt subclass ────────────────────────────────

/** @extends Prompt<GreetingResponse> */
final class GreetingPrompt extends Prompt
{
    public function __construct(public readonly string $userName)
    {
        parent::__construct();
    }

    /**
     * Provider-side schema. Returning non-null switches executeSync() / execute()
     * onto the Prism::structured() path.
     */
    protected function getJsonSchema(): ?Schema
    {
        return new ObjectSchema(
            name: 'greeting_response',
            description: 'A greeting reply',
            properties: [
                new StringSchema('message', 'the greeting text'),
                new NumberSchema(
                    name: 'tone',
                    description: 'tone score (0.0 = cold, 1.0 = warm)',
                    minimum: 0.0,
                    maximum: 1.0,
                ),
                new BooleanSchema('polite', 'true if polite register'),
            ],
            requiredFields: ['message', 'tone', 'polite'],
        );
    }

    /**
     * Map the decoded array straight into the DTO. No round-trip through
     * json_encode / extractJson.
     *
     * @param  array<string, mixed>  $data
     */
    protected function parseStructured(array $data): GreetingResponse
    {
        return new GreetingResponse(
            message: (string) $data['message'],
            tone: (float) $data['tone'],
            polite: (bool) $data['polite'],
        );
    }

    /**
     * Optional. Reached only when Prompt::fake([TextResponseFake]) short-circuits
     * before the structured branch (mostly tests). Production traffic uses
     * parseStructured because getJsonSchema() returns non-null.
     */
    protected function parseResponse(string $text): GreetingResponse
    {
        $data = $this->extractJson($text);

        return new GreetingResponse(
            message: (string) $data['message'],
            tone: (float) $data['tone'],
            polite: (bool) $data['polite'],
        );
    }
}

// ── YAML companion (resources/prompts/greeting.yaml) ────────────────────────
//
// name: greeting
// provider: anthropic
// model: claude-haiku-4-5-20251001
// max_tokens: 200
//
// system_prompt: |
//   You are a friendly greeting assistant. Respond with a short greeting
//   in the requested register.
//   # NOTE: Do NOT include an output-format JSON example here. The Prism
//   # schema in GreetingPrompt::getJsonSchema() is the single source of truth.
//
// prompt: |
//   Greet {{ $userName }}.
//
// ── Usage ───────────────────────────────────────────────────────────────────

$result = (new GreetingPrompt('Alice'))->executeSync();

echo 'message: '.$result->message.PHP_EOL;
echo 'tone:    '.$result->tone.PHP_EOL;
echo 'polite:  '.($result->polite ? 'yes' : 'no').PHP_EOL;
