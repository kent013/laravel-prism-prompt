<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Kent013\PrismPrompt\Operation\Models\PromptJob;
use Kent013\PrismPrompt\Operation\Models\PromptJobAttempt;

/**
 * `Prompt::withMetadata(...)` に渡す配列を組み立てるヘルパ。
 *
 * Codex Round 4 Suggestion 反映: phase コンテキストが必要な correlation_id は
 * 必ず PromptJobPhase::metadata() 経由で取得する (PromptOperationHandle 直からは
 * phase 情報が無いので correlationIdFromPhase() を呼んでも phase 名が空になる)。
 */
final class PromptMetadataBuilder
{
    /** @var array<string, mixed> */
    private array $arguments = [];

    public function __construct(
        private readonly PromptJob $job,
        private readonly ?PromptJobAttempt $attempt,
        private readonly string $scopeType,
        private readonly string $scopeId,
        private readonly ?string $phaseName = null,
    ) {}

    public function subjectFromScope(): self
    {
        $this->arguments['subject_type'] = $this->scopeType;
        $this->arguments['subject_id'] = $this->scopeId;

        return $this;
    }

    /**
     * 'prism-job:{job_id}:attempt:{attempt_id}:phase:{phase_name}' 形式で生成。
     * phase が無い場合は ':phase:' は省略される。
     */
    public function correlationIdFromPhase(?int $sequence = null): self
    {
        $parts = ["prism-job:{$this->job->id}"];
        if ($this->attempt !== null) {
            $parts[] = "attempt:{$this->attempt->id}";
        }
        if ($this->phaseName !== null) {
            $parts[] = "phase:{$this->phaseName}";
        }
        if ($sequence !== null) {
            $parts[] = "seq:{$sequence}";
        }
        $this->arguments['correlation_id'] = implode(':', $parts);

        return $this;
    }

    public function organizationId(int $id): self
    {
        $this->arguments['organization_id'] = $id;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function withExtra(array $extra): self
    {
        $this->arguments = array_merge($this->arguments, $extra);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArguments(): array
    {
        return $this->arguments;
    }
}
