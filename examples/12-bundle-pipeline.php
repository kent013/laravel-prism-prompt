<?php

/**
 * Example 12: Multi-prompt pipeline
 *
 * A realistic example of one user-facing operation that fans out into
 * several LLM calls.
 *
 * Scenario: a role-play training app processing one trainee turn. A
 * single request runs three calls back to back:
 *
 *   1. Generate the NPC reply  (GenerateNpcResponsePrompt) — natural
 *      response to the trainee's last utterance.
 *   2. Evaluate the trainee    (EvaluateUserMessagePrompt) — score the
 *      trainee's utterance against a rubric.
 *   3. Generate the next hint  (GenerateHintPrompt) — what the trainee
 *      should ask next.
 *
 * Shared infrastructure for all three:
 *   - withMetadata() so the listener can attribute calls to the tenant
 *     and training session.
 *   - UserInput wrapping the trainee's utterance (prompt-injection
 *     defence).
 *   - One YAML and one DTO per Prompt — they stay independent.
 *
 * ⚠ This example runs sequentially. If the shared context is large you
 *    may want PromptPool from Example 09 instead. Here we don't pool
 *    because one of the three calls returns plain text and the DTOs
 *    differ — so sequential is the appropriate choice.
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Values\UserInput;

// ── DTOs ───────────────────────────────────────────

class EvaluateUserMessageDto
{
    public function __construct(
        public readonly int $score,           // 1-5
        public readonly float $trustDelta,    // -0.5 .. +0.5
        public readonly string $feedback,
    ) {}
}

class HintDto
{
    public function __construct(
        public readonly string $hint,
        /** @var list<string> */
        public readonly array $examples,
    ) {}
}

// ── Prompt subclasses ──────────────────────────────

/** @extends Prompt<string> */
final class GenerateNpcResponsePrompt extends Prompt
{
    protected string $promptsDirectory = 'bundle';

    public function __construct(
        public readonly string $npcName,
        public readonly string $npcRole,
        public readonly string $conversationContext,
        public readonly UserInput $userMessage,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $text): string
    {
        return trim($text);  // NPC utterances are plain text (1-3 sentences).
    }
}

/** @extends Prompt<EvaluateUserMessageDto> */
final class EvaluateUserMessagePrompt extends Prompt
{
    protected string $promptsDirectory = 'bundle';

    public function __construct(
        public readonly string $npcName,
        public readonly string $npcRole,
        public readonly string $conversationContext,
        public readonly UserInput $userMessage,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $text): EvaluateUserMessageDto
    {
        $data = $this->extractJson($text);

        /** @var array{score: int, trust_delta: float, feedback: string} $data */
        return new EvaluateUserMessageDto(
            score: $data['score'],
            trustDelta: $data['trust_delta'],
            feedback: $data['feedback'],
        );
    }
}

/** @extends Prompt<HintDto> */
final class GenerateHintPrompt extends Prompt
{
    protected string $promptsDirectory = 'bundle';

    public function __construct(
        public readonly string $conversationContext,
        public readonly string $progressText,
        public readonly string $latestNpcMessage,
    ) {
        parent::__construct();
    }

    protected function parseResponse(string $text): HintDto
    {
        $data = $this->extractJson($text);

        /** @var array{hint: string, examples: list<string>} $data */
        return new HintDto($data['hint'], $data['examples']);
    }
}

// ════════════════════════════════════════════════════
// Pipeline
// ════════════════════════════════════════════════════

class TrainingTurnPipeline
{
    public function __construct(
        private readonly int $organizationId,
        private readonly int $sessionId,
    ) {}

