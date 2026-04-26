<?php

declare(strict_types=1);

namespace Kent013\PrismPrompt\Operation;

use Illuminate\Database\Eloquent\Model;

/**
 * Operation の entry point.
 *
 * 使い方:
 *   $claim = PromptOperation::for($scope, 'training.initial-message', 'fixed')
 *       ->withPhases(['generate-initial-message', 'analyze-progress'])
 *       ->withSerializationGroup("training-write:{$scope->id}")
 *       ->withHeartbeatTtl(90)
 *       ->claimOrFollow();
 *
 *   if ($claim instanceof OwnerClaim) { ... }
 *   if ($claim instanceof SameOperationFollower) { ... }
 *   if ($claim instanceof BlockedBySerialization) { ... }
 *   if ($claim instanceof AlreadyCompleted) { ... }
 */
final class PromptOperation
{
    public static function for(
        Model $scope,
        string $operationName,
        string $idempotencyKey,
        int $operationVersion = 1,
    ): PromptOperationBuilder {
        return new PromptOperationBuilder(
            scope: $scope,
            operationName: $operationName,
            idempotencyKey: $idempotencyKey,
            operationVersion: $operationVersion,
        );
    }
}
