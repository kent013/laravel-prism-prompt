<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 完了履歴 (Job 内の phase manifest 順に 1 行ずつ).
 * UNIQUE(job_id, phase_name) で同一 phase の重複完了を DB レベルで防止。
 */
return new class extends Migration
{
    public function up(): void
    {
        $jobsTable = $this->prefix().'jobs';
        $attemptsTable = $this->prefix().'job_attempts';
        Schema::create($this->table(), function (Blueprint $table) use ($jobsTable, $attemptsTable): void {
            $table->id();
            $table->foreignId('job_id')->constrained($jobsTable)->cascadeOnDelete();
            $table->string('phase_name');
            $table->unsignedInteger('phase_order');
            $table->foreignId('attempt_id')->constrained($attemptsTable)->cascadeOnDelete();
            $table->string('output_reference', 500)->nullable();  // app 側の opaque 値
            $table->timestampTz('completed_at');

            $table->timestamps();

            $table->unique(['job_id', 'phase_name'], 'ppjp_uniq_phase');
            $table->index('attempt_id', 'ppjp_attempt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return $this->prefix().'job_phases';
    }

    private function prefix(): string
    {
        return (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');
    }
};
