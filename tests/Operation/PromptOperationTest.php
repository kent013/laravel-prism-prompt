<?php

declare(strict_types=1);

use Kent013\PrismPrompt\Operation\AlreadyCompleted;
use Kent013\PrismPrompt\Operation\BlockedBySerialization;
use Kent013\PrismPrompt\Operation\Exceptions\IncompletePhaseException;
use Kent013\PrismPrompt\Operation\Exceptions\InvalidPhaseManifestException;
use Kent013\PrismPrompt\Operation\Exceptions\UnknownPhaseException;
use Kent013\PrismPrompt\Operation\Models\PromptJob;
use Kent013\PrismPrompt\Operation\Models\PromptJobAttempt;
use Kent013\PrismPrompt\Operation\Models\PromptJobPhaseRecord;
use Kent013\PrismPrompt\Operation\OwnerClaim;
use Kent013\PrismPrompt\Operation\PromptOperation;
use Kent013\PrismPrompt\Operation\SameOperationFollower;

test('claim 1 度目は OwnerClaim、2 度目は SameOperationFollower (heartbeat 内)', function () {
    $scope = $this->makeFakeScope();

    $first = PromptOperation::for($scope, 'training.initial-message', 'fixed')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $second = PromptOperation::for($scope, 'training.initial-message', 'fixed')
        ->withPhases(['phase-a'])
        ->claimOrFollow();

    expect($first)->toBeInstanceOf(OwnerClaim::class)
        ->and($second)->toBeInstanceOf(SameOperationFollower::class);

    $job = PromptJob::query()->first();
    expect($job)->not->toBeNull()
        ->and($job->status)->toBe('generating')
        ->and(PromptJobAttempt::query()->where('job_id', $job->id)->count())->toBe(1);
});

test('phase manifest が空だと InvalidPhaseManifestException', function () {
    $scope = $this->makeFakeScope();
    expect(fn () => PromptOperation::for($scope, 'op', 'k')->withPhases([])->claimOrFollow())
        ->toThrow(InvalidPhaseManifestException::class);
});

test('manifest 重複は InvalidPhaseManifestException', function () {
    $scope = $this->makeFakeScope();
    expect(fn () => PromptOperation::for($scope, 'op', 'k')->withPhases(['a', 'a']))
        ->toThrow(InvalidPhaseManifestException::class);
});

test('manifest 外の phase 名で phase() を呼ぶと UnknownPhaseException', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['known-phase'])
        ->claimOrFollow();
    $handle = $claim->handle();
    expect(fn () => $handle->phase('unknown-phase', fn () => null))
        ->toThrow(UnknownPhaseException::class);
});

test('phase 完了で子テーブルに行が入り、再呼出は onSkipped が呼ばれる', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();

    $bodyCalls = 0;
    $skippedCalls = 0;

    $handle->phase('phase-a', function () use (&$bodyCalls): void {
        $bodyCalls++;
    });

    $handle->phase('phase-a',
        body: function () use (&$bodyCalls): void {
            $bodyCalls++;
        },
        onSkipped: function () use (&$skippedCalls): void {
            $skippedCalls++;
        },
    );

    expect($bodyCalls)->toBe(1)
        ->and($skippedCalls)->toBe(1)
        ->and(PromptJobPhaseRecord::query()->where('phase_name', 'phase-a')->count())->toBe(1);
});

test('phase 完了 transaction 内で onCommit が呼ばれる', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();

    $commitCalled = false;
    $handle->phase('phase-a',
        body: function ($phase): void {
            $phase->setOutputReference('output:1');
        },
        onCommit: function ($phase) use (&$commitCalled): void {
            $commitCalled = true;
            // この時点で phase row は同 transaction 内で既に insert されている
            expect(PromptJobPhaseRecord::query()->where('phase_name', $phase->name())->exists())->toBeTrue();
        },
    );

    expect($commitCalled)->toBeTrue();
});

test('complete() は全 phase 完了でないと IncompletePhaseException', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a', 'phase-b'])
        ->claimOrFollow();
    $handle = $claim->handle();
    $handle->phase('phase-a', fn () => null);

    expect(fn () => $handle->complete())->toThrow(IncompletePhaseException::class);
});

test('complete() で全 phase 完了済なら status=completed', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();
    $handle->phase('phase-a', fn () => null);

    $handle->complete();

    $job = $handle->job()->fresh();
    expect($job->status)->toBe('completed')
        ->and($job->completed_at)->not->toBeNull();
});

test('complete 後の claimOrFollow は AlreadyCompleted を返す', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $claim->handle()->phase('phase-a', fn () => null);
    $claim->handle()->complete();

    $next = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();

    expect($next)->toBeInstanceOf(AlreadyCompleted::class);
});

test('fail() で status=failed, error 記録', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();
    $handle->fail(new RuntimeException('boom'));

    $job = $handle->job()->fresh();
    expect($job->status)->toBe('failed')
        ->and($job->last_error_class)->toBe(RuntimeException::class)
        ->and($job->last_error_message)->toBe('boom');
});

test('cancel() で status=cancelled, reason 保持', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();
    $handle->cancel('user requested');

    $job = $handle->job()->fresh();
    expect($job->status)->toBe('cancelled')
        ->and($job->cancelled_reason)->toBe('user requested');
});

test('failed Job は再 claim で OwnerClaim (retryFailed=true デフォルト)', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $claim->handle()->fail(new RuntimeException('first'));

    $next = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    expect($next)->toBeInstanceOf(OwnerClaim::class);

    $job = $next->handle()->job();
    $attempts = PromptJobAttempt::query()->where('job_id', $job->id)->count();
    expect($attempts)->toBe(2);
});

test('SerializationGroup: 同じ group の別 operation は BlockedBySerialization', function () {
    $scope = $this->makeFakeScope();
    PromptOperation::for($scope, 'op-a', 'k1')
        ->withPhases(['p'])
        ->withSerializationGroup('g1')
        ->claimOrFollow();

    $second = PromptOperation::for($scope, 'op-b', 'k2')
        ->withPhases(['p'])
        ->withSerializationGroup('g1')
        ->claimOrFollow();

    expect($second)->toBeInstanceOf(BlockedBySerialization::class);
});

test('manifest 順序通りに phase が実行できる', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a', 'phase-b'])
        ->claimOrFollow();
    $handle = $claim->handle();
    $handle->phase('phase-a', fn () => null);
    $handle->phase('phase-b', fn () => null);
    $handle->complete();
    expect($handle->job()->fresh()->status)->toBe('completed');
});
