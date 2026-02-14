<?php

/**
 * Example 1: Basic - system_prompt による役割分離
 *
 * Prompt::load() で YAML を読み込み、system_prompt と prompt を
 * それぞれ SystemMessage / UserMessage として LLM に送信する最もシンプルなパターン。
 *
 * PHPクラスを作成する必要はありません。
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;

// ── YAML テンプレート ──────────────────────────────
// resources/prompts/summarize.yaml
//
// name: summarize
// provider: anthropic
// model: claude-sonnet-4-5-20250929
// max_tokens: 500
// temperature: 0.3
//
// system_prompt: |
//   あなたは文章要約の専門家です。
//   以下のルールに従ってください:
//   - 元の文章の要点を3つ以内に絞る
//   - 各要点は1文で簡潔に表現する
//   - 箇条書き形式で出力する
//
// prompt: |
//   以下の文章を要約してください。
//
//   {{ $text }}
//
// ── 送信されるメッセージ ──────────────────────────────
// | Role          | Content                          |
// |---------------|----------------------------------|
// | SystemMessage | あなたは文章要約の専門家です...     |
// | UserMessage   | 以下の文章を要約してください...     |

$result = Prompt::load('summarize', [
    'text' => '長い文章がここに入ります...',
])->executeSync();

// $result は string（生のテキスト応答）
echo $result;
