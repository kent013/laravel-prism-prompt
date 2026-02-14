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

it('loads system_prompt from yaml', function (): void {
    $path = __DIR__.'/../fixtures/prompts/common/greeting.yaml';

    $template = PromptTemplate::fromYaml($path);

    expect($template->systemPrompt)->not->toBeNull();
    expect($template->systemPrompt)->toContain('friendly greeting assistant');
});

it('returns null system_prompt when not specified in yaml', function (): void {
    $tempDir = sys_get_temp_dir().'/prism-prompt-test';
    @mkdir($tempDir, 0777, true);
    $tempFile = $tempDir.'/no-system.yaml';

    file_put_contents($tempFile, <<<'YAML'
name: no-system
prompt: "Hello {{ $name }}"
YAML);

    $template = PromptTemplate::fromYaml($tempFile);

    expect($template->systemPrompt)->toBeNull();

    @unlink($tempFile);
    @rmdir($tempDir);
});

it('loads system_prompt with blade syntax from yaml', function (): void {
    $tempDir = sys_get_temp_dir().'/prism-prompt-test';
    @mkdir($tempDir, 0777, true);
    $tempFile = $tempDir.'/with-system.yaml';

    file_put_contents($tempFile, <<<'YAML'
name: with-system
system_prompt: |
  You are {{ $role }}.
  Be helpful.
prompt: "Hello {{ $name }}"
YAML);

    $template = PromptTemplate::fromYaml($tempFile);

    expect($template->systemPrompt)->toContain('You are {{ $role }}');
    expect($template->systemPrompt)->toContain('Be helpful');

    @unlink($tempFile);
    @rmdir($tempDir);
});
