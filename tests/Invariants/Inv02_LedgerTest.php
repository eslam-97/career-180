<?php

// Invariants 3, 4, 5, 6 — ARCHITECTURE.md §1.1, §10.5, §12

use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Outcome;
use App\Domain\Provider\Scenario;
use App\Domain\Recognition\ReleaseCalculator;
use App\Domain\Recognition\ReleaseService;
use App\Jobs\SendPayoutAttempt;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payout;
use Tests\Support\PayoutFixtures;
use Tests\Support\RecognitionFixtures;

/**
 * §1.1: a goodwill credit, a chargeback, a manual correction — an adjustment
 * that is NOT derivable from released(), which is exactly why it is excluded
 * from `posted`.
 *
 * §19 states that the allocator-level counter-allocation which would split such
 * an adjustment across instructors is not built, so there is no service to post
 * one. The ledger row and its cache movement are written here the way §10.5
 * requires any recognition entry to move the cache: recognized and available
 * both by the delta.
 */
function postRefundAdjustment(int $instructorId, int $amountMinor, string $sourceRef): LedgerEntry
{
    $entry = LedgerEntry::factory()->refundAdjustment($amountMinor, $sourceRef)->create([
        'instructor_id' => $instructorId,
        // §3.3: a correction does not advance time, so it carries the watermark.
        'recognized_through_at' => RecognitionFixtures::balance($instructorId)->recognized_through_at,
    ]);

    // §10.5: "any recognition entry posted — release, release_correction or
    // refund_adjustment — recognized += delta, available += delta".
    InstructorBalance::query()->whereKey($instructorId)->incrementEach([
        'recognized_minor' => $amountMinor,
        'available_minor' => $amountMinor,
    ]);

    return $entry;
}

/**
 * Stands in for §10.1's ClaimService and §10.6's SettlementService, which are
 * slice 4. It does only what those will do to the cache (§10.5): a claim moves
 * available → reserved, a settlement moves reserved → paid.
 *
 * @param  array<int, LedgerEntry>  $entries
 */
function claimEntriesInto(Payout $payout, array $entries, bool $settled): void
{
    $amount = 0;

    foreach ($entries as $entry) {
        LedgerEntry::query()->whereKey($entry->id)->update(['payout_id' => $payout->id]);
        $amount += $entry->amount_minor;
    }

    // §10.4: a committed payout always carries a positive amount.
    $payout->update(['amount_minor' => $amount]);

    InstructorBalance::query()->whereKey($payout->instructor_id)->incrementEach(
        $settled
            ? ['available_minor' => -$amount, 'paid_minor' => $amount]
            : ['available_minor' => -$amount, 'reserved_minor' => $amount],
    );
}

