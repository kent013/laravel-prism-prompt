<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $job_id
 * @property int $attempt_number
 * @property string $owner_token
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $end_status
 * @property string|null $error_class
 * @property string|null $error_message
 * @property string|null $error_trace
 */
class PromptJobAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        $prefix = (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');

        return $prefix.'job_attempts';
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'attempt_number' => 'integer',
        ];
    }

    /** @return BelongsTo<PromptJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(PromptJob::class, 'job_id');
    }
}
