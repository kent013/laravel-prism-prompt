<?php

/**
 * Example 3: Conversation History - 会話履歴を Message 配列で送信
 *
 * buildConversationMessages() をオーバーライドして、
 * 過去の会話履歴を UserMessage / AssistantMessage として送信するパターン。
 *
 * これにより LLM はネイティブな会話コンテキストとして履歴を理解できます。
 * （テキスト連結で渡すよりも精度が高い）
 *
 * system_prompt (YAML) → SystemMessage  … 役割設定・制約
 * 会話履歴 (PHP)       → UserMessage / AssistantMessage の交互配列
 * 最新の入力 (PHP)     → UserMessage   … 末尾に配置（必須）
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

// ── メッセージ履歴の型 ──────────────────────────────

class ChatMessage
{
    public function __construct(
        public readonly string $role, // 'user' | 'assistant'
        public readonly string $content,
    ) {}
}

// ── Prompt クラス ────────────────────────────────────

/**
 * @extends Prompt<string>
 */
class ChatResponsePrompt extends Prompt
{
    /** @var list<ChatMessage> */
    private array $history;

    /**
     * @param  list<ChatMessage>  $history
     */
    public function __construct(
        private readonly string $userMessage,
        array $history = [],
    ) {
        parent::__construct();
        $this->history = $history;
    }

    /**
     * 会話履歴を Prism Message 配列に変換
     *
     * @return array<int, Message>
     */
    protected function buildConversationMessages(): array
    {
        $messages = [];

        // 過去の会話履歴を UserMessage / AssistantMessage に変換
        foreach ($this->history as $msg) {
            $messages[] = match ($msg->role) {
                'user' => new UserMessage($msg->content),
                'assistant' => new AssistantMessage($msg->content),
            };
        }

        // 最新のユーザー入力（必ず末尾に UserMessage）
        $messages[] = new UserMessage($this->userMessage);

        return $messages;
    }

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }
}

// ── YAML テンプレート ──────────────────────────────
// resources/prompts/chat_response.yaml
//
// name: chat_response
// provider: anthropic
// model: claude-sonnet-4-5-20250929
// max_tokens: 500
// temperature: 0.7
//
// system_prompt: |
//   あなたはカスタマーサポートのアシスタントです。
//   丁寧かつ簡潔に回答してください。
//   技術的な質問には具体的な手順を示してください。
//
// prompt: |
//   {{ $userMessage }}
//
// ── 送信されるメッセージ（3ターン目の場合）──────────
// | #  | Role             | Content                    |
// |----|------------------|----------------------------|
// | 0  | SystemMessage    | あなたはカスタマーサポート...  |
// | 1  | UserMessage      | パスワードを忘れました       |
// | 2  | AssistantMessage | パスワードリセット手順...     |
// | 3  | UserMessage      | メールが届きません           |
// | 4  | AssistantMessage | 迷惑メールフォルダを...       |
// | 5  | UserMessage      | 確認しましたが見つかりません   |
//
// ※ buildConversationMessages() の返り値がそのまま
//   SystemMessage の後に配置される

$response = (new ChatResponsePrompt(
    userMessage: '確認しましたが見つかりません',
    history: [
        new ChatMessage('user', 'パスワードを忘れました'),
        new ChatMessage('assistant', 'パスワードリセットの手順をご案内します。ログイン画面の「パスワードを忘れた方」をクリックしてください。'),
        new ChatMessage('user', 'メールが届きません'),
        new ChatMessage('assistant', '迷惑メールフォルダをご確認ください。また、登録メールアドレスが正しいかご確認ください。'),
    ],
))->executeSync();

echo $response;
