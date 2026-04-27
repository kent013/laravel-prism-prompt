<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation\Exceptions;

use RuntimeException;

/**
 * v0.14.2 (audit Warning 観点 1 対応): 既存 PromptJob の lease semantics
 * (serialization_group / heartbeat_ttl_seconds) と claim 時の指定値が
 * 不一致の場合に throw する。
 *
 * このエラーが起きた時の対処:
 *   - lease semantics を変更する場合は `operationVersion` を bump する
 *     (旧 version の Job は新 claim 対象外になり namespace が分かれる)
 *   - 過去 Job の retention 期限を待ってから新 semantics を反映する
 */
class LeaseSemanticMismatchException extends RuntimeException {}
