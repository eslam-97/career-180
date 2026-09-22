<?php

// Invariants 13, 14, 15, 16, 26 — ARCHITECTURE.md §6.2, §12

use App\Domain\Recognition\ReleaseService;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Tests\Support\RecognitionFixtures;

/**
 * §6.2's Hazard B worked example, made concrete.
 *
 *     term 2026-03-01 .. 2026-05-01   =  61 whole days
 *     two allocations of 102 each
 *
 *     expected(31 Mar) = 2 × floor(102 × 30/61) = 2 × 50  = 100
 *     expected(30 Apr) = 2 × floor(102 × 60/61) = 2 × 100 = 200
 *
 * Two allocations rather than one, so the job is exercised as the Σ-over-
 * allocations aggregate §6.4 says it is, not as a single-row lookup.
 */
function hazardBInstructor(int $instructorId): void
{
    $termStart = RecognitionFixtures::utc('2026-03-01 00:00:00');
    $termEnd = RecognitionFixtures::utc('2026-05-01 00:00:00');

    RecognitionFixtures::allocation($instructorId, 102, $termStart, $termEnd);
    RecognitionFixtures::allocation($instructorId, 102, $termStart, $termEnd);
}

function hazardBMarch(): DateTimeImmutable
{
    return RecognitionFixtures::utc('2026-03-31 00:00:00');
}

function hazardBApril(): DateTimeImmutable
{
    return RecognitionFixtures::utc('2026-04-30 00:00:00');
}

it('inv-13: the watermark only moves forward and a covered target posts nothing', function () {
    // Run the scheduled release for April. Watermark -> 30 April.
    // Run it again for April: zero new rows, watermark unchanged.
    // Run it for March: zero new rows, watermark unchanged.
    $instructorId = 7;
    hazardBInstructor($instructorId);

    $release = app(ReleaseService::class);

    $release->releaseScheduled($instructorId, hazardBApril());

    expect(RecognitionFixtures::posted($instructorId))->toBe(200)
        ->and(RecognitionFixtures::entries($instructorId))->toHaveCount(1)
        ->and(RecognitionFixtures::balance($instructorId)->recognized_through_at->format('Y-m-d H:i:s'))
        ->toBe('2026-04-30 00:00:00');

    // A target already covered is a no-op, by the guard AND by the unique key.
    $release->releaseScheduled($instructorId, hazardBApril());

    expect(RecognitionFixtures::entries($instructorId))->toHaveCount(1)
        ->and(RecognitionFixtures::posted($instructorId))->toBe(200)
        ->and(RecognitionFixtures::balance($instructorId)->recognized_through_at->format('Y-m-d H:i:s'))
        ->toBe('2026-04-30 00:00:00');

    // §6.2: a scheduled release may only ADVANCE the watermark. March is
    // behind April, so it posts nothing and moves nothing.
    $release->releaseScheduled($instructorId, hazardBMarch());

    expect(RecognitionFixtures::entries($instructorId))->toHaveCount(1)
        ->and(RecognitionFixtures::posted($instructorId))->toBe(200)
        ->and(RecognitionFixtures::balance($instructorId)->recognized_through_at->format('Y-m-d H:i:s'))
        ->toBe('2026-04-30 00:00:00');
});

it('inv-14: a late scheduled run does not reverse a later catch-up', function () {
    // Skip March entirely.
    // Run April: posts +200 (expected 200, posted 0). Watermark -> 30 April.
    // NOW run March late.
    // Assert: no release_correction of -100 is created, ledger total stays 200.
    // This is the bug the monotonic guard exists to stop.
    $instructorId = 7;
    hazardBInstructor($instructorId);

    $release = app(ReleaseService::class);

    // March never runs. Cumulative delta heals it: April posts both months.
    $release->releaseScheduled($instructorId, hazardBApril());

    expect(RecognitionFixtures::posted($instructorId))->toBe(200);

    $release->releaseScheduled($instructorId, hazardBMarch());

    // Unguarded this computes expected(31 Mar) 100 − posted 200 = −100 and
    // posts a correction that silently undoes April's correct catch-up.
    expect(LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->where('type', 'release_correction')
        ->count())->toBe(0)
        ->and(RecognitionFixtures::posted($instructorId))->toBe(200)
        ->and(RecognitionFixtures::entries($instructorId))->toHaveCount(1)
        ->and(RecognitionFixtures::balance($instructorId)->recognized_through_at->format('Y-m-d H:i:s'))
        ->toBe('2026-04-30 00:00:00');
});

