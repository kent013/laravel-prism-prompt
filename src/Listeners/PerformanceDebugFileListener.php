<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Listeners;

use Kent013\PrismPrompt\Events\PromptExecutionCompleted;

/**
 * Saves prompt/response/metadata files when prism-prompt.debug.save_files = true.
 * Re-implementation of old PerformanceLogger::saveDebugFiles as a Listener.
 */
final class PerformanceDebugFileListener
{
    public function handle(PromptExecutionCompleted $event): void
    {
        $directory = $this->getDebugDirectory($event->executionId);

        file_put_contents(
            "{$directory}/response.txt",
            $event->response->text
        );

        $metadata = [
            'execution_id' => $event->executionId,
            'timestamp' => now()->toIso8601String(),
            'prompt_class' => $event->promptClass,
            'prompt_template' => $event->promptTemplate,
            'provider' => $event->provider,
            'model' => $event->model,
            'duration_ms' => round($event->durationMs, 2),
            'prompt_tokens' => $event->totalUsage->promptTokens,
            'completion_tokens' => $event->totalUsage->completionTokens,
            'total_tokens' => $event->totalUsage->promptTokens + $event->totalUsage->completionTokens,
            'step_count' => $event->stepCount,
        ];

        file_put_contents(
            "{$directory}/metadata.json",
            (string) json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
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
