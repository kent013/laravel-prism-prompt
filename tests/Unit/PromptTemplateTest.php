<?php

declare(strict_types=1);

use Kent013\PrismPrompt\PromptTemplate;

it('loads template from yaml file', function (): void {
    $path = __DIR__.'/../fixtures/prompts/common/greeting.yaml';

    $template = PromptTemplate::fromYaml($path);

    expect($template->name)->toBe('greeting');
    expect($template->provider)->toBe('anthropic');
    expect($template->model)->toBe('claude-sonnet-4-5-20250929');
    expect($template->maxTokens)->toBe(1024);
    expect($template->temperature)->toBe(0.5);
    expect($template->template)->toContain('Say hello to');
});

it('throws exception for non-existent file', function (): void {
    PromptTemplate::fromYaml('/non/existent/path.yaml');
})->throws(InvalidArgumentException::class);

it('uses config defaults when yaml values are not specified', function (): void {
    // Create a minimal YAML without provider/model
    $tempDir = sys_get_temp_dir().'/prism-prompt-test';
    @mkdir($tempDir, 0777, true);
    $tempFile = $tempDir.'/minimal.yaml';

    file_put_contents($tempFile, <<<'YAML'
name: minimal
prompt: "Hello {{ $name }}"
YAML);

    $template = PromptTemplate::fromYaml($tempFile);

    expect($template->name)->toBe('minimal');
    expect($template->provider)->toBe('anthropic');
    expect($template->maxTokens)->toBe(4096);

    @unlink($tempFile);
    @rmdir($tempDir);
});
