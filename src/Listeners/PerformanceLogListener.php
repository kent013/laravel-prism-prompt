<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Listeners;

use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;

/**
 * Re-implementation of the old PerformanceLogger::logPerformance as an Event Listener.
 * Only registered when prism-prompt.debug.enabled = true.
 */
final class PerformanceLogListener
{
    public function handle(PromptExecutionCompleted $event): void
    {
        $logData = [
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

        $jsonString = json_encode($logData);
        if ($jsonString === false) {
            return;
        }

        $channel = config('prism-prompt.debug.log_channel', 'prism-prompt');
        if (! is_string($channel)) {
            $channel = 'prism-prompt';
        }

        Log::channel($channel)->info($jsonString);
    }
}
