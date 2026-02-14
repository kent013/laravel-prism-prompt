<?php

/**
 * Example 2: JSON DTO - サブクラスで構造化レスポンスを取得
 *
 * system_prompt にJSON出力形式を指示し、
 * parseResponse() で extractJson → DTO マッピングを行うパターン。
 *
 * system_prompt: 役割定義 + 出力形式の指示
 * prompt:        動的データ（会話履歴、進捗など）
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;

// ── DTO ──────────────────────────────────────────────

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

// ── Prompt クラス ────────────────────────────────────

/**
 * @extends Prompt<HintResponseDto>
 */
class HintGenerationPrompt extends Prompt
{
    protected string $promptsDirectory = 'training';

    // YAML は resources/prompts/training/hint_generation.yaml に配置
    // naming convention: HintGenerationPrompt → hint_generation.yaml

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

// ── YAML テンプレート ──────────────────────────────
// resources/prompts/training/hint_generation.yaml
//
// name: hint_generation
// provider: anthropic
// model: claude-haiku-4-5-20251001
// max_tokens: 1000
// temperature: 0.9
//
// system_prompt: |
//   あなたは学習者が次に質問すべき内容のヒントを生成する役割です。
//   会話履歴と進捗状況から、適切なヒントと具体的な質問例を提示してください。
//
//   # 出力形式（JSON）
//   {
//     "hint": "次に確認すべき内容のヒント（50文字以内）",
//     "examples": [
//       "具体的な質問例1",
//       "具体的な質問例2",
//       "具体的な質問例3"
//     ]
//   }
//
// prompt: |
//   # 会話履歴（直近20件）
//   {{ $conversationText }}
//
//   # 現在の進捗状況
//   {{ $progressText }}
//
//   # 最新のNPC発言
//   {{ $latestNpcMessage }}
//
//   # タスク
//   上記の会話履歴と進捗状況から、学習者が次に質問すべき内容のヒントを生成してください。
//
// ── 送信されるメッセージ ──────────────────────────────
// | Role          | Content                                     |
// |---------------|---------------------------------------------|
// | SystemMessage | あなたは学習者が...出力形式（JSON）{...}       |
// | UserMessage   | # 会話履歴\nトレーニー: ...# タスク\n...      |

$hint = (new HintGenerationPrompt(
    conversationText: "トレーニー: 現在のシステム構成を教えてください\nNPC: 現在はオンプレミスのサーバーで運用しています",
    progressText: "確認済み:\n- 現行システムの概要 (100%)\n\n未確認:\n- 移行要件 (0%)\n- スケジュール (0%)",
    latestNpcMessage: '現在はオンプレミスのサーバーで運用しています',
))->executeSync();

// $hint は HintResponseDto
echo $hint->hint; // "移行要件について確認しましょう"
foreach ($hint->examples as $example) {
    echo "- {$example}\n";
}
