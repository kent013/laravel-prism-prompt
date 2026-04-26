<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $scope_type
 * @property int $scope_id
 * @property string $operation_name
 * @property int $operation_version
 * @property string $idempotency_key
 * @property string|null $serialization_group
 * @property string $status 'pending' | 'generating' | 'completed' | 'failed' | 'cancelled'
 * @property list<string> $phase_manifest
 * @property string|null $current_phase
 * @property string|null $owner_token
 * @property CarbonImmutable|null $heartbeat_at
 * @property int $heartbeat_ttl_seconds
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_reason
 * @property string|null $last_error_class
 * @property string|null $last_error_message
 * @property array<string, mixed>|null $metadata
 */
class PromptJob extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return $this->prefix().'jobs';
    }

    protected function casts(): array
    {
        return [
            'phase_manifest' => 'array',
            'metadata' => 'array',
            'heartbeat_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'operation_version' => 'integer',
            'heartbeat_ttl_seconds' => 'integer',
            'scope_id' => 'integer',
        ];
    }

    /** @return HasMany<PromptJobAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PromptJobAttempt::class, 'job_id');
    }

    /** @return HasMany<PromptJobPhaseRecord, $this> */
    public function phases(): HasMany
    {
        return $this->hasMany(PromptJobPhaseRecord::class, 'job_id');
    }

    public function isStale(?CarbonImmutable $now = null): bool
    {
        if ($this->status !== 'generating' || $this->heartbeat_at === null) {
            return false;
        }
        $now ??= CarbonImmutable::now();

        return $this->heartbeat_at->addSeconds($this->heartbeat_ttl_seconds)->lt($now);
    }

    public function isOwner(?string $token): bool
    {
        return $token !== null && $token === $this->owner_token;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<self> */
    public static function active(): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()->where('status', 'generating');
    }

    /**
     * status='generating' な行を全件返す (DB-portable)。
     * 厳密な stale 判定は PHP 側 isStale() で行う (heartbeat_ttl_seconds が行ごとに違うため
     * SQL での日付演算は DB driver 依存になりやすい)。
     *
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public static function stale(): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()->where('status', 'generating');
    }

    private function prefix(): string
    {
        return (string) config('prism-prompt.jobs.table_prefix', 'prism_prompt_');
    }
}
