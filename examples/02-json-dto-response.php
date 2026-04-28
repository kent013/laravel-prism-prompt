<?php

/**
 * Example 2: JSON DTO — structured response via subclass
 *
 * Instruct the LLM to emit JSON in `system_prompt`, then parse it into a
 * typed DTO inside `parseResponse()` using `extractJson()`.
 *
 * system_prompt: role definition + output schema
 * prompt:        dynamic data (conversation, progress, ...)
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;

// ── DTO ────────────────────────────────────────────

class HintResponseDto
{
    public function __construct(
        public readonly string $hint,
        /** @var list<string> */
        public readonly array $examples,
    ) {}

    /**
     * @param  array{hint: string, examples: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hint: $data['hint'],
            examples: $data['examples'],
        );
    }
}

// ── Prompt subclass ────────────────────────────────

/**
 * @extends Prompt<HintResponseDto>
 */
class HintGenerationPrompt extends Prompt
{
    protected string $promptsDirectory = 'training';

    // YAML lives at resources/prompts/training/hint_generation.yaml
    // Naming convention: HintGenerationPrompt → hint_generation.yaml

    public function __construct(
        public readonly string $conversationText,
        public readonly string $progressText,
        public readonly string $latestNpcMessage,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $responseText): HintResponseDto
    {
        $data = $this->extractJson($responseText);

        /** @var array{hint: string, examples: list<string>} $data */
        return HintResponseDto::fromArray($data);
    }
}

// ── YAML template ──────────────────────────────────
// resources/prompts/training/hint_generation.yaml
//
// name: hint_generation
// provider: anthropic
// model: claude-haiku-4-5-20251001
// max_tokens: 1000
// temperature: 0.9
//
// system_prompt: |
//   You generate hints suggesting what the trainee should ask next.
//   Read the conversation history and progress, then return a useful
//   hint plus concrete example questions.
//
//   # Output format (JSON)
//   {
//     "hint": "Hint pointing at the next thing to clarify (<= 50 chars)",
//     "examples": [
//       "Concrete example question 1",
//       "Concrete example question 2",
//       "Concrete example question 3"
//     ]
//   }
//
// prompt: |
//   # Conversation history (latest 20 turns)
//   {{ $conversationText }}
//
//   # Current progress
//   {{ $progressText }}
//
//   # Latest NPC utterance
//   {{ $latestNpcMessage }}
//
//   # Task
//   Generate a hint suggesting what the trainee should ask about next.
//
// ── Messages sent to the LLM ───────────────────────
// | Role          | Content                                                |
// |---------------|--------------------------------------------------------|
// | SystemMessage | You generate hints ... # Output format (JSON) {...}    |
// | UserMessage   | # Conversation history\nTrainee: ...\n# Task\n...      |

$hint = (new HintGenerationPrompt(
    conversationText: "Trainee: Could you describe your current system?\nNPC: We run on-prem.",
    progressText: "Confirmed:\n- Current system overview (100%)\n\nUnconfirmed:\n- Migration requirements (0%)\n- Schedule (0%)",
    latestNpcMessage: 'We run on-prem.',
))->executeSync();

// $hint is a HintResponseDto
echo $hint->hint; // "Drill into the migration requirements"
foreach ($hint->examples as $example) {
    echo "- {$example}\n";
}
