<?php

/**
 * Example 1: Basic — role separation via `system_prompt`
 *
 * Simplest pattern: load a YAML template with `Prompt::load()` and let the
 * package send `system_prompt` and `prompt` as separate `SystemMessage` /
 * `UserMessage` to the LLM.
 *
 * No PHP subclass needed.
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;

// ── YAML template ─────────────────────────────────
// resources/prompts/summarize.yaml
//
// name: summarize
// provider: anthropic
// model: claude-sonnet-4-5-20250929
// max_tokens: 500
// temperature: 0.3
//
// system_prompt: |
//   You are a text summarisation expert.
//   Follow these rules:
//   - Distil the source text into at most three points.
//   - Each point is one concise sentence.
//   - Format as a bullet list.
//
// prompt: |
//   Summarise the following text.
//
//   {{ $text }}
//
// ── Resulting messages sent to the LLM ────────────
// | Role          | Content                                   |
// |---------------|-------------------------------------------|
// | SystemMessage | You are a text summarisation expert ...   |
// | UserMessage   | Summarise the following text ...          |

$result = Prompt::load('summarize', [
    'text' => 'A long passage of text goes here ...',
])->executeSync();

// $result is a string (raw text response)
echo $result;
