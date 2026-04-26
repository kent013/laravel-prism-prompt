<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 完了 transaction 時点で llm_call_logs に未記録の correlation_id を保持する
 * 作業テーブル。後段 listener が記録時に拾って phase_llm_calls に紐付ける。
 *
 * @property int $id
 * @property int $phase_id
 * @property string $correlation_id
 * @property int $sequence
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 */
class PendingLlmCallResolution extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        $prefix = (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');

        return $prefix.'pending_llm_call_resolutions';
    }

    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<PromptJobPhaseRecord, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(PromptJobPhaseRecord::class, 'phase_id');
    }
}
