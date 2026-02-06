<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Kent013\PrismPrompt\PerformanceLogger;

beforeEach(function (): void {
    // Clean up test log file
    $logPath = storage_path('logs/prism-prompt.log');
    if (file_exists($logPath)) {
        unlink($logPath);
    }

    // Clean up test debug directory
    $debugPath = storage_path('prism-prompt-debug');
    if (is_dir($debugPath)) {
        File::deleteDirectory($debugPath);
    }
});

it('is disabled when debug.enabled is false', function (): void {
    Config::set('prism-prompt.debug.enabled', false);

    $logger = new PerformanceLogger;

    expect($logger->isEnabled())->toBeFalse();
});

it('is enabled when debug.enabled is true', function (): void {
    Config::set('prism-prompt.debug.enabled', true);

    $logger = new PerformanceLogger;

    expect($logger->isEnabled())->toBeTrue();
});

it('returns null when disabled', function (): void {
    Config::set('prism-prompt.debug.enabled', false);

    $logger = new PerformanceLogger;
    $executionId = $logger->startExecution(
        'TestPrompt',
        'anthropic',
        'claude-3-5-sonnet',
        'test prompt'
    );

    expect($executionId)->toBeNull();
});

it('returns execution id when enabled', function (): void {
    Config::set('prism-prompt.debug.enabled', true);

    $logger = new PerformanceLogger;
    $executionId = $logger->startExecution(
        'TestPrompt',
        'anthropic',
        'claude-3-5-sonnet',
        'test prompt'
    );

    expect($executionId)->not->toBeNull();
    expect($executionId)->toMatch('/^\d{14}-[a-f0-9]{6}-TestPrompt$/');
});

it('logs performance data', function (): void {
    Config::set('prism-prompt.debug.enabled', true);
    Config::set('prism-prompt.debug.log_channel', 'single');

    $logger = new PerformanceLogger;
    $executionId = $logger->startExecution(
        'TestPrompt',
        'anthropic',
        'claude-3-5-sonnet',
        'test prompt'
    );

    $logger->completeExecution(
        $executionId,
        'test response',
        1234.56,
        100,
        200
    );

    $logPath = storage_path('logs/laravel.log');
    expect(file_exists($logPath))->toBeTrue();

    $logContent = file_get_contents($logPath);
    expect($logContent)->toContain($executionId);
    expect($logContent)->toContain('TestPrompt');
    expect($logContent)->toContain('1234.56');
});

it('saves debug files when save_files is enabled', function (): void {
    Config::set('prism-prompt.debug.enabled', true);
    Config::set('prism-prompt.debug.save_files', true);
    Config::set('prism-prompt.debug.storage_path', storage_path('prism-prompt-debug'));

    $logger = new PerformanceLogger;
    $executionId = $logger->startExecution(
        'TestPrompt',
        'anthropic',
        'claude-3-5-sonnet',
        'test prompt'
    );

    $logger->completeExecution(
        $executionId,
        'test response',
        1234.56,
        100,
        200
    );

    $date = now()->format('Y-m-d');
    $debugPath = storage_path("prism-prompt-debug/{$date}/{$executionId}");

    expect(file_exists("{$debugPath}/prompt.txt"))->toBeTrue();
    expect(file_exists("{$debugPath}/response.txt"))->toBeTrue();
    expect(file_exists("{$debugPath}/metadata.json"))->toBeTrue();

    expect(file_get_contents("{$debugPath}/prompt.txt"))->toBe('test prompt');
    expect(file_get_contents("{$debugPath}/response.txt"))->toBe('test response');

    $metadata = json_decode(file_get_contents("{$debugPath}/metadata.json"), true);
    expect($metadata['execution_id'])->toBe($executionId);
    expect($metadata['prompt_tokens'])->toBe(100);
    expect($metadata['completion_tokens'])->toBe(200);
    expect($metadata['total_tokens'])->toBe(300);
});

it('does not save debug files when save_files is disabled', function (): void {
    Config::set('prism-prompt.debug.enabled', true);
    Config::set('prism-prompt.debug.save_files', false);

    $logger = new PerformanceLogger;
    $executionId = $logger->startExecution(
        'TestPrompt',
        'anthropic',
        'claude-3-5-sonnet',
        'test prompt'
    );

    $logger->completeExecution(
        $executionId,
        'test response',
        1234.56,
        100,
        200
    );

    $debugPath = storage_path('prism-prompt-debug');
    expect(is_dir($debugPath))->toBeFalse();
});

it('handles null token counts', function (): void {
    Config::set('prism-prompt.debug.enabled', true);
    Config::set('prism-prompt.debug.save_files', true);
    Config::set('prism-prompt.debug.storage_path', storage_path('prism-prompt-debug'));

    $logger = new PerformanceLogger;
    $executionId = $logger->startExecution(
        'TestPrompt',
        'anthropic',
        'claude-3-5-sonnet',
        'test prompt'
    );

    $logger->completeExecution(
        $executionId,
        'test response',
        1234.56,
        null,
        null
    );

    $date = now()->format('Y-m-d');
    $debugPath = storage_path("prism-prompt-debug/{$date}/{$executionId}");

    $metadata = json_decode(file_get_contents("{$debugPath}/metadata.json"), true);
    expect($metadata['prompt_tokens'])->toBeNull();
    expect($metadata['completion_tokens'])->toBeNull();
    expect($metadata['total_tokens'])->toBeNull();
});

it('does nothing on completeExecution when disabled', function (): void {
    Config::set('prism-prompt.debug.enabled', false);

    $logger = new PerformanceLogger;
    $executionId = $logger->startExecution(
        'TestPrompt',
        'anthropic',
        'claude-3-5-sonnet',
        'test prompt'
    );

    $logger->completeExecution(
        $executionId,
        'test response',
        1234.56,
        100,
        200
    );

    $debugPath = storage_path('prism-prompt-debug');
    expect(is_dir($debugPath))->toBeFalse();
});
