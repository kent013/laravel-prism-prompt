<?php

/**
 * Example 12: 複数 Prompt のパイプライン
 *
 * 1 つのユーザー操作を複数の LLM 呼び出しでさばく現実的な例。
 *
 * シナリオ: ロールプレイ訓練アプリで「学習者の発言を 1 ターン処理する」
 * というユースケース。1 つのリクエストで以下を順に走らせる:
 *
 *   1. NPC 応答生成   (GenerateNpcResponsePrompt) — 学習者発言に対する NPC の返答
 *   2. 発言評価       (EvaluateUserMessagePrompt) — 学習者発言を rubric で採点
 *   3. ヒント生成     (GenerateHintPrompt)        — 学習者の次の一手のヒント
 *
 * 全てに共通する基盤要素:
 *   - withMetadata() でテナント / 訓練セッションを listener に渡す
 *   - UserInput でユーザー発言を wrap (Prompt injection 防御)
 *   - 各 Prompt は独立した YAML を持ち、独立した DTO を返す
 *
 * ⚠ この例は順次実行版。共通コンテキストが大きい場合は Example 09 の
 *    PromptPool を検討すること (今回は 3 本中 1 本が plain text 応答で
 *    DTO が違うため pool しない、というのも妥当な判断)。
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Values\UserInput;

// ── DTO ────────────────────────────────────────────

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
        return trim($text);  // NPC 発言は plain text (1-3 文)
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
// パイプライン本体
// ════════════════════════════════════════════════════

class TrainingTurnPipeline
{
    public function __construct(
        private readonly int $organizationId,
        private readonly int $sessionId,
    ) {}

    /**
     * @param  list<array{role: string, body: string}>  $history  会話履歴 (新しい順)
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

        // ── 1. NPC 応答生成 ──────────────────────────
        $npcReply = (new GenerateNpcResponsePrompt(
            npcName: $npcName,
            npcRole: $npcRole,
            conversationContext: $conversationContext,
            userMessage: $userMessage,
        ))
            ->withMetadata($metadata + ['stage' => 'generate_npc_response'])
            ->executeSync();

        // ── 2. 学習者発言の評価 ─────────────────────
        $evaluation = (new EvaluateUserMessagePrompt(
            npcName: $npcName,
            npcRole: $npcRole,
            conversationContext: $conversationContext,
            userMessage: $userMessage,
        ))
            ->withMetadata($metadata + ['stage' => 'evaluate_user_message'])
            ->executeSync();

        // ── 3. 次のヒント生成 ─ NPC 応答後の状態で履歴を組み直す ──
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
        // 注: 実プロダクションでは ConversationContextBuilder のような
        //     UserInput-aware なヘルパーで組み立てる (Example 11 参照)。
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
// 利用例
// ════════════════════════════════════════════════════

$pipeline = new TrainingTurnPipeline(
    organizationId: 42,
    sessionId: 1234,
);

$result = $pipeline->handle(
    npcName: '田中部長',
    npcRole: '製造業の取締役 (50代男性)',
    history: [
        ['role' => 'user', 'body' => 'はじめまして、お時間ありがとうございます'],
        ['role' => 'assistant', 'body' => 'こちらこそ。早速本題に入ろうか'],
    ],
    rawUserMessage: 'まず御社の現状の課題からお伺いしてよろしいでしょうか',
    progressText: "確認済み:\n- 自己紹介 (100%)\n\n未確認:\n- 現状課題 (0%)\n- 予算 (0%)",
);

echo $result->npcReply;            // string (NPC が返す自然な発言)
echo $result->evaluation->score;   // int 1-5
echo $result->hint->hint;          // string (次の質問のヒント)
foreach ($result->hint->examples as $example) {
    echo "- {$example}\n";
}

// ════════════════════════════════════════════════════
// 設計上のポイント
// ════════════════════════════════════════════════════
//
// 1. 各 Prompt は独立した責務 / 独立した YAML / 独立した DTO を持つ。
//    入力を Prompt サブクラスで型として受けるので、誤った変数を渡すと
//    型エラーで早期に死ぬ (YAML 文字列展開で silent に消えない)。
//
// 2. withMetadata に 'stage' を付けておくと PromptExecutionCompleted listener
//     側で「どの段階の LLM call か」をコスト集計できる:
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
// 3. 1 ターンで 3 回 LLM を叩くので、各 Prompt の model を最適化する:
//    - NPC 応答生成: 自然さ重視 → claude-sonnet
//    - 評価:        構造化応答だけ → claude-haiku で十分 (安価高速)
//    - ヒント:      構造化応答だけ → claude-haiku
//    YAML を分けてあるので model を別個に変えられる。
//
// 4. 中間で例外が出た場合の補償処理 (NPC 応答は保存したのに評価で失敗した
//    場合の整合性) が要件次第で重くなる。データベース整合性が要件なら
//    Example 07 の PromptOperation で phase を切ってチェックポイント化する。
