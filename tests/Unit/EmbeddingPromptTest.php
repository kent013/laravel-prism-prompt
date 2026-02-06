<?php

declare(strict_types=1);

use Kent013\PrismPrompt\EmbeddingPrompt;
use Kent013\PrismPrompt\Testing\EmbeddingResponseFake;

class TestEmbeddingPrompt extends EmbeddingPrompt
{
    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/embedding.yaml';
    }
}

class TestEmbeddingPromptNoYaml extends EmbeddingPrompt
{
    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/nonexistent.yaml';
    }
}

it('fakes embedding execution and records requests', function (): void {
    $mockVector = [0.1, 0.2, 0.3, 0.4, 0.5];

    $fake = TestEmbeddingPrompt::fake([
        EmbeddingResponseFake::make()->withEmbedding($mockVector),
    ]);

    $prompt = new TestEmbeddingPrompt;
    $result = $prompt->executeSync('Hello world');

    expect($result)->toBe($mockVector);

    $fake->assertCallCount(1);
    $fake->assertTextContains('Hello world');

    TestEmbeddingPrompt::stopFaking();
});

it('fakes multiple embedding responses in sequence', function (): void {
    $vector1 = [0.1, 0.2, 0.3];
    $vector2 = [0.4, 0.5, 0.6];

    $fake = TestEmbeddingPrompt::fake([
        EmbeddingResponseFake::make()->withEmbedding($vector1),
        EmbeddingResponseFake::make()->withEmbedding($vector2),
    ]);

    $prompt = new TestEmbeddingPrompt;
    $result1 = $prompt->executeSync('First text');
    $result2 = $prompt->executeSync('Second text');

    expect($result1)->toBe($vector1);
    expect($result2)->toBe($vector2);

    $fake->assertCallCount(2);

    TestEmbeddingPrompt::stopFaking();
});

it('resolves provider and model from yaml', function (): void {
    $fake = TestEmbeddingPrompt::fake([
        EmbeddingResponseFake::make()->withEmbedding([0.1]),
    ]);

    $prompt = new TestEmbeddingPrompt;
    $prompt->executeSync('test');

    $fake->assertProvider('openai');
    $fake->assertModel('text-embedding-3-small');

    TestEmbeddingPrompt::stopFaking();
});

it('falls back to config defaults when yaml is missing', function (): void {
    $fake = TestEmbeddingPromptNoYaml::fake([
        EmbeddingResponseFake::make()->withEmbedding([0.1]),
    ]);

    $prompt = new TestEmbeddingPromptNoYaml;
    $prompt->executeSync('test');

    // Falls back to config defaults (openai / text-embedding-3-small)
    $fake->assertProvider('openai');
    $fake->assertModel('text-embedding-3-small');

    TestEmbeddingPromptNoYaml::stopFaking();
});

it('supports withApiKey', function (): void {
    $fake = TestEmbeddingPrompt::fake([
        EmbeddingResponseFake::make()->withEmbedding([0.1]),
    ]);

    $prompt = new TestEmbeddingPrompt;
    $result = $prompt->withApiKey('custom-key');

    expect($result)->toBe($prompt);

    $prompt->executeSync('test');
    $fake->assertCallCount(1);

    TestEmbeddingPrompt::stopFaking();
});

it('reports isFaking status correctly', function (): void {
    expect(TestEmbeddingPrompt::isFaking())->toBeFalse();

    TestEmbeddingPrompt::fake([]);
    expect(TestEmbeddingPrompt::isFaking())->toBeTrue();

    TestEmbeddingPrompt::stopFaking();
    expect(TestEmbeddingPrompt::isFaking())->toBeFalse();
});
