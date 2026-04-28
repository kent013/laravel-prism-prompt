<?php

/**
 * Example 4: Testing — message-aware assertions
 *
 * Mock LLM calls with `Prompt::fake()` and verify the system / user
 * messages independently.
 *
 * Assertions added in v0.6:
 * - assertSystemMessageContains()  ... checks system_prompt content
 * - assertUserMessageContains()    ... checks prompt content
 * - assertHasSystemMessage()       ... ensures a SystemMessage was sent
 * - assertMessageCount()           ... message count
 * - assertPromptContains()         ... cross-message search (legacy compat)
 */

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake;

// ════════════════════════════════════════════════════
// Scenario A: Testing Prompt::load()
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

    // A SystemMessage was dispatched.
    $fake->assertHasSystemMessage();

    // The SystemMessage carries the role instructions.
    $fake->assertSystemMessageContains('friendly greeting assistant');
    $fake->assertSystemMessageContains('JSON format');

    // The UserMessage carries the dynamic data.
    $fake->assertUserMessageContains('Say hello to Alice');

    // Message count = system + user = 2.
    $fake->assertMessageCount(2);

    // Cross-message search still works.
    $fake->assertPromptContains('Alice');
    $fake->assertPromptContains('greeting assistant');

    // Provider / model assertions.
    $fake->assertProvider('anthropic');
    $fake->assertModel('claude-sonnet-4-5-20250929');

    Prompt::stopFaking();
});

// ════════════════════════════════════════════════════
// Scenario B: Testing a Prompt subclass
// ════════════════════════════════════════════════════

// Test for HintGenerationPrompt (see Example 2).

it('generates hint with correct system and user messages', function (): void {
    $fake = HintGenerationPrompt::fake([
        TextResponseFake::make()->withText(json_encode([
            'hint' => 'Drill into the migration requirements',
            'examples' => [
                'What matters most when you move to the cloud?',
                'How large is your current dataset?',
            ],
        ])),
    ]);

    $result = (new HintGenerationPrompt(
        conversationText: "Trainee: Tell me about your current system.\nNPC: We run on-prem.",
        progressText: "Confirmed:\n- Current system overview (100%)",
        latestNpcMessage: 'We run on-prem.',
    ))->executeSync();

    // The DTO is parsed correctly.
    expect($result)->toBeInstanceOf(HintResponseDto::class);
    expect($result->hint)->toBe('Drill into the migration requirements');
    expect($result->examples)->toHaveCount(2);

    // The system_prompt declares the JSON schema.
    $fake->assertSystemMessageContains('hint');
    $fake->assertSystemMessageContains('"hint"');
    $fake->assertSystemMessageContains('"examples"');

    // The prompt carries the dynamic data.
    $fake->assertUserMessageContains('Tell me about your current system');
    $fake->assertUserMessageContains('Current system overview');

    $fake->assertCallCount(1);

    HintGenerationPrompt::stopFaking();
});

// ════════════════════════════════════════════════════
// Scenario C: YAML without `system_prompt`
// ════════════════════════════════════════════════════

it('works without system_prompt in yaml', function (): void {
    // YAML has no `system_prompt` field.
    $fake = Prompt::fake([
        TextResponseFake::make()->withText('Hello!'),
    ]);

    // Without system_prompt only a UserMessage is sent.
    Prompt::load('legacy-prompt', ['name' => 'Alice'])->executeSync();

    $fake->assertMessageCount(1); // UserMessage only.
    $fake->assertUserMessageContains('Alice');

    Prompt::stopFaking();
});

// ════════════════════════════════════════════════════
// Scenario D: Multiple executions
// ════════════════════════════════════════════════════

it('records multiple executions with messages', function (): void {
    $fake = Prompt::fake([
        TextResponseFake::make()->withText('Response 1'),
        TextResponseFake::make()->withText('Response 2'),
    ]);

    Prompt::load('greeting', ['userName' => 'Alice'])->executeSync();
    Prompt::load('greeting', ['userName' => 'Bob'])->executeSync();

    $fake->assertCallCount(2);

    // Inspect each request individually.
    $fake->assertRequest(function (array $recorded): void {
        // Call 1: Alice
        expect($recorded[0]['messages'])->toBeArray();
        $userMsg = end($recorded[0]['messages']);
        expect($userMsg->content)->toContain('Alice');

        // Call 2: Bob
        $userMsg2 = end($recorded[1]['messages']);
        expect($userMsg2->content)->toContain('Bob');
    });

    Prompt::stopFaking();
});
