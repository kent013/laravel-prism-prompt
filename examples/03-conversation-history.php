<?php

/**
 * Example 3: Conversation History — send chat history as native messages
 *
 * Override `buildConversationMessages()` to dispatch past turns as
 * `UserMessage` / `AssistantMessage` instead of one big concatenated
 * string. The LLM understands native message arrays better than a
 * stringified transcript.
 *
 * system_prompt (YAML)   → SystemMessage     ... role / constraints
 * History (PHP)          → UserMessage / AssistantMessage interleaved
 * Latest user turn (PHP) → UserMessage      ... must be last
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

// ── Message history type ───────────────────────────

class ChatMessage
{
    public function __construct(
        public readonly string $role, // 'user' | 'assistant'
        public readonly string $content,
    ) {}
}

// ── Prompt subclass ────────────────────────────────

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
     * Convert history into a Prism message array.
     *
     * @return array<int, Message>
     */
    protected function buildConversationMessages(): array
    {
        $messages = [];

        // Past turns become alternating UserMessage / AssistantMessage.
        foreach ($this->history as $msg) {
            $messages[] = match ($msg->role) {
                'user' => new UserMessage($msg->content),
                'assistant' => new AssistantMessage($msg->content),
            };
        }

        // The latest user turn must always be the last UserMessage.
        $messages[] = new UserMessage($this->userMessage);

        return $messages;
    }

    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }
}

// ── YAML template ──────────────────────────────────
// resources/prompts/chat_response.yaml
//
// name: chat_response
// provider: anthropic
// model: claude-sonnet-4-5-20250929
// max_tokens: 500
// temperature: 0.7
//
// system_prompt: |
//   You are a customer support assistant.
//   Reply politely and concisely.
//   Provide concrete steps for technical questions.
//
// prompt: |
//   {{ $userMessage }}
//
// ── Messages sent to the LLM (3rd turn shown) ─────
// | #  | Role             | Content                              |
// |----|------------------|--------------------------------------|
// | 0  | SystemMessage    | You are a customer support assistant.|
// | 1  | UserMessage      | I forgot my password                 |
// | 2  | AssistantMessage | Here are the password reset steps... |
// | 3  | UserMessage      | The email isn't arriving             |
// | 4  | AssistantMessage | Please check your spam folder...     |
// | 5  | UserMessage      | I checked but I can't find it        |
//
// The buildConversationMessages() return value is placed right after
// the SystemMessage.

$response = (new ChatResponsePrompt(
    userMessage: "I checked but I can't find it",
    history: [
        new ChatMessage('user', 'I forgot my password'),
        new ChatMessage('assistant', 'Let me walk you through the password reset. Click "Forgot password" on the login screen and we will email a reset link.'),
        new ChatMessage('user', "The email isn't arriving"),
        new ChatMessage('assistant', 'Please check your spam folder and double-check the email address you registered with.'),
    ],
))->executeSync();

echo $response;
