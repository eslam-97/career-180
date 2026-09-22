<?php

// Invariants 18, 20, 21, 22, 24, 27 — ARCHITECTURE.md §10.2, §10.6, §10.7, §11

use App\Domain\Payout\AttemptService;
use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Outcome;
use App\Domain\Provider\Scenario;
use App\Jobs\PollPayoutAttempt;
use App\Jobs\SendPayoutAttempt;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\ReconciliationAlert;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PayoutFixtures;
use Tests\Support\RecognitionFixtures;

/**
 * The instant every test here runs at. Fixture entries are effective 31 March,
 * so the batch cutoff must be after them (§10.1).
 */
function inv05Now(): void
{
    test()->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));
}

/** @return array{recognized: int, available: int, reserved: int, paid: int} */
function inv05Claimed(int $amountMinor): array
{
    return ['recognized' => $amountMinor, 'available' => 0, 'reserved' => $amountMinor, 'paid' => 0];
}

function inv05Attempt(int $attemptId): PayoutAttempt
{
    return PayoutAttempt::query()->findOrFail($attemptId);
}

function inv05ClaimedEntryCount(int $payoutId): int
{
    return LedgerEntry::query()->where('payout_id', $payoutId)->count();
}

it('inv-18: at most one attempt per payout is in a non-terminal state', function () {
    // Create attempt 1 in 'sending'. Try to insert a second attempt for the same
    // payout directly, bypassing the service.
    // Assert the database rejects it (UNIQUE on the generated active_payout_id column).
    // Then mark attempt 1 'failed' and assert a second attempt CAN now be created.
    $instructor = 101;

    inv05Now();

    $payout = PayoutFixtures::claimedPayout($instructor, 300);
    $first = PayoutFixtures::acquire($payout);

    expect(inv05Attempt($first->id)->status)->toBe('sending')
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(1);

    // Straight past the §10.2 gate and into the table. §14: "at most one attempt
    // in flight expressed as a constraint rather than as a gate" — the backstop
    // that holds even if the gate is bypassed.
    $bypass = fn (int $attemptNo) => DB::table('payout_attempts')->insert([
        'payout_id' => $payout->id,
        'attempt_no' => $attemptNo,
        'idempotency_key' => hash('sha256', "bypass:{$payout->id}:{$attemptNo}"),
        'status' => 'sending',
        'request_payload' => json_encode(['amount_minor' => 300]),
        'started_at' => now()->utc(),
        'lease_expires_at' => now()->utc()->addMinutes(5),
        'created_at' => now()->utc(),
        'updated_at' => now()->utc(),
    ]);

    expect(fn () => $bypass(2))->toThrow(QueryException::class);

    // §10.2: and the gate refuses too, which is the ordering that matters —
    // "the constraint the mechanism and the gate the backstop" is the inverse of
    // what is wanted. The counter is untouched by the refusal (invariant 19).
    expect((new AttemptService)->acquire($payout->id))->toBeNull()
        ->and(Payout::query()->findOrFail($payout->id)->attempt_count)->toBe(1)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(1);

    // §10.2: 'unknown' is still non-terminal, so it blocks a second attempt
    // exactly as 'sending' does — that is what keeps a lease-swept attempt from
    // being resent underneath (§11.1).
    PayoutFixtures::forceAttemptStatus($first->id, 'unknown');

    expect(fn () => $bypass(2))->toThrow(QueryException::class)
        ->and((new AttemptService)->acquire($payout->id))->toBeNull();

    // Terminal: active_payout_id generates NULL, and MySQL permits many of those.
    PayoutFixtures::forceAttemptStatus($first->id, 'failed');

    $second = (new AttemptService)->acquire($payout->id);

    expect($second)->not->toBeNull()
        ->and($second->attemptNo)->toBe(2)
        ->and($second->idempotencyKey)->not->toBe($first->idempotencyKey)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(2)
        // The invariant itself: one non-terminal attempt, never two.
        ->and(PayoutAttempt::query()
            ->where('payout_id', $payout->id)
            ->whereIn('status', ['sending', 'unknown'])
            ->count())->toBe(1);
});

