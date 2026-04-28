<?php

declare(strict_types=1);

/**
 * Example 07: PromptOperation (durable LLM operation coordinator)
 *
 * `PromptOperation` makes an operation that includes one or more LLM
 * calls robust against interruptions: page reload, LLM error, process
 * crash, two-tab race. The base `Prompt` class is unchanged — wrap the
 * call in `PromptOperation` and you get:
 *
 * - One job per `(scope + operation_name + idempotency_key)` tuple,
 *   second-and-later requests join as `SameOperationFollower`.
 * - Per-phase completion markers — re-running a partial operation skips
 *   already-finished phases.
 * - Heartbeat TTL — stale ownership is detected so another process can
 *   take over.
 * - Phase ↔ `llm_call_log` N:N association for audit traces.
 *
 * The example below models a training app's "initial-message" operation:
 * 1. Generate the facilitator NPC's opening message (1 LLM call).
 * 2. Run the initial progress-guide evaluation (1 LLM call).
 *
 * Each phase persists a draft, and `onCommit` promotes draft → active.
 */

use App\Models\Training\TrainingSessionMessage;
use App\Models\UserScenarioProgress;
use App\Services\AI\Prompts\AnalyzeProgressPrompt;
use App\Services\AI\Prompts\GenerateInitialMessagePrompt;
use App\Services\Billing\TrainingCreditService;
use Carbon\CarbonImmutable;
use Kent013\PrismPrompt\Operation\AlreadyCancelled;
use Kent013\PrismPrompt\Operation\AlreadyCompleted;
use Kent013\PrismPrompt\Operation\AlreadyFailed;
use Kent013\PrismPrompt\Operation\BlockedBySerialization;
use Kent013\PrismPrompt\Operation\OwnerClaim;
use Kent013\PrismPrompt\Operation\PromptJobPhase;
use Kent013\PrismPrompt\Operation\PromptOperation;
use Kent013\PrismPrompt\Operation\PromptOperationHandle;
use Kent013\PrismPrompt\Operation\SameOperationFollower;
use Kent013\PrismPrompt\Operation\WaitResult;

// Application domain model (UserScenarioProgress, etc.)
$progress = UserScenarioProgress::find(7);

for ($attempt = 0; $attempt < 3; $attempt++) {
    $claim = PromptOperation::for($progress, 'training.initial-message', 'fixed')
        ->withPhases(['generate-initial-message', 'analyze-progress'])
        ->withSerializationGroup("training-write:{$progress->id}")
        ->withHeartbeatTtl(90)
        ->claimOrFollow();

    if ($claim instanceof OwnerClaim) {
        $handle = $claim->handle();

        try {
            // Phase 1: generate the opening message
            $handle->phase(
                'generate-initial-message',
                body: function (PromptJobPhase $phase) use ($handle, $progress): void {
                    $metadata = $phase->metadata()
                        ->subjectFromScope()
                        ->correlationIdFromPhase()
                        ->toArguments();

                    $response = (new GenerateInitialMessagePrompt)
                        ->withMetadata($metadata)
                        ->executeSync();

                    // The event listener writes correlation_id into
                    // llm_call_logs; here we register the correlation_id
                    // as pending (two-step resolution).
                    $phase->attachLlmCallByCorrelationId($metadata['correlation_id']);

                    // Persist the side effect as a draft row.
                    $message = TrainingSessionMessage::create([
                        'user_scenario_progress_id' => $progress->id,
                        'job_id' => $handle->job()->id,
                        'attempt_id' => $phase->attemptId(),
                        'visibility' => 'draft',
                        'role' => 'assistant',
                        'content' => $response->content,
                        'occurred_at' => CarbonImmutable::now(),
                    ]);
                    $phase->setOutputReference("message:{$message->id}");
                },
                onCommit: function (PromptJobPhase $phase) use ($progress): void {
                    // Inside the phase-completion transaction:
                    // demote any current active row, promote this draft.
                    TrainingSessionMessage::query()
                        ->where('user_scenario_progress_id', $progress->id)
                        ->where('visibility', 'active')
                        ->update(['visibility' => 'inactive']);
                    TrainingSessionMessage::query()
                        ->where('user_scenario_progress_id', $progress->id)
                        ->where('attempt_id', $phase->attemptId())
                        ->where('visibility', 'draft')
                        ->update(['visibility' => 'active']);
                },
                onSkipped: function (): void {
                    // Phase 1 has already finished (resume case).
                    // Reload state into context here if you need it; no-op for now.
                },
            );

            // Phase 2: progress-guide evaluation
            $handle->phase('analyze-progress', function (PromptJobPhase $phase): void {
                $response = (new AnalyzeProgressPrompt)
                    ->withMetadata($phase->metadata()->correlationIdFromPhase()->toArguments())
                    ->executeSync();
                $phase->attachLlmCallByCorrelationId($phase->metadata()->correlationIdFromPhase()->toArguments()['correlation_id']);
                // ... persist ...
            });

            // All phases done → status='completed' + commit credits.
            $handle->complete(onCommit: function (PromptOperationHandle $h): void {
                app(TrainingCreditService::class)->commitOperation($h);
            });

            // Replay persisted messages over SSE etc. (omitted)
        } catch (Throwable $e) {
            $handle->fail($e, onFail: function (PromptOperationHandle $h): void {
                app(TrainingCreditService::class)->releaseOperation($h);
            });

            throw $e;
        }

        break;
    }

    if ($claim instanceof SameOperationFollower) {
        // Another request already owns this operation. Poll for completion.
        $result = $claim->handle()->follow();
        if ($result->isCompleted()) {
            // Read the persisted messages and replay over SSE.
            break;
        }
        if ($result->isStale()) {
            // Owner missed its heartbeat — try to claim it ourselves.
            continue;
        }
        if ($result->isFailed() || $result->isCancelled() || $result->isTimeout()) {
            // Return the appropriate error event.
            break;
        }
    }

    if ($claim instanceof BlockedBySerialization) {
        // Another operation in the same scope is running (e.g. send-message).
        // Wait for the lock to be released.
        $waitResult = $claim->waitForLockRelease(90);
        if ($waitResult === WaitResult::Timeout) {
            // Give up and return e.g. 425 Too Early.
            break;
        }

        continue;  // Released → retry our own claim.
    }

    if ($claim instanceof AlreadyCompleted) {
        // A previous attempt already completed. Replay persisted messages.
        break;
    }
    if ($claim instanceof AlreadyFailed) {
        // Previous attempt failed and retryFailed=false. Return the error.
        break;
    }
    if ($claim instanceof AlreadyCancelled) {
        // Explicitly cancelled.
        break;
    }
}
