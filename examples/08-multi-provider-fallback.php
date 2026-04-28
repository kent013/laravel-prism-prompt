<?php

/**
 * Example 8: Multi-provider Fallback (BYOK)
 *
 * In a SaaS with bring-your-own-key UX you cannot predict which
 * provider's key the caller will hand you. Declare the candidate models
 * in priority order in YAML, and `withApiKeys()` automatically selects
 * the highest-priority provider for which a key was supplied.
 *
 * This sample assumes "the user provides at least one of Anthropic /
 * OpenAI / Google keys". The server-side logic stays provider-agnostic.
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;

// ── YAML template ──────────────────────────────────
// resources/prompts/translate.yaml
//
// name: translate
//
// # System default — used when no caller-supplied keys are available.
// provider: anthropic
// model: claude-sonnet-4-5-20250929
// max_tokens: 1024
// temperature: 0.2
//
// # Multi-provider candidates. Lower priority value = higher priority.
// models:
//   - { provider: anthropic, model: claude-sonnet-4-5-20250929, priority: 1 }
//   - { provider: openai,    model: gpt-4o,                     priority: 2 }
//   - { provider: google,    model: gemini-2.0-flash-exp,       priority: 3 }
//
// system_prompt: |
//   You are a precise translator. Translate the input to {{ $targetLang }}.
//   Output only the translation. No quotes, no explanation.
//
// prompt: |
//   {{ $text }}

// ════════════════════════════════════════════════════
// Scenario A: User provides only one key
// ════════════════════════════════════════════════════

// User has only an OpenAI key. The YAML preferred Anthropic, but the
// resolver falls back to OpenAI (priority 2 — picked over priority 3
// google because google has no key either).
$translated = Prompt::load('translate', [
    'targetLang' => 'Japanese',
    'text' => 'The quick brown fox jumps over the lazy dog.',
])
    ->withApiKeys([
        'openai' => $userApiKeys['openai'] ?? '',
    ])
    ->executeSync();

// ════════════════════════════════════════════════════
// Scenario B: Multiple keys — Anthropic wins
// ════════════════════════════════════════════════════

$translated = Prompt::load('translate', [
    'targetLang' => 'English',
    'text' => 'Quantum computing does not replace classical computing.',
])
    ->withApiKeys([
        'anthropic' => $userApiKeys['anthropic'] ?? '',
        'openai' => $userApiKeys['openai'] ?? '',
        'google' => $userApiKeys['google'] ?? '',
    ])
    ->executeSync();
// → Anthropic at priority 1 is selected.

// ════════════════════════════════════════════════════
// Scenario C: Override the provider config too
// ════════════════════════════════════════════════════

$translated = Prompt::load('translate', [
    'targetLang' => 'French',
    'text' => 'Hello world',
])
    ->withProviderConfigs([
        'openai' => [
            'api_key' => $userApiKeys['openai'],
            // e.g. Azure OpenAI or an internal proxy.
            'url' => 'https://my-openai-proxy.example.com/v1',
        ],
    ])
    ->executeSync();

// ════════════════════════════════════════════════════
// Scenario D: Fall back to a system default key when no user keys are present
// ════════════════════════════════════════════════════

$builder = Prompt::load('translate', [
    'targetLang' => 'Japanese',
    'text' => 'Welcome aboard.',
]);

// Without withApiKeys() the system default (env / Prism config) is used.
if ($userApiKeys !== []) {
    $builder = $builder->withApiKeys($userApiKeys);
}

$translated = $builder->executeSync();

// ════════════════════════════════════════════════════
// Caveats
// ════════════════════════════════════════════════════
//
// - Do NOT reuse a Prompt instance after `withApiKeys` /
//   `withProviderConfigs`. One instance per request.
// - YAML without a `models[]` section keeps the legacy behaviour
//   (provider/model only).
// - When all supplied keys are empty strings the resolver still picks
//   the YAML system default, but if the env doesn't have that key
//   either, Prism returns an auth error. Decide upfront whether your
//   caller policy is "fall back to default key" vs "error out".
