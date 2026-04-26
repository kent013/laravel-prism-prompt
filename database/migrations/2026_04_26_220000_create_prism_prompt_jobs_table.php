<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-prism-prompt: Operation Job 基盤
 *
 * "LLM 呼び出しを含む operation を妨害 (リロード/失敗/プロセスクラッシュ/2タブ並行)
 * に対して堅牢で、途中から再開可能にする" ための基盤テーブル群。
 *
 * - Job 識別子: (scope_type, scope_id, operation_name, operation_version, idempotency_key) で UNIQUE
 * - 状態機械: pending → generating → completed | failed | cancelled
 * - CAS claim: owner_token + heartbeat_at による排他
 * - phase ごとの完了は子テーブル (prism_prompt_job_phases) で管理
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            // Job 識別 (scope は app 側のドメインモデル)
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id');
            $table->string('operation_name');
            $table->unsignedInteger('operation_version')->default(1);
            $table->string('idempotency_key');

            // 並行直列化対象識別子 (例: training-write:{progress_id})
            $table->string('serialization_group')->nullable();

            // 状態
            $table->enum('status', ['pending', 'generating', 'completed', 'failed', 'cancelled'])
                ->default('pending');

            // phase 順序の宣言 (builder の withPhases([...]) で渡される)
            $table->json('phase_manifest');
            $table->string('current_phase')->nullable();

            // CAS owner / heartbeat
            $table->char('owner_token', 36)->nullable();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->unsignedInteger('heartbeat_ttl_seconds')->default(90);

            // ライフサイクル時刻
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancelled_reason', 1000)->nullable();

            // エラー
            $table->string('last_error_class')->nullable();
            $table->text('last_error_message')->nullable();

            // 任意 metadata
            $table->json('metadata')->nullable();

            $table->timestamps();

            // (scope, operation_name, idempotency_key, version) で UNIQUE
            $table->unique(
                ['scope_type', 'scope_id', 'operation_name', 'idempotency_key', 'operation_version'],
                'ppj_uniq_op'
            );
            $table->index(['status', 'heartbeat_at'], 'ppj_status_heartbeat');
            $table->index(['scope_type', 'scope_id'], 'ppj_scope');
            $table->index(['serialization_group', 'status', 'scope_type', 'scope_id'], 'ppj_serialization');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $prefix = (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');

        return $prefix.'jobs';
    }
};
