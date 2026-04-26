<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase ↔ LLM call の N:N 紐付け。
 *
 * llm_call_log_id は app 側 (e.g. App\Models\LlmCallLog) を参照する想定だが、
 * library が app テーブルに依存しないよう FK は張らず INT 列としてのみ保持する
 * (Codex Round 3 Critical 反映)。
 */
return new class extends Migration
{
    public function up(): void
    {
        $phasesTable = $this->prefix().'job_phases';
        Schema::create($this->table(), function (Blueprint $table) use ($phasesTable): void {
            $table->id();
            $table->foreignId('phase_id')->constrained($phasesTable)->cascadeOnDelete();
            $table->unsignedBigInteger('llm_call_log_id');  // app 側 FK は app 側 migration で別途張る
            $table->unsignedInteger('sequence');
            $table->timestamp('created_at')->nullable();

            $table->unique(['phase_id', 'sequence'], 'ppjpl_uniq_seq');
            $table->index('llm_call_log_id', 'ppjpl_log');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return $this->prefix().'job_phase_llm_calls';
    }

    private function prefix(): string
    {
        return (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');
    }
};
