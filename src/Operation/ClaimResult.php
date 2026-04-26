<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

/**
 * PromptOperation::claimOrFollow() の戻り値共通インターフェース (sealed 風)。
 * 実装は OwnerClaim / SameOperationFollower / BlockedBySerialization /
 *   AlreadyCompleted / AlreadyFailed / AlreadyCancelled の 6 種のみ。
 */
interface ClaimResult
{
    public function isOwner(): bool;

    public function isSameOperationFollower(): bool;

    public function isBlockedBySerialization(): bool;

    public function isAlreadyTerminal(): bool;
}
