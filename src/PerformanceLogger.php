<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt;

use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Contracts\PerformanceLoggerInterface;
use Webmozart\Assert\Assert;

class PerformanceLogger implements PerformanceLoggerInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $executions = [];

    public function isEnabled(): bool
    {
        return (bool) config('prism-prompt.debug.enabled', false);
    }

    public function startExecution(
        string $promptClass,
        string $provider,
        string $model,
        string $prompt
    ): ?string {
        if (! $this->isEnabled()) {
            return null;
        }

        $executionId = $this->generateExecutionId($promptClass);

        $this->executions[$executionId] = [
            'start_time' => microtime(true),
            'prompt_class' => $promptClass,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
        ];

        return $executionId;
    }

    public function completeExecution(
        ?string $executionId,
        string $response,
        float $durationMs,
        ?int $promptTokens = null,
        ?int $completionTokens = null
    ): void {
        if (! $executionId || ! $this->isEnabled()) {
            return;
        }

        $execution = $this->executions[$executionId] ?? null;
        if (! $execution) {
            return;
        }

        $this->logPerformance(
            $executionId,
            $execution,
            $response,
            $durationMs,
            $promptTokens,
            $completionTokens
        );

        if (config('prism-prompt.debug.save_files', false)) {
            $this->saveDebugFiles(
                $executionId,
                $execution,
                $response,
                $durationMs,
                $promptTokens,
                $completionTokens
            );
        }

        unset($this->executions[$executionId]);
    }

    private function generateExecutionId(string $promptClass): string
    {
        $timestamp = now()->format('YmdHis');
        $random = substr(md5(uniqid('', true)), 0, 6);
        $className = class_basename($promptClass);

        return "{$timestamp}-{$random}-{$className}";
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function logPerformance(
        string $executionId,
        array $execution,
        string $response,
        float $durationMs,
        ?int $promptTokens,
        ?int $completionTokens
    ): void {
        $logData = [
            'execution_id' => $executionId,
            'timestamp' => now()->toIso8601String(),
            'prompt_class' => $execution['prompt_class'],
            'provider' => $execution['provider'],
            'model' => $execution['model'],
            'duration_ms' => round($durationMs, 2),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => ($promptTokens && $completionTokens)
                ? $promptTokens + $completionTokens
                : null,
        ];

        $jsonString = json_encode($logData);
        if ($jsonString === false) {
            return;
        }

        $channel = config('prism-prompt.debug.log_channel', 'prism-prompt');
        Log::channel($channel)->info($jsonString);
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function saveDebugFiles(
        string $executionId,
        array $execution,
        string $response,
        float $durationMs,
        ?int $promptTokens,
        ?int $completionTokens
    ): void {
        $directory = $this->getDebugDirectory($executionId);

        $promptText = $execution['prompt'];
        Assert::string($promptText, 'Prompt must be a string');

        file_put_contents(
            "{$directory}/prompt.txt",
            $promptText
        );

        file_put_contents(
            "{$directory}/response.txt",
            $response
        );

        $metadata = [
            'execution_id' => $executionId,
            'timestamp' => now()->toIso8601String(),
            'prompt_class' => $execution['prompt_class'],
            'provider' => $execution['provider'],
            'model' => $execution['model'],
            'duration_ms' => round($durationMs, 2),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => ($promptTokens && $completionTokens)
                ? $promptTokens + $completionTokens
                : null,
            'prompt_length' => mb_strlen($promptText),
            'response_length' => mb_strlen($response),
        ];

        file_put_contents(
            "{$directory}/metadata.json",
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function getDebugDirectory(string $executionId): string
    {
        $basePath = config('prism-prompt.debug.storage_path');
        if (! is_string($basePath)) {
            $basePath = storage_path('prism-prompt-debug');
        }

        $date = now()->format('Y-m-d');
        $path = "{$basePath}/{$date}/{$executionId}";

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }
}