it('inv-03: recognized equals available plus reserved plus paid', function () {
    // Build an instructor with releases, a correction and a refund_adjustment,
    // one settled payout and one in-flight payout.
    // Assert the cached balance matches:
    //   available  = SUM(entries WHERE payout_id IS NULL)
    //   reserved   = SUM(entries claimed by non-terminal payouts)
    //   paid       = SUM(entries claimed by settled payouts)
    //   recognized = available + reserved + paid
    // recognized must include refund_adjustment. If it does not, this fails (§1.1).
    $instructorId = 3;
    $payment = RecognitionFixtures::allocation(
        $instructorId,
        9_334,
        RecognitionFixtures::utc('2026-03-01 00:00:00'),
        RecognitionFixtures::utc('2026-05-30 00:00:00'),
    );

    $release = app(ReleaseService::class);

    // Two scheduled releases: +3,111 then +3,111 (expected(30 Apr) = 6,222).
    $release->releaseScheduled($instructorId, RecognitionFixtures::utc('2026-03-31 00:00:00'));
    $march = LedgerEntry::query()->where('instructor_id', $instructorId)->orderBy('id')->sole();

    $release->releaseScheduled($instructorId, RecognitionFixtures::utc('2026-04-30 00:00:00'));
    $april = LedgerEntry::query()->where('instructor_id', $instructorId)->orderByDesc('id')->first();

    expect($march->amount_minor)->toBe(3_111)
        ->and($april->amount_minor)->toBe(3_111);

    // §6.1: access terminated on day 29 — a correction of −3,215 against the
    // 6,222 already posted.
    RecognitionFixtures::terminateAccess($payment, RecognitionFixtures::utc('2026-03-30 00:00:00'));
    $release->correct($instructorId, 'refund:9812', RecognitionFixtures::utc('2026-03-15 00:00:00'));

    // §1.1: the type the whole invariant turns on.
    postRefundAdjustment($instructorId, -30, 'refund:7000');

    // One settled payout and one still in flight, each claiming a release.
    claimEntriesInto(Payout::factory()->settled()->create(['instructor_id' => $instructorId]), [$march], settled: true);
    claimEntriesInto(Payout::factory()->create(['instructor_id' => $instructorId]), [$april], settled: false);

    $balance = RecognitionFixtures::balance($instructorId);

    // §12 level one, computed from the ledger rather than read from the cache.
    $available = (int) LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->whereNull('payout_id')
        ->sum('amount_minor');

    $reserved = (int) LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->whereIn('payout_id', Payout::query()
            ->whereIn('status', ['pending', 'in_progress', 'needs_review'])
            ->select('id'))
        ->sum('amount_minor');

    $paid = (int) LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->whereIn('payout_id', Payout::query()->where('status', 'settled')->select('id'))
        ->sum('amount_minor');

    expect($balance->available_minor)->toBe($available)
        ->and($balance->reserved_minor)->toBe($reserved)
        ->and($balance->paid_minor)->toBe($paid)
        // The identity itself.
        ->and($balance->recognized_minor)->toBe($available + $reserved + $paid);

    // §1.1: recognized covers ALL three entry types. Σ over every entry,
    // refund_adjustment included, is the same number.
    expect($balance->recognized_minor)->toBe(RecognitionFixtures::recognized($instructorId))
        // posted (3,007) and recognized (2,977) are deliberately different sums;
        // if they were equal here, refund_adjustment would have leaked into one.
        ->and($balance->recognized_minor)->toBe(2_977)
        ->and(RecognitionFixtures::posted($instructorId))->toBe(3_007);

    // §10.4: debt lives as a negative available and nets against future
    // recognition, so this bucket is allowed below zero — and is, here.
    expect($balance->available_minor)->toBeLessThan(0);
});

