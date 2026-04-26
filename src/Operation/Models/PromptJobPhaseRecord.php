<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $job_id
 * @property string $phase_name
 * @property int $phase_order
 * @property int $attempt_id
 * @property string|null $output_reference
 * @property CarbonImmutable $completed_at
 */
class PromptJobPhaseRecord extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        $prefix = (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');

        return $prefix.'job_phases';
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
            'phase_order' => 'integer',
        ];
    }

    /** @return BelongsTo<PromptJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(PromptJob::class, 'job_id');
    }

    /** @return BelongsTo<PromptJobAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PromptJobAttempt::class, 'attempt_id');
    }

    /** @return HasMany<PromptJobPhaseLlmCall, $this> */
    public function llmCalls(): HasMany
    {
        return $this->hasMany(PromptJobPhaseLlmCall::class, 'phase_id');
    }
}