it('inv-20: a payout cannot be failed while an attempt is live or succeeded', function () {
    // Attempt in 'unknown'. Try to fail the payout. Assert it is refused and the
    // ledger entries stay claimed.
    // Attempt in 'sending'. Same.
    // Attempt 'succeeded'. Same.
    // Attempt 'failed' and 'unresolved' with none succeeded -> failure is allowed.
    inv05Now();

    $settlement = new SettlementService;

    // §10.6: "the attempt precondition is the fix for a real money bug". Without
    // it the later poll sets the attempt succeeded while the payout is already
    // failed and its entries released — the provider moved the money and the
    // system believes it did not.
    $refused = function (int $instructor, string $status) use ($settlement): void {
        $payout = PayoutFixtures::claimedPayout($instructor, 300);
        $slot = PayoutFixtures::acquire($payout);

        PayoutFixtures::forceAttemptStatus($slot->id, $status);

        expect($settlement->failPayout($payout->id))->toBeFalse()
            ->and(Payout::query()->findOrFail($payout->id)->status)->toBe('in_progress')
            // The money stays exactly where the claim put it.
            ->and(inv05ClaimedEntryCount($payout->id))->toBe(1)
            ->and(PayoutFixtures::buckets($instructor))->toBe(inv05Claimed(300))
            ->and(inv05Attempt($slot->id)->status)->toBe($status);
    };

    $refused(111, 'sending');
    $refused(112, 'unknown');
    $refused(113, 'succeeded');

    // §10.6: no attempt live, none succeeded — the transition is allowed, and
    // this is the half that proves the assertions above are not vacuous.
    $allowed = function (int $instructor, Closure $reachState) use ($settlement): void {
        $payout = PayoutFixtures::claimedPayout($instructor, 300);
        $slot = PayoutFixtures::acquire($payout);

        $reachState($slot->id);

        expect($settlement->failPayout($payout->id))->toBeTrue()
            ->and(Payout::query()->findOrFail($payout->id)->status)->toBe('failed')
            // §10.6: entries un-stamped, reserved -> available (invariant 32).
            ->and(inv05ClaimedEntryCount($payout->id))->toBe(0)
            ->and(PayoutFixtures::buckets($instructor))
            ->toBe(['recognized' => 300, 'available' => 300, 'reserved' => 0, 'paid' => 0]);
    };

    $allowed(114, fn (int $attemptId) => PayoutFixtures::forceAttemptStatus($attemptId, 'failed'));

    // §11.2: 'unresolved' is us giving up on a schedule, not the provider saying
    // no. A human may still resolve the payout as failed on outside evidence.
    $allowed(115, function (int $attemptId) use ($settlement): void {
        $settlement->recordUnknown($attemptId, Outcome::unknown());
        $settlement->markUnresolved($attemptId);

        expect(inv05Attempt($attemptId)->status)->toBe('unresolved');
    });
});

it('inv-21: a definitive outcome is recorded from sending, unknown and unresolved', function () {
    // Three separate cases. For each, deliver a definitive SUCCESS and assert the
    // attempt reaches 'succeeded'.
    // The 'unresolved' case is the one that matters — excluding it would silently
    // drop a confirmed transfer (§11.2).
    inv05Now();

    $settlement = new SettlementService;

    $case = function (int $instructor, string $from) use ($settlement): void {
        $payout = PayoutFixtures::claimedPayout($instructor, 300);
        $slot = PayoutFixtures::acquire($payout);

        // Each state reached the way §11 reaches it, not by a direct UPDATE.
        if ($from !== 'sending') {
            $settlement->recordUnknown($slot->id, Outcome::unknown());
        }

        if ($from === 'unresolved') {
            $settlement->markUnresolved($slot->id);
        }

        expect(inv05Attempt($slot->id)->status)->toBe($from);

        expect($settlement->recordSuccess($slot->id, Outcome::success("txf_{$instructor}")))->toBeTrue()
            ->and(inv05Attempt($slot->id)->status)->toBe('succeeded')
            ->and(inv05Attempt($slot->id)->provider_reference)->toBe("txf_{$instructor}")
            ->and(Payout::query()->findOrFail($payout->id)->status)->toBe('settled')
            // §10.5: reserved -> paid, exactly once.
            ->and(PayoutFixtures::buckets($instructor))
            ->toBe(['recognized' => 300, 'available' => 0, 'reserved' => 0, 'paid' => 300]);
    };

    $case(121, 'sending');
    $case(122, 'unknown');

    // §11.2: "a definitive answer arriving a day later is still recorded and
    // still authoritative. Excluding unresolved would silently drop a confirmed
    // transfer, which is the same class of bug as double-paying."
    $case(123, 'unresolved');
});

