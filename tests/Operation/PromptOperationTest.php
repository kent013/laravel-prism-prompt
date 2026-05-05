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

test('v0.13.0: ULID 主キーの scope モデルでも (int) cast collision なく 1 model = 1 scope_id で識別される', function () {
    // 同じ ULID 接頭辞 (現年内すべて "01..." を共有) を持つ別 scope を 2 つ作る。
    // 旧 v0.12.0 では (int) "01..." → 1 で両方が衝突していた。
    $scopeA = $this->makeFakeUlidScope();
    $scopeB = $this->makeFakeUlidScope();
    expect($scopeA->id)->not->toBe($scopeB->id);
    expect(strlen($scopeA->id))->toBeGreaterThan(20);  // ULID is 26 chars

    // 同 idempotency_key で claim — 別 scope なら別 Job として claim できるはず
    $claimA = PromptOperation::for($scopeA, 'training.send-message', 'shared-key')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $claimB = PromptOperation::for($scopeB, 'training.send-message', 'shared-key')
        ->withPhases(['phase-a'])
        ->claimOrFollow();

    expect($claimA)->toBeInstanceOf(OwnerClaim::class)
        ->and($claimB)->toBeInstanceOf(OwnerClaim::class);

    // 各 scope に対し 1 PromptJob が独立して作られる (collision なし)
    $jobs = PromptJob::query()->orderBy('id')->get();
    expect($jobs)->toHaveCount(2);
    expect($jobs[0]->scope_id)->toBe($scopeA->id)
        ->and($jobs[1]->scope_id)->toBe($scopeB->id);
});

test('v0.14.0 streamingPhase: body の yield が caller に forward され、終了後に commit transaction が走る', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();

    $events = [];
    foreach ($handle->streamingPhase('phase-a', function () {
        yield 'event-1';
        yield 'event-2';
        yield 'event-3';
    }) as $event) {
        $events[] = $event;
    }

    expect($events)->toBe(['event-1', 'event-2', 'event-3']);

    // body 終了後に phase commit が走り、子テーブルに row が入る
    $job = $handle->job()->fresh();
    expect($job->phases()->where('phase_name', 'phase-a')->count())->toBe(1);
});

test('v0.14.0 streamingPhase: body が Generator を返さないと TypeError', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();

    $caught = null;

    try {
        foreach ($handle->streamingPhase('phase-a', function (): void {
            // not a generator (no yield)
        }) as $_) {
        }
    } catch (TypeError $e) {
        $caught = $e;
    }
    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain('streamingPhase body must return a Generator');
});

test('v0.14.0 streamingPhase: body 内 throw で fail event 発火 + recordPhaseError', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();

    $caught = null;

    try {
        foreach ($handle->streamingPhase('phase-a', function () {
            yield 'event-1';

            throw new RuntimeException('boom');
        }) as $_) {
        }
    } catch (RuntimeException $e) {
        $caught = $e;
    }
    expect($caught?->getMessage())->toBe('boom');

    // phase commit は走らない (child row なし)
    $job = $handle->job()->fresh();
    expect($job->phases()->count())->toBe(0);
    // last_error_message が記録される
    expect($job->last_error_message)->toBe('boom');
});

test('v0.14.0 streamingPhase: 既に completed な phase はスキップ + onSkipped 呼び出し', function () {
    $scope = $this->makeFakeScope();
    /** @var OwnerClaim $claim */
    $claim = PromptOperation::for($scope, 'op', 'k')
        ->withPhases(['phase-a'])
        ->claimOrFollow();
    $handle = $claim->handle();

    // phase-a を 1 度実行
    foreach ($handle->streamingPhase('phase-a', function () {
        yield 'first';
    }) as $_) {
    }

    $skipped = false;
    foreach ($handle->streamingPhase('phase-a', function () {
        yield 'should-not-run';
    }, onSkipped: function () use (&$skipped): void {
        $skipped = true;
    }) as $event) {
        // 何も yield されない
        expect(false)->toBeTrue('completed phase should not yield');
    }

    expect($skipped)->toBeTrue();
});
