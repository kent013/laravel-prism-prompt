<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending LLM call references (Codex Round 3 Critical 反映).
 *
 * phase 完了 transaction 時点で llm_call_logs にまだ記録されていない correlation_id
 * を保持する。PromptExecutionCompleted event listener (after commit) や resolver が
 * 後続で記録した際に拾って phase_llm_calls に紐付ける。
 */
return new class extends Migration
{
    public function up(): void
    {
        $phasesTable = $this->prefix().'job_phases';
        Schema::create($this->table(), function (Blueprint $table) use ($phasesTable): void {
            $table->id();
            $table->foreignId('phase_id')->constrained($phasesTable)->cascadeOnDelete();
            $table->string('correlation_id');
            $table->unsignedInteger('sequence');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['phase_id', 'correlation_id'], 'ppplcr_uniq_corr');
            $table->index('correlation_id', 'ppplcr_corr');
            $table->index('resolved_at', 'ppplcr_unresolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return $this->prefix().'pending_llm_call_resolutions';
    }

    private function prefix(): string
    {
        return (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');
    }
};