    /**
     * @param  list<array{role: string, body: string}>  $history  newest-first conversation history
     */
    public function handle(
        string $npcName,
        string $npcRole,
        array $history,
        string $rawUserMessage,
        string $progressText,
    ): TrainingTurnResultDto {
        $userMessage = UserInput::from($rawUserMessage);
        $conversationContext = $this->formatHistory($history);

        $metadata = [
            'organization_id' => $this->organizationId,
            'subject_type' => 'App\\Models\\TrainingSession',
            'subject_id' => $this->sessionId,
        ];

        // ── 1. NPC reply ────────────────────────────
        $npcReply = (new GenerateNpcResponsePrompt(
            npcName: $npcName,
            npcRole: $npcRole,
            conversationContext: $conversationContext,
            userMessage: $userMessage,
        ))
            ->withMetadata($metadata + ['stage' => 'generate_npc_response'])
            ->executeSync();

        // ── 2. Score the trainee ────────────────────
        $evaluation = (new EvaluateUserMessagePrompt(
            npcName: $npcName,
            npcRole: $npcRole,
            conversationContext: $conversationContext,
            userMessage: $userMessage,
        ))
            ->withMetadata($metadata + ['stage' => 'evaluate_user_message'])
            ->executeSync();

        // ── 3. Hint — rebuild history with the new NPC reply included
        $extendedHistory = [
            ['role' => 'user', 'body' => $rawUserMessage],
            ['role' => 'assistant', 'body' => $npcReply],
            ...$history,
        ];

        $hint = (new GenerateHintPrompt(
            conversationContext: $this->formatHistory($extendedHistory),
            progressText: $progressText,
            latestNpcMessage: $npcReply,
        ))
            ->withMetadata($metadata + ['stage' => 'generate_hint'])
            ->executeSync();

        return new TrainingTurnResultDto(
            npcReply: $npcReply,
            evaluation: $evaluation,
            hint: $hint,
        );
    }

    /** @param list<array{role: string, body: string}> $history */
    private function formatHistory(array $history): string
    {
        // Note: in production prefer a UserInput-aware helper such as
        // ConversationContextBuilder (see Example 11 for the pattern).
        return collect($history)
            ->map(fn (array $h) => "{$h['role']}: {$h['body']}")
            ->implode("\n");
    }
}

class TrainingTurnResultDto
{
    public function __construct(
        public readonly string $npcReply,
        public readonly EvaluateUserMessageDto $evaluation,
        public readonly HintDto $hint,
    ) {}
}

// ════════════════════════════════════════════════════
// Usage
// ════════════════════════════════════════════════════

$pipeline = new TrainingTurnPipeline(
    organizationId: 42,
    sessionId: 1234,
);

$result = $pipeline->handle(
    npcName: 'Director Tanaka',
    npcRole: 'Director at a manufacturing company (50s, male)',
    history: [
        ['role' => 'user', 'body' => 'Nice to meet you, thank you for your time.'],
        ['role' => 'assistant', 'body' => "Likewise. Let's get straight into it."],
    ],
    rawUserMessage: 'May I start by asking about the challenges your company is currently facing?',
    progressText: "Confirmed:\n- Introductions (100%)\n\nUnconfirmed:\n- Current challenges (0%)\n- Budget (0%)",
);

echo $result->npcReply;            // string — natural NPC utterance
echo $result->evaluation->score;   // int 1-5
echo $result->hint->hint;          // string — hint about what to ask next
foreach ($result->hint->examples as $example) {
    echo "- {$example}\n";
}

// ════════════════════════════════════════════════════
// Design notes
// ════════════════════════════════════════════════════
//
// 1. Each Prompt owns one responsibility, one YAML, one DTO. Inputs are
//    typed via the constructor so wrong variables fail at type-check
//    time instead of silently producing an empty {{ $var }} expansion.
//
// 2. With a 'stage' field in metadata, the PromptExecutionCompleted
//    listener can split costs per stage:
//
//        Event::listen(PromptExecutionCompleted::class, function ($e) {
//            DB::table('llm_call_logs')->insert([
//                ...,
//                'stage' => $e->metadata['stage'] ?? null,
//                'organization_id' => $e->metadata['organization_id'],
//                'subject_type' => $e->metadata['subject_type'],
//                'subject_id' => $e->metadata['subject_id'],
//                'cost_usd' => $e->cost?->totalCostUsd,
//            ]);
//        });
//
// 3. Three LLM calls per turn means model selection matters. Tune each
//    YAML to the appropriate model:
//      - NPC reply:  natural-feeling text → claude-sonnet
//      - Evaluation: structured response → claude-haiku is enough
//      - Hint:       structured response → claude-haiku
//    Separate YAML files make per-stage tuning trivial.
//
// 4. Compensation across partial failures (e.g. NPC reply persisted but
//    the evaluation call failed) gets heavy depending on your
//    consistency requirements. If you need transactional integrity,
//    wrap the whole thing in a PromptOperation (see Example 07) and
//    split it into checkpointed phases.