it('inv-15: an event correction leaves the watermark unchanged', function () {
    // Watermark at 31 March. Process a refund dated 15 March.
    // Assert a release_correction row is created,
    // AND instructor_balances.recognized_through_at is still 31 March,
    // AND the correction row's own recognized_through_at is 31 March (§3.3).
    //
    // §6.2's worked example exactly: 9,334 over a 90-day term, day-30 release
    // posts 3,111, a termination dated day 29 makes released(day 30) 3,007, so
    // the correction is −104.
    $instructorId = 3;
    $termStart = RecognitionFixtures::utc('2026-03-01 00:00:00');
    $termEnd = RecognitionFixtures::utc('2026-05-30 00:00:00');

    $payment = RecognitionFixtures::allocation($instructorId, 9_334, $termStart, $termEnd);

    $release = app(ReleaseService::class);
    $release->releaseScheduled($instructorId, hazardBMarch());

    expect(RecognitionFixtures::posted($instructorId))->toBe(3_111);

    // §6.1: access terminated on day 29, which is what caps recognition.
    RecognitionFixtures::terminateAccess($payment, RecognitionFixtures::utc('2026-03-30 00:00:00'));

    // §3.3's worked example: dated 15 March, processed 3 April, watermark at
    // 31 March — three different dates on one row.
    Carbon::setTestNow('2026-04-03 09:00:00');

    $release->correct($instructorId, 'refund:9812', RecognitionFixtures::utc('2026-03-15 00:00:00'));

    $correction = LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->where('type', 'release_correction')
        ->sole();

    expect($correction->amount_minor)->toBe(-104)
        ->and($correction->source_ref)->toBe('refund:9812')
        // §3.3: the horizon this row accounts for — held at W, because a
        // correction does not advance time.
        ->and($correction->recognized_through_at->format('Y-m-d H:i:s'))->toBe('2026-03-31 00:00:00')
        // §6.3: period_start is the POSTING period, so April.
        ->and($correction->period_start->format('Y-m-d'))->toBe('2026-04-01')
        // §3.3: the refund's own business date.
        ->and($correction->effective_at->format('Y-m-d H:i:s'))->toBe('2026-03-15 00:00:00');

    // The whole point: the cursor did not move.
    expect(RecognitionFixtures::balance($instructorId)->recognized_through_at->format('Y-m-d H:i:s'))
        ->toBe('2026-03-31 00:00:00')
        ->and(RecognitionFixtures::posted($instructorId))->toBe(3_007);

    // §10.5: the cache moved with the ledger, in the same transaction.
    expect(RecognitionFixtures::balance($instructorId)->recognized_minor)->toBe(3_007)
        ->and(RecognitionFixtures::balance($instructorId)->available_minor)->toBe(3_007);
});

it('inv-16: the cached watermark matches the ledger', function () {
    // After any sequence of scheduled runs and corrections:
    //   instructor_balances.recognized_through_at
    //     === MAX(ledger_entries.recognized_through_at) for that instructor
    // This is level-three reconciliation (§12).
    $instructorId = 3;
    $termStart = RecognitionFixtures::utc('2026-03-01 00:00:00');
    $termEnd = RecognitionFixtures::utc('2026-05-30 00:00:00');

    $payment = RecognitionFixtures::allocation($instructorId, 9_334, $termStart, $termEnd);

    $release = app(ReleaseService::class);

    $assertCursorMatchesLedger = function () use ($instructorId): void {
        $cursor = RecognitionFixtures::balance($instructorId)->recognized_through_at;
        $ledgerMax = LedgerEntry::query()
            ->where('instructor_id', $instructorId)
            ->max('recognized_through_at');

        expect($cursor?->format('Y-m-d H:i:s'))->toBe($ledgerMax);
    };

    $release->releaseScheduled($instructorId, hazardBMarch());
    $assertCursorMatchesLedger();

    $release->releaseScheduled($instructorId, hazardBApril());
    $assertCursorMatchesLedger();

    // A correction holds the cursor and stamps its own row with the same W, so
    // the identity survives an entry that is not a scheduled release.
    RecognitionFixtures::terminateAccess($payment, RecognitionFixtures::utc('2026-04-15 00:00:00'));
    $release->correct($instructorId, 'refund:5150', RecognitionFixtures::utc('2026-04-15 00:00:00'));

    expect(LedgerEntry::query()->where('instructor_id', $instructorId)->where('type', 'release_correction')->count())
        ->toBe(1);
    $assertCursorMatchesLedger();

    // A later scheduled run after a correction: the cursor advances again and
    // the newest row is the one it must equal.
    $release->releaseScheduled($instructorId, RecognitionFixtures::utc('2026-05-31 00:00:00'));
    $assertCursorMatchesLedger();
});

it('inv-26: running the release job twice for a posting period posts one row', function () {
    // Run the March scheduled release twice in sequence.
    // Assert exactly one ledger row with source_ref = 'period:2026-03'.
    // Blocked by BOTH the unique constraint and the watermark guard — assert the
    // row count, not which mechanism caught it.
    $instructorId = 7;
    hazardBInstructor($instructorId);

    $release = app(ReleaseService::class);

    $release->releaseScheduled($instructorId, hazardBMarch());
    $release->releaseScheduled($instructorId, hazardBMarch());

    expect(LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->where('source_ref', 'period:2026-03')
        ->count())->toBe(1)
        ->and(RecognitionFixtures::posted($instructorId))->toBe(100)
        ->and(RecognitionFixtures::balance($instructorId)->recognized_minor)->toBe(100);
});
