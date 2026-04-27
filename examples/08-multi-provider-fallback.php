<?php

/**
 * Example 8: Multi-provider Fallback (BYOK)
 *
 * SaaS で「ユーザー自身の API key を持ち込ませる (BYOK)」UX を提供したいとき、
 * どの provider key が来るかは事前に分からない。
 * YAML に `models[]` を priority 順で並べておけば、`withApiKeys()` で渡された
 * 鍵のうち priority が最も高い provider が自動選択される。
 *
 * このサンプルは「ユーザーが Anthropic / OpenAI / Google のどれか 1 つ以上の
 * 鍵を提供してくる」想定で、サーバー側のロジックは provider 非依存に書く。
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;

// ── YAML テンプレート ──────────────────────────────
// resources/prompts/translate.yaml
//
// name: translate
//
// # System default — どのユーザー鍵も来なかった場合に使う社内 fallback
// provider: anthropic
// model: claude-sonnet-4-5-20250929
// max_tokens: 1024
// temperature: 0.2
//
// # Multi-provider 候補。lower priority = 優先
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
// Scenario A: ユーザーが 1 つだけ鍵を持っている
// ════════════════════════════════════════════════════

// User has only OpenAI key. YAML preferred Anthropic, but the resolver
// falls back to OpenAI automatically (priority 2 > priority 3 google,
// and anthropic key is missing).
$translated = Prompt::load('translate', [
    'targetLang' => '日本語',
    'text' => 'The quick brown fox jumps over the lazy dog.',
])
    ->withApiKeys([
        'openai' => $userApiKeys['openai'] ?? '',
    ])
    ->executeSync();

// ════════════════════════════════════════════════════
// Scenario B: 複数鍵 — Anthropic が選ばれる
// ════════════════════════════════════════════════════

$translated = Prompt::load('translate', [
    'targetLang' => 'English',
    'text' => '量子コンピューティングは古典的なコンピューティングを置き換えるものではない',
])
    ->withApiKeys([
        'anthropic' => $userApiKeys['anthropic'] ?? '',
        'openai' => $userApiKeys['openai'] ?? '',
        'google' => $userApiKeys['google'] ?? '',
    ])
    ->executeSync();
// → priority 1 の anthropic が選ばれる

// ════════════════════════════════════════════════════
// Scenario C: provider config まで上書きしたい
// ════════════════════════════════════════════════════

$translated = Prompt::load('translate', [
    'targetLang' => 'French',
    'text' => 'Hello world',
])
    ->withProviderConfigs([
        'openai' => [
            'api_key' => $userApiKeys['openai'],
            // 例: Azure OpenAI / 自社 proxy 経由
            'url' => 'https://my-openai-proxy.example.com/v1',
        ],
    ])
    ->executeSync();

// ════════════════════════════════════════════════════
// Scenario D: 鍵が 1 つも来なかったら社内 default 鍵で fallback
// ════════════════════════════════════════════════════

$builder = Prompt::load('translate', [
    'targetLang' => 'Japanese',
    'text' => 'Welcome aboard.',
]);

// withApiKeys を呼ばなければ system default (env / config の prism 設定) で動く
if ($userApiKeys !== []) {
    $builder = $builder->withApiKeys($userApiKeys);
}

$translated = $builder->executeSync();

// ════════════════════════════════════════════════════
// 注意点
// ════════════════════════════════════════════════════
//
// - `withApiKeys` / `withProviderConfigs` を呼んだ Prompt インスタンスは
//   再利用しないこと (1 リクエスト 1 インスタンス)。
// - `models[]` がない YAML は従来動作 (provider/model のみ)。
// - 全鍵が空文字列なら resolver は YAML system default で起動するが、
//   その鍵が env に無ければ Prism 側で auth エラーになる。
//   呼び出し側で「ユーザー鍵 0 のときは default に逃がす vs エラーを返す」
//   を決めること。