it('inv-22: a late success on a failed payout moves no money and raises an alert', function () {
    // Payout failed, entries released, claimed by a later batch.
    // Now deliver a definitive SUCCESS for the old attempt.
    // Assert: attempt -> 'succeeded', balance buckets UNCHANGED,
    // one reconciliation_alerts row of kind late_success_on_failed_payout.
    $instructor = 131;

    inv05Now();

    // §16.3: the provider never answers, so nothing is stored against the key
    // and every status query says UNKNOWN.
    $provider = PayoutFixtures::scripted(Scenario::TimeoutBeforeSend);
    $settlement = new SettlementService;

    $payout = PayoutFixtures::claimedPayout($instructor, 300);
    $slot = PayoutFixtures::acquire($payout);

    $settlement->recordUnknown($slot->id, Outcome::unknown());
    $settlement->markUnresolved($slot->id);

    expect(Payout::query()->findOrFail($payout->id)->status)->toBe('needs_review');

    // §11.2 / §11.3: "a human resolves an unresolved attempt as failed" — an
    // assertion about evidence OUTSIDE the system. Permitted, and recorded with
    // that evidence. The attempt stays 'unresolved', so polling continues.
    expect($settlement->failPayout($payout->id, 'operator: provider dashboard shows no transfer'))->toBeTrue()
        ->and(Payout::query()->findOrFail($payout->id)->status)->toBe('failed')
        ->and(inv05Attempt($slot->id)->status)->toBe('unresolved')
        ->and(inv05Attempt($slot->id)->resolution_evidence)->toContain('operator');

    // §11.3: "the entries return to available and are claimed by the next batch".
    $next = PayoutFixtures::claim($instructor);

    expect($next->amount_minor)->toBe(300)
        ->and(inv05ClaimedEntryCount($payout->id))->toBe(0)
        ->and(inv05ClaimedEntryCount($next->id))->toBe(1);

    $before = PayoutFixtures::buckets($instructor);

    // --- and *then* the provider confirms the original transfer succeeded ---
    $provider->resolveLate($slot->idempotencyKey, Outcome::success('txf_late'));

    app()->call([new PollPayoutAttempt($slot->id), 'handle']);

    $alert = ReconciliationAlert::query()->sole();

    expect(inv05Attempt($slot->id)->status)->toBe('succeeded')
        // §10.6: "it must NOT move money — the entries may already belong to
        // another payout". They do: they belong to $next.
        ->and(PayoutFixtures::buckets($instructor))->toBe($before)
        ->and(Payout::query()->findOrFail($payout->id)->status)->toBe('failed')
        ->and(Payout::query()->findOrFail($next->id)->status)->toBe('pending')
        ->and(inv05ClaimedEntryCount($next->id))->toBe(1)
        // §19: detected, alerted, and knowingly unrecoverable by machine.
        ->and($alert->kind)->toBe('late_success_on_failed_payout')
        ->and($alert->subject_type)->toBe('payout')
        ->and($alert->subject_id)->toBe($payout->id)
        ->and($alert->detail['provider_reference'])->toBe('txf_late')
        ->and($alert->detail['amount_minor'])->toBe(300);

    // A second poll re-raises nothing: the attempt is already succeeded, so the
    // conditional update affects zero rows and no second alert is written.
    app()->call([new PollPayoutAttempt($slot->id), 'handle']);

    expect(ReconciliationAlert::query()->count())->toBe(1)
        ->and(PayoutFixtures::buckets($instructor))->toBe($before);
});

it('inv-24: a needs_review payout is never re-dispatched by any sweeper', function () {
    // Payout in needs_review, attempt in unresolved, entries still claimed.
    // Run the stranded-payout sweeper and the lease sweeper.
    // Assert: no new attempt, attempt_count unchanged, status unchanged.
    $instructor = 141;

    inv05Now();

    $provider = PayoutFixtures::scripted(Scenario::TimeoutBeforeSend);
    $settlement = new SettlementService;

    $payout = PayoutFixtures::claimedPayout($instructor, 300);
    $slot = PayoutFixtures::acquire($payout);

    $settlement->recordUnknown($slot->id, Outcome::unknown());
    $settlement->markUnresolved($slot->id);

    // Even with the lease long expired, which is what the lease sweeper looks
    // for — its predicate is the attempt's status, not the payout's.
    PayoutFixtures::expireLease($slot->id);

    expect(Payout::query()->findOrFail($payout->id)->status)->toBe('needs_review');

    $sendsBefore = $provider->sendCalls();

    Queue::fake();

    $this->artisan('payouts:sweep-stranded')->assertSuccessful();
    $this->artisan('payouts:sweep-leases')->assertSuccessful();

    // §10.7: "needs_review is never re-dispatched and requires explicit human
    // resolution". Not queued, not acted on, not counted.
    Queue::assertNotPushed(SendPayoutAttempt::class);

    expect(Payout::query()->findOrFail($payout->id)->status)->toBe('needs_review')
        ->and(Payout::query()->findOrFail($payout->id)->attempt_count)->toBe(1)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(1)
        ->and(inv05Attempt($slot->id)->status)->toBe('unresolved')
        ->and($provider->sendCalls())->toBe($sendsBefore)
        // §12: needs_review is non-terminal for money — its entries stay
        // reserved, which is why it is counted in the reserved bucket.
        ->and(inv05ClaimedEntryCount($payout->id))->toBe(1)
        ->and(PayoutFixtures::buckets($instructor))->toBe(inv05Claimed(300));
});