it('inv-04: every ledger entry is in exactly one bucket at all times', function () {
    // Assert no entry is stamped with a payout in a terminal-failed state.
    // Run after: a normal settlement, a failed payout, a rolled-back claim,
    // and a stranded payout swept by §10.7.
    // Also assert the three bucket queries partition the entry set with no overlap
    // and no gaps.
    $settled = 191;
    $failed = 192;
    $rolledBack = 193;
    $stranded = 194;
    $inFlightInstructor = 195;

    $this->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));

    // §10.7: one attempt allowed, so the definitive failure below exhausts the
    // ceiling and the payout is failed through §10.6 rather than retried.
    config()->set('payouts.attempt_ceiling', 1);

    $settlement = new SettlementService;
    $provider = PayoutFixtures::scripted();

    // 1. A normal settlement: reserved -> paid.
    $settledPayout = PayoutFixtures::claimedPayout($settled, 300);
    $slot = PayoutFixtures::acquire($settledPayout);
    $settlement->recordSuccess($slot->id, Outcome::success('txf_settled'));

    // 2. A failed payout: reserved -> available, entries un-stamped in the same
    //    transaction (§10.6). This is the state the invariant is really about —
    //    an entry stamped with a failed payout is in NO bucket at all.
    $provider->always(Scenario::Failure);
    $failedPayout = PayoutFixtures::claimedPayout($failed, 400);
    app()->call([new SendPayoutAttempt($failedPayout->id), 'handle']);

    // 3. A rolled-back claim (§10.4): release +100, correction −150, so the sum
    //    is negative and the whole transaction is discarded — no payout row, and
    //    the entries stay exactly where they were.
    PayoutFixtures::recognize($rolledBack, 100);

    $correction = LedgerEntry::factory()->correction(-150, 'refund:9814')->create([
        'instructor_id' => $rolledBack,
    ]);

    InstructorBalance::query()->whereKey($rolledBack)->incrementEach([
        'recognized_minor' => -150,
        'available_minor' => -150,
    ]);

    expect(PayoutFixtures::tryClaim($rolledBack))->toBeNull();

    // 4. A stranded payout — claimed, with its attempt-creation job never having
    //    landed — swept by §10.7 and carried to settlement.
    $provider->always(Scenario::Success);
    $strandedPayout = PayoutFixtures::claimedPayout($stranded, 500);

    expect(Payout::query()->findOrFail($strandedPayout->id)->status)->toBe('pending')
        ->and($strandedPayout->attempts()->count())->toBe(0);

    $this->artisan('payouts:sweep-stranded')->assertSuccessful();

    expect(Payout::query()->findOrFail($strandedPayout->id)->status)->toBe('settled');

    // 5. And one payout still in flight, so the reserved bucket is not empty
    //    when the partition below is counted. Claimed after the sweep, because
    //    the sweeper would otherwise have carried this one to settlement too.
    $inFlightPayout = PayoutFixtures::claimedPayout($inFlightInstructor, 600);
    PayoutFixtures::acquire($inFlightPayout);

    expect(Payout::query()->findOrFail($inFlightPayout->id)->status)->toBe('in_progress');

    // --- the invariant, over every entry in the table ---

    // An entry stamped with a failed payout belongs to no bucket: not available
    // (it has a payout_id), not reserved (the payout is terminal), not paid.
    // §10.6's single transaction is what makes this count zero.
    expect(PayoutFixtures::orphanedEntries())->toBe(0);

    $total = LedgerEntry::query()->count();

    $unclaimed = LedgerEntry::query()->whereNull('payout_id')->count();

    $inFlight = LedgerEntry::query()->whereIn('payout_id', Payout::query()
        ->whereIn('status', ['pending', 'in_progress', 'needs_review'])
        ->select('id'))->count();

    $paidOut = LedgerEntry::query()->whereIn('payout_id', Payout::query()
        ->where('status', 'settled')
        ->select('id'))->count();

    // No gaps: the three queries between them cover every row. No overlap: a row
    // has one payout_id and a payout has one status, so the counts can only add
    // up if each row was counted once.
    expect($total)->toBe(6)
        ->and($unclaimed + $inFlight + $paidOut)->toBe($total)
        // The failed payout's entry plus the two the rolled-back claim left
        // alone; the in-flight payout's entry; the settled and swept ones.
        ->and($unclaimed)->toBe(3)
        ->and($inFlight)->toBe(1)
        ->and($paidOut)->toBe(2);

    // §12 level one, per instructor: the cache agrees with the ledger in every
    // one of the four states above.
    foreach ([$settled, $failed, $rolledBack, $stranded, $inFlightInstructor] as $instructorId) {
        expect(PayoutFixtures::buckets($instructorId))
            ->toBe(PayoutFixtures::ledgerBuckets($instructorId));
    }

    expect(PayoutFixtures::buckets($settled))
        ->toBe(['recognized' => 300, 'available' => 0, 'reserved' => 0, 'paid' => 300])
        // The failed payout's money is back where it started, in one piece.
        ->and(PayoutFixtures::buckets($failed))
        ->toBe(['recognized' => 400, 'available' => 400, 'reserved' => 0, 'paid' => 0])
        // §10.5: "claim rolled back — none". The cache never moved.
        ->and(PayoutFixtures::buckets($rolledBack))
        ->toBe(['recognized' => -50, 'available' => -50, 'reserved' => 0, 'paid' => 0])
        ->and($correction->fresh()->payout_id)->toBeNull()
        ->and(PayoutFixtures::buckets($stranded))
        ->toBe(['recognized' => 500, 'available' => 0, 'reserved' => 0, 'paid' => 500])
        ->and(PayoutFixtures::buckets($inFlightInstructor))
        ->toBe(['recognized' => 600, 'available' => 0, 'reserved' => 600, 'paid' => 0]);
});

