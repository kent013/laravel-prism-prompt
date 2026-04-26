<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase ↔ LLM call の N:N 紐付け行。
 * llm_call_log_id は app 側 (e.g. App\Models\LlmCallLog) を参照する。FK は library
 * では張らない (app テーブルに依存しないため)。
 *
 * @property int $id
 * @property int $phase_id
 * @property int $llm_call_log_id
 * @property int $sequence
 * @property CarbonImmutable|null $created_at
 */
class PromptJobPhaseLlmCall extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        $prefix = (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');

        return $prefix.'job_phase_llm_calls';
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'sequence' => 'integer',
            'llm_call_log_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PromptJobPhaseRecord, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(PromptJobPhaseRecord::class, 'phase_id');
    }
}
