<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serialization Lock テーブル。
 * 同一 (scope_type, scope_id, serialization_group) 内で同時に 1 つの Job だけ
 * 走らせるための排他用 (Codex Round 3 Critical: advisory lock 不採用)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type');
            // v0.13.0: scope_id を string 化 (jobs テーブルと整合)
            $table->string('scope_id', 255);
            $table->string('serialization_group');
            $table->unsignedBigInteger('job_id');
            $table->char('owner_token', 36);
            $table->timestampTz('acquired_at');
            $table->timestampTz('heartbeat_at');
            $table->timestampTz('expires_at');

            $table->unique(['scope_type', 'scope_id', 'serialization_group'], 'ppsl_uniq_group');
            $table->index('expires_at', 'ppsl_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $prefix = (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');

        return $prefix.'serialization_locks';
    }
};