it('inv-27: retrying an attempt sends nothing; a new attempt follows definitive failure', function () {
    // Case A: retry the SAME attempt (same job, same id). Assert zero additional
    //   provider send() calls.
    // Case B: attempt 1 reaches definitive 'failed'. Create attempt 2.
    //   Assert exactly one additional send(), with a DIFFERENT idempotency key.
    inv05Now();

    // The retry dispatch is captured rather than run, so each step is counted
    // where it happens instead of cascading inside one call.
    Queue::fake();

    // --- Case A ---
    // §16.3's hardest case: the transfer IS recorded and the call then throws,
    // so the attempt is left 'unknown' and live.
    $provider = PayoutFixtures::scripted(Scenario::TimeoutAfterSuccess);

    $retried = PayoutFixtures::claimedPayout(151, 300);

    app()->call([new SendPayoutAttempt($retried->id), 'handle']);

    $key = AttemptService::idempotencyKey($retried->id, 1);

    expect($provider->sendCalls())->toBe(1)
        ->and($provider->sentKeys())->toBe([$key])
        ->and($provider->transferCount($key))->toBe(1)
        ->and(PayoutAttempt::query()->where('payout_id', $retried->id)->sole()->status)->toBe('unknown');

    // The same job, retried mid-flight by the queue. §10.2's gate sees the live
    // attempt and the worker exits — nothing reaches the provider at all.
    app()->call([new SendPayoutAttempt($retried->id), 'handle']);
    app()->call([new SendPayoutAttempt($retried->id), 'handle']);

    expect($provider->sendCalls())->toBe(1)
        ->and($provider->transferCount($key))->toBe(1)
        ->and(Payout::query()->findOrFail($retried->id)->attempt_count)->toBe(1)
        ->and(PayoutAttempt::query()->where('payout_id', $retried->id)->count())->toBe(1);

    // §11.3's other layer, asserted on its own: even a request that does reach
    // the provider with a key it has already seen returns the stored result and
    // creates no second transfer.
    expect($provider->send(300, $key)->reference)->toBe($provider->status($key)->reference)
        ->and($provider->transferCount($key))->toBe(1);

    // --- Case B ---
    $failed = PayoutFixtures::claimedPayout(152, 400);

    $provider->script(Scenario::Failure, Scenario::Success);
    $sendsBefore = $provider->sendCalls();

    app()->call([new SendPayoutAttempt($failed->id), 'handle']);

    $firstKey = AttemptService::idempotencyKey($failed->id, 1);

    expect($provider->sendCalls())->toBe($sendsBefore + 1)
        ->and(PayoutAttempt::query()->where('payout_id', $failed->id)->sole()->status)->toBe('failed')
        // §11.4: "provider permanent failure -> new attempt, new key, entries
        // stay claimed".
        ->and(inv05ClaimedEntryCount($failed->id))->toBe(1)
        ->and($provider->transferCount($firstKey))->toBe(0);

    Queue::assertPushed(SendPayoutAttempt::class);

    app()->call([new SendPayoutAttempt($failed->id), 'handle']);

    $secondKey = AttemptService::idempotencyKey($failed->id, 2);

    expect($provider->sendCalls())->toBe($sendsBefore + 2)
        ->and($secondKey)->not->toBe($firstKey)
        ->and($provider->sentKeys())->toContain($secondKey)
        ->and($provider->transferCount($secondKey))->toBe(1)
        ->and(Payout::query()->findOrFail($failed->id)->status)->toBe('settled')
        ->and(Payout::query()->findOrFail($failed->id)->attempt_count)->toBe(2)
        ->and(PayoutFixtures::buckets(152))
        ->toBe(['recognized' => 400, 'available' => 0, 'reserved' => 0, 'paid' => 400]);
});
