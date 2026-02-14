<?php

/**
 * Example 4: Testing - メッセージ対応のアサーション
 *
 * Prompt::fake() でモック化し、system / user メッセージを
 * 個別に検証するテストパターン。
 *
 * v0.6 で追加されたアサーションメソッド:
 * - assertSystemMessageContains()  … system_prompt の内容を検証
 * - assertUserMessageContains()    … prompt の内容を検証
 * - assertHasSystemMessage()       … SystemMessage が送信されたか
 * - assertMessageCount()           … メッセージ数の検証
 * - assertPromptContains()         … 全メッセージから横断検索（既存互換）
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;

// ════════════════════════════════════════════════════
// Scenario A: Prompt::load() のテスト
// ════════════════════════════════════════════════════

// YAML: resources/prompts/greeting.yaml
// system_prompt: |
//   You are a friendly greeting assistant.
//   Always respond in JSON format.
// prompt: |
//   Say hello to {{ $userName }}.

it('sends system prompt and user message separately', function (): void {
    config()->set('prism-prompt.prompts_path', resource_path('prompts'));

    $fake = Prompt::fake([
        TextResponseFake::make()->withText('{"message": "Hello Alice!", "tone": "friendly"}'),
    ]);

    Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();

    // SystemMessage が送信されたことを確認
    $fake->assertHasSystemMessage();

    // SystemMessage の内容を検証（役割指示が含まれている）
    $fake->assertSystemMessageContains('friendly greeting assistant');
    $fake->assertSystemMessageContains('JSON format');

    // UserMessage の内容を検証（動的データが含まれている）
    $fake->assertUserMessageContains('Say hello to Alice');

    // メッセージ数の検証（system + user = 2）
    $fake->assertMessageCount(2);

    // 全メッセージ横断検索（既存の assertPromptContains も使える）
    $fake->assertPromptContains('Alice');
    $fake->assertPromptContains('greeting assistant');

    // プロバイダー・モデルの検証
    $fake->assertProvider('anthropic');
    $fake->assertModel('claude-sonnet-4-5-20250929');

    Prompt::stopFaking();
});

// ════════════════════════════════════════════════════
// Scenario B: サブクラス Prompt のテスト
// ════════════════════════════════════════════════════

// HintGenerationPrompt（Example 2 参照）のテスト例

it('generates hint with correct system and user messages', function (): void {
    $fake = HintGenerationPrompt::fake([
        TextResponseFake::make()->withText(json_encode([
            'hint' => '移行要件について確認しましょう',
            'examples' => [
                'クラウドへの移行で重視するポイントは何ですか？',
                '現在のデータ量はどのくらいですか？',
            ],
        ])),
    ]);

    $result = (new HintGenerationPrompt(
        conversationText: "トレーニー: 現在のシステム構成を教えてください\nNPC: オンプレミスで運用しています",
        progressText: "確認済み:\n- 現行システムの概要 (100%)",
        latestNpcMessage: 'オンプレミスで運用しています',
    ))->executeSync();

    // DTO が正しくパースされている
    expect($result)->toBeInstanceOf(HintResponseDto::class);
    expect($result->hint)->toBe('移行要件について確認しましょう');
    expect($result->examples)->toHaveCount(2);

    // system_prompt にJSON出力形式の指示が含まれている
    $fake->assertSystemMessageContains('ヒントを生成する役割');
    $fake->assertSystemMessageContains('"hint"');
    $fake->assertSystemMessageContains('"examples"');

    // prompt に動的データが含まれている
    $fake->assertUserMessageContains('現在のシステム構成を教えてください');
    $fake->assertUserMessageContains('現行システムの概要');

    $fake->assertCallCount(1);

    HintGenerationPrompt::stopFaking();
});

// ════════════════════════════════════════════════════
// Scenario C: system_prompt なしの YAML テスト
// ════════════════════════════════════════════════════

it('works without system_prompt in yaml', function (): void {
    // system_prompt フィールドがない YAML
    $fake = Prompt::fake([
        TextResponseFake::make()->withText('Hello!'),
    ]);

    // system_prompt がない場合、UserMessage のみ送信される
    Prompt::load('legacy-prompt', ['name' => 'Alice'])->executeSync();

    $fake->assertMessageCount(1); // UserMessage のみ
    $fake->assertUserMessageContains('Alice');

    Prompt::stopFaking();
});

// ════════════════════════════════════════════════════
// Scenario D: 複数回実行の検証
// ════════════════════════════════════════════════════

it('records multiple executions with messages', function (): void {
    $fake = Prompt::fake([
        TextResponseFake::make()->withText('Response 1'),
        TextResponseFake::make()->withText('Response 2'),
    ]);

    Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();
    Prompt::load('greeting', ['userName' => 'Bob'])->executeSync();

    $fake->assertCallCount(2);

    // assertRequest で個別のリクエストを検証
    $fake->assertRequest(function (array $recorded): void {
        // 1回目: Alice
        expect($recorded[0]['messages'])->toBeArray();
        $userMsg = end($recorded[0]['messages']);
        expect($userMsg->content)->toContain('Alice');

        // 2回目: Bob
        $userMsg2 = end($recorded[1]['messages']);
        expect($userMsg2->content)->toContain('Bob');
    });

    Prompt::stopFaking();
});
