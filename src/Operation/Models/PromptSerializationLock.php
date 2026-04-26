<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $scope_type
 * @property int $scope_id
 * @property string $serialization_group
 * @property int $job_id
 * @property string $owner_token
 * @property CarbonImmutable $acquired_at
 * @property CarbonImmutable $heartbeat_at
 * @property CarbonImmutable $expires_at
 */
class PromptSerializationLock extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        $prefix = (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');

        return $prefix.'serialization_locks';
    }

    protected function casts(): array
    {
        return [
            'acquired_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'scope_id' => 'integer',
            'job_id' => 'integer',
        ];
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->expires_at->lt($now);
    }
}
