<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Operation\Models\PendingLlmCallResolution;
use Kent013\PrismPrompt\Operation\Models\PromptJobPhaseLlmCall;

/**
 * PromptExecutionCompleted を購読し、対応する pending resolution を解決して
 * phase_llm_calls join に紐付ける。
 */
class ResolvePendingLlmCallReferences
{
    public function handle(PromptExecutionCompleted $event): void
    {
        if (! config('prism-prompt.jobs.enabled', true)) {
            return;
        }
        $correlationId = $event->metadata['correlation_id'] ?? null;
        if (! is_string($correlationId) || $correlationId === '') {
            return;
        }

        $logsTable = (string) config('prism-prompt.jobs.llm_call_logs_table', 'llm_call_logs');
        $row = DB::table($logsTable)->where('correlation_id', $correlationId)->first();
        if ($row === null) {
            return;
        }
        $logId = (int) $row->id;

        $pendings = PendingLlmCallResolution::query()
            ->where('correlation_id', $correlationId)
            ->whereNull('resolved_at')
            ->get();

        foreach ($pendings as $pending) {
            DB::transaction(function () use ($pending, $logId): void {
                PromptJobPhaseLlmCall::create([
                    'phase_id' => $pending->phase_id,
                    'llm_call_log_id' => $logId,
                    'sequence' => $pending->sequence,
                    'created_at' => CarbonImmutable::now(),
                ]);
                $pending->update(['resolved_at' => CarbonImmutable::now()]);
            });
        }
    }
}
