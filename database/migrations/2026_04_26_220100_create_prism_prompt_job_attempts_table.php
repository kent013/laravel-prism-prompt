<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job の試行履歴 (append-only)。
 * 各 claim ごとに 1 行 insert、終了時に end_status と error 詳細を更新。
 */
return new class extends Migration
{
    public function up(): void
    {
        $jobsTable = $this->prefix().'jobs';
        Schema::create($this->table(), function (Blueprint $table) use ($jobsTable): void {
            $table->id();
            $table->foreignId('job_id')->constrained($jobsTable)->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->char('owner_token', 36);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->enum('end_status', ['completed', 'failed', 'stale_takeover', 'cancelled'])
                ->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();

            $table->unique(['job_id', 'attempt_number'], 'ppja_uniq_attempt');
            $table->index(['job_id', 'started_at'], 'ppja_chrono');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return $this->prefix().'job_attempts';
    }

    private function prefix(): string
    {
        return (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');
    }
};
