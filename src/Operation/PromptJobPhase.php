<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

interface PromptJobPhase
{
    public function name(): string;

    public function attemptId(): int;

    public function attemptNumber(): int;

    public function isCompleted(): bool;

    /**
     * Phase 完了 transaction 内で join table に insert される (pending collection 経由)。
     */
    public function attachLlmCallLog(int $llmCallLogId): void;

    /**
     * correlation_id を pending collection に登録。
     * phase 完了 transaction 内で 2 段階解決:
     * 1. llm_call_logs に既に行があれば即 join table insert
     * 2. 無ければ pending_llm_call_resolutions に保存し、後段 resolver listener が
     *    PromptExecutionCompleted event 受信時に拾う
     *
     * library は llm_call_logs の存在を仮定しないため、テーブル名は config で指定される。
     */
    public function attachLlmCallByCorrelationId(string $correlationId): void;

    public function setOutputReference(string $reference): void;

    /**
     * @param  mixed  $value
     */
    public function setMetadata(string $key, $value): void;

    /**
     * Long-running phase 内で明示的に heartbeat を打つ。
     *
     * @throws Exceptions\StaleOwnershipException heartbeat 失敗 (owner_token mismatch) 時
     */
    public function heartbeat(): void;
}
