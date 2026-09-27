<?php

// ARCHITECTURE.md §10.3 — "claim transaction -> COMMIT -> acquire attempt slot /
// create attempt -> call provider". The claim job is what starts the attempt.
// §10.7's `payouts:sweep-stranded` is recovery for a dispatch that was lost, and
// the last test here is what holds it to that.

use App\Domain\Payout\AttemptService;
use App\Domain\Provider\Scenario;
use App\Jobs\ClaimInstructorPayout;
use App\Jobs\SendPayoutAttempt;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\PayoutFixtures;
use Tests\Support\RecognitionFixtures;

/**
 * A ledger entry of any payable type, plus the §10.5 cache move the transaction
 * that posted it would have made. PayoutFixtures::recognize() writes 'release'
 * only, and the non-positive case below needs a negative release_correction.
 *
 * Prefixed, because Pest file-scope functions are global across the suite — the
 * same reason Inv04_PayoutClaimTest prefixes inv04Recognize.
 */
function claimDispatchEntry(
    int $instructorId,
    string $type,
    int $amountMinor,
    string $sourceRef,
    string $effectiveAt = '2026-03-31 00:00:00',
): LedgerEntry {
    RecognitionFixtures::balanceRow($instructorId);

    $entry = LedgerEntry::query()->create([
        'instructor_id' => $instructorId,
        'type' => $type,
        'amount_minor' => $amountMinor,
        'period_start' => substr($effectiveAt, 0, 7).'-01',
        'recognized_through_at' => $effectiveAt,
        'effective_at' => $effectiveAt,
        'source_ref' => $sourceRef,
        'payout_id' => null,
    ]);

    DB::table('instructor_balances')
        ->where('instructor_id', $instructorId)
        ->incrementEach([
            'recognized_minor' => $amountMinor,
            'available_minor' => $amountMinor,
        ]);

    return $entry;
}

/** The batch `payouts:run` would open for March, opened after every entry exists (§10.1). */
function claimDispatchBatchId(): int
{
    return PayoutFixtures::batch('2026-03')->id;
}

beforeEach(function () {
    test()->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));
});

it('payouts:run settles a payout end to end without the stranded sweeper', function () {
    // §10.3: claim -> COMMIT -> attempt -> provider, driven by nothing but
    // `payouts:run`. If the claim job did not dispatch the attempt, this payout
    // would still be `pending` at the end of the command and only
    // `payouts:sweep-stranded` — deliberately never run here — would move it.
    $instructor = 301;

    $provider = PayoutFixtures::scripted(Scenario::Success);

    claimDispatchEntry($instructor, 'release', 900, 'period:2026-03');

    $this->artisan('payouts:run', ['--month' => '2026-03'])->assertSuccessful();

    $payout = Payout::query()->where('instructor_id', $instructor)->sole();

    expect($payout->status)->toBe('settled')
        ->and($payout->amount_minor)->toBe(900);

    $attempt = PayoutAttempt::query()->where('payout_id', $payout->id)->sole();

    expect($attempt->status)->toBe('succeeded')
        ->and($attempt->attempt_no)->toBe(1);

    // §10.5: the claim moved available -> reserved, the settlement moved
    // reserved -> paid, and nothing is left held.
    expect(PayoutFixtures::buckets($instructor))
        ->toBe(['recognized' => 900, 'available' => 0, 'reserved' => 0, 'paid' => 900]);

    // §11.3 / §16.3: one call on the wire, one transfer created, under the one
    // key §9.1 derives for attempt 1.
    expect($provider->sendCalls())->toBe(1)
        ->and($provider->transferCount(AttemptService::idempotencyKey($payout->id, 1)))->toBe(1);
});

it('the claim dispatches one attempt for the payout it created, and none on replay', function () {
    $instructor = 302;

    Bus::fake([SendPayoutAttempt::class]);

    claimDispatchEntry($instructor, 'release', 500, 'period:2026-03');

    $batchId = claimDispatchBatchId();

    ClaimInstructorPayout::dispatchSync($instructor, $batchId);

    $payout = Payout::query()->where('instructor_id', $instructor)->sole();

    // §10.3: exactly one attempt job, for exactly this payout.
    Bus::assertDispatchedTimes(SendPayoutAttempt::class, 1);
    Bus::assertDispatched(
        SendPayoutAttempt::class,
        fn (SendPayoutAttempt $job) => $job->payoutId === $payout->id,
    );

    // §11.4, "command run twice": the replay finds the batch's existing payout
    // and returns its id. A non-null id is NOT permission to send — the count
    // below is what says so.
    ClaimInstructorPayout::dispatchSync($instructor, $batchId);

    expect(Payout::query()->where('instructor_id', $instructor)->count())->toBe(1);

    Bus::assertDispatchedTimes(SendPayoutAttempt::class, 1);
});

it('a claim that nets to zero or less dispatches nothing', function () {
    $instructor = 303;

    Bus::fake([SendPayoutAttempt::class]);

    claimDispatchEntry($instructor, 'release', 100, 'period:2026-03');
    claimDispatchEntry($instructor, 'release_correction', -150, 'refund:303');

    ClaimInstructorPayout::dispatchSync($instructor, claimDispatchBatchId());

    // §10.4: "sum <= 0 -> ROLLBACK, no payout row, entries stay unclaimed".
    // There is no payout to attempt, and nothing may be queued against one.
    expect(Payout::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->where('instructor_id', $instructor)->whereNotNull('payout_id')->count())->toBe(0);

    Bus::assertNotDispatched(SendPayoutAttempt::class);
});

it('the stranded sweeper still recovers a claim whose attempt dispatch was lost', function () {
    // §10.7 is recovery, not the normal path — which only means anything if it
    // still recovers. This is the case it exists for: the claim committed and
    // the attempt dispatch never landed.
    $instructor = 304;

    // Captured BEFORE the fake, because Bus::fake() replaces the container's
    // dispatcher and there is no way back to it afterwards.
    $realBus = app(Dispatcher::class);

    Bus::fake([SendPayoutAttempt::class]);

    claimDispatchEntry($instructor, 'release', 700, 'period:2026-03');

    $batchId = claimDispatchBatchId();

    ClaimInstructorPayout::dispatchSync($instructor, $batchId);

    $payout = Payout::query()->where('instructor_id', $instructor)->sole();

    // The payout is committed with its money reserved, and the dispatch is on
    // the floor: §10.7's predicate exactly.
    expect($payout->status)->toBe('pending')
        ->and($payout->attempt_count)->toBe(0)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(0);

    // The rerun that recovers a lost claim job finds the payout already there
    // and must NOT queue a send — the count stays at the one the fake swallowed.
    ClaimInstructorPayout::dispatchSync($instructor, $batchId);

    Bus::assertDispatchedTimes(SendPayoutAttempt::class, 1);

    Bus::swap($realBus);

    $provider = PayoutFixtures::scripted(Scenario::Success);

    $this->artisan('payouts:sweep-stranded')
        ->expectsOutputToContain('Re-dispatched 1 stranded payout(s)')
        ->assertSuccessful();

    // §10.7: one attempt, created by the recovery path. Not two — the §10.2
    // gate and UNIQUE(active_payout_id) are behind it either way.
    expect(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(1)
        ->and($provider->sendCalls())->toBe(1);
});
