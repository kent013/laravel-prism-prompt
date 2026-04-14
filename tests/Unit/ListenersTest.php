<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Listeners\PerformanceDebugFileListener;
use Kent013\PrismPrompt\Listeners\PerformanceLogListener;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

function makeCompletedEvent(): PromptExecutionCompleted
{
    $response = new TextResponse(
        steps: new Collection([]),
        text: 'fake-response-text',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(promptTokens: 12, completionTokens: 34),
        meta: new Meta(id: 'resp_abc', model: 'm'),
        messages: new Collection([]),
    );

    return new PromptExecutionCompleted(
        executionId: 'exec-listener',
        promptClass: 'App\\Prompts\\X',
        promptTemplate: 'x',
        provider: 'openai',
        model: 'gpt-4o',
        finishReason: FinishReason::Stop,
        stepCount: 2,
        totalUsage: $response->usage,
        durationMs: 99.25,
        requestId: 'req_1',
        response: $response,
        metadata: [],
    );
}

describe('PerformanceLogListener', function () {
    it('logs a JSON payload to the configured channel', function () {
        Log::shouldReceive('channel')->once()->with('prism-prompt')->andReturnSelf();
        Log::shouldReceive('info')->once()->with(Mockery::on(function ($payload): bool {
            $data = json_decode((string) $payload, true);

            return is_array($data)
                && $data['prompt_tokens'] === 12
                && $data['completion_tokens'] === 34
                && $data['total_tokens'] === 46
                && $data['step_count'] === 2
                && $data['provider'] === 'openai'
                && $data['model'] === 'gpt-4o'
                && $data['execution_id'] === 'exec-listener';
        }));

        (new PerformanceLogListener)->handle(makeCompletedEvent());
    });

    it('respects custom log channel from config', function () {
        config(['prism-prompt.debug.log_channel' => 'custom-channel']);

        Log::shouldReceive('channel')->once()->with('custom-channel')->andReturnSelf();
        Log::shouldReceive('info')->once();

        (new PerformanceLogListener)->handle(makeCompletedEvent());
    });
});

describe('PerformanceDebugFileListener', function () {
    beforeEach(function () {
        $this->tmpDir = sys_get_temp_dir().'/prism-prompt-test-'.uniqid();
    });

    afterEach(function () {
        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rii as $file) {
                $path = $file->getPathname();
                $file->isDir() ? rmdir($path) : unlink($path);
            }
            rmdir($this->tmpDir);
        }
    });

    it('writes response.txt and metadata.json under dated directory', function () {
        config(['prism-prompt.debug.storage_path' => $this->tmpDir]);

        (new PerformanceDebugFileListener)->handle(makeCompletedEvent());

        $date = now()->format('Y-m-d');
        $dir = "{$this->tmpDir}/{$date}/exec-listener";

        expect(file_get_contents("{$dir}/response.txt"))->toBe('fake-response-text');

        $meta = json_decode((string) file_get_contents("{$dir}/metadata.json"), true);
        expect($meta)->toBeArray();
        expect($meta['execution_id'])->toBe('exec-listener');
        expect($meta['prompt_tokens'])->toBe(12);
        expect($meta['completion_tokens'])->toBe(34);
        expect($meta['total_tokens'])->toBe(46);
        expect($meta['step_count'])->toBe(2);
        expect($meta['provider'])->toBe('openai');
    });
});
