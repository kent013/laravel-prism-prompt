<?php

declare(strict_types=1);

use Kent013\PrismPrompt\Prompt;

class MetadataTestPrompt extends Prompt
{
    protected function getTemplatePath(): string
    {
        return __DIR__.'/../fixtures/prompts/common/greeting.yaml';
    }

    protected function parseResponse(string $responseText): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadataContext(): array
    {
        $ref = new ReflectionProperty($this, 'metadata_context');

        /** @var array<string, mixed> $value */
        $value = $ref->getValue($this);

        return $value;
    }
}

it('merges metadata across multiple withMetadata() calls', function () {
    $prompt = new MetadataTestPrompt;

    $prompt->withMetadata(['evaluation_id' => 1, 'persona_id' => 2]);
    $prompt->withMetadata(['persona_id' => 3, 'run_id' => 'r-1']);

    expect($prompt->getMetadataContext())->toBe([
        'evaluation_id' => 1,
        'persona_id' => 3,
        'run_id' => 'r-1',
    ]);
});

it('returns static for fluent chaining', function () {
    $prompt = new MetadataTestPrompt;

    expect($prompt->withMetadata(['x' => 1]))->toBe($prompt);
});

it('starts with an empty metadata context', function () {
    $prompt = new MetadataTestPrompt;

    expect($prompt->getMetadataContext())->toBe([]);
});