it('inv-05: posted release entries match the calculated total at the watermark', function () {
    // SUM(release + release_correction) === sum over allocations of released(alloc, W)
    // where W is instructor_balances.recognized_through_at.
    // Note: refund_adjustment is EXCLUDED from the left side (§1.1).
    $instructorId = 12;
    $termStart = RecognitionFixtures::utc('2026-03-01 00:00:00');
    $termEnd = RecognitionFixtures::utc('2026-05-30 00:00:00');

    $first = RecognitionFixtures::allocation($instructorId, 9_334, $termStart, $termEnd);
    RecognitionFixtures::allocation($instructorId, 4_100, $termStart, $termEnd);

    $release = app(ReleaseService::class);

    $assertLevelTwo = function () use ($instructorId, $termStart, $termEnd, $first): void {
        $watermark = RecognitionFixtures::balance($instructorId)->recognized_through_at;

        $accessEndsAt = $first->subscription()->first()->access_ends_at;

        // Computed here from the fixture's own numbers, not read back through
        // the service that wrote the rows.
        $expected = ReleaseCalculator::released(
            9_334,
            $termStart,
            $termEnd,
            $accessEndsAt === null ? null : RecognitionFixtures::utc($accessEndsAt->format('Y-m-d H:i:s')),
            RecognitionFixtures::utc($watermark->format('Y-m-d H:i:s')),
        ) + ReleaseCalculator::released(
            4_100,
            $termStart,
            $termEnd,
            null,
            RecognitionFixtures::utc($watermark->format('Y-m-d H:i:s')),
        );

        expect(RecognitionFixtures::posted($instructorId))->toBe($expected);
    };

    $release->releaseScheduled($instructorId, RecognitionFixtures::utc('2026-03-31 00:00:00'));
    $assertLevelTwo();

    $release->releaseScheduled($instructorId, RecognitionFixtures::utc('2026-04-30 00:00:00'));
    $assertLevelTwo();

    // A refund_adjustment must not disturb the identity, because it is excluded
    // from the left side. Posting one and re-asserting is the check.
    postRefundAdjustment($instructorId, -30, 'refund:7000');
    $assertLevelTwo();

    // A correction on one of the two allocations keeps it true as well.
    RecognitionFixtures::terminateAccess($first, RecognitionFixtures::utc('2026-04-10 00:00:00'));
    $release->correct($instructorId, 'refund:9812', RecognitionFixtures::utc('2026-04-10 00:00:00'));
    $assertLevelTwo();
});

it('inv-06: a refund adjustment is never reversed by a later release run', function () {
    // Post a goodwill refund_adjustment of -30 with access unchanged.
    // Run the scheduled release for the next period.
    // Assert the -30 entry is untouched and no +30 compensating entry appears.
    // This fails if `posted` wrongly includes refund_adjustment.
    $instructorId = 7;
    $termStart = RecognitionFixtures::utc('2026-03-01 00:00:00');
    $termEnd = RecognitionFixtures::utc('2026-05-01 00:00:00');

    RecognitionFixtures::allocation($instructorId, 102, $termStart, $termEnd);
    RecognitionFixtures::allocation($instructorId, 102, $termStart, $termEnd);

    $release = app(ReleaseService::class);
    $release->releaseScheduled($instructorId, RecognitionFixtures::utc('2026-03-31 00:00:00'));

    expect(RecognitionFixtures::posted($instructorId))->toBe(100);

    // §6.1: goodwill partial refund — access continues, so recognition is NOT
    // capped. Only the entitlement is reduced.
    $adjustment = postRefundAdjustment($instructorId, -30, 'refund:7000');

    $release->releaseScheduled($instructorId, RecognitionFixtures::utc('2026-04-30 00:00:00'));

    // §1.1's worked example: expected stays 200 because access did not change.
    //   posted excludes the adjustment -> 200 − 100 = +100   job leaves it alone
    //   posted includes the adjustment -> 200 −  70 = +130   job reverses it
    $april = LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->where('source_ref', 'period:2026-04')
        ->sole();

    expect($april->amount_minor)->toBe(100)
        ->and($april->type)->toBe('release');

    // The adjustment is untouched — same row, same amount, same type.
    $adjustment->refresh();

    expect($adjustment->amount_minor)->toBe(-30)
        ->and($adjustment->type)->toBe('refund_adjustment');

    // And nothing compensating was posted anywhere.
    expect(LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->where('amount_minor', 30)
        ->count())->toBe(0)
        ->and(LedgerEntry::query()->where('instructor_id', $instructorId)->count())->toBe(3);

    // §1.1: posted and recognized are deliberately different sums. The refund
    // survives in recognized; it never entered posted.
    expect(RecognitionFixtures::posted($instructorId))->toBe(200)
        ->and(RecognitionFixtures::recognized($instructorId))->toBe(170)
        ->and(RecognitionFixtures::balance($instructorId)->recognized_minor)->toBe(170);
});
