<?php

// Invariants 12, 19, 23 — ARCHITECTURE.md §6.2 Hazard A, §9.4, §10.2, §10.6

use App\Domain\Recognition\ReleaseService;
use App\Models\InstructorBalance;
use Tests\Concurrency\TwoConnections;
use Tests\Support\RecognitionFixtures;

uses(TwoConnections::class);

/**
 * §6.2 Hazard A's worked example, made concrete.
 *
 *     term 2026-03-01 .. 2026-05-01   =  61 whole days
 *     two allocations of 102 each
 *
 *     expected(31 Mar) = 2 × floor(102 × 30/61) = 100
 *     expected(30 Apr) = 2 × floor(102 × 60/61) = 200
 *
 * The watermark starts at the end of February, so both targets are ahead of it
 * and neither run is stopped by the Hazard B guard — leaving the lock as the
 * only thing standing between these two runs and 300.
 */
function hazardAInstructor(int $instructorId): void
{
    $termStart = RecognitionFixtures::utc('2026-03-01 00:00:00');
    $termEnd = RecognitionFixtures::utc('2026-05-01 00:00:00');

    RecognitionFixtures::allocation($instructorId, 102, $termStart, $termEnd);
    RecognitionFixtures::allocation($instructorId, 102, $termStart, $termEnd);

    // Set directly: with the term starting in March, a February release would
    // compute delta 0 and post nothing, so there is no run that can put the
    // watermark here. This is the starting state §6.2 describes, not a state
    // the job produces.
    InstructorBalance::query()
        ->whereKey($instructorId)
        ->update(['recognized_through_at' => '2026-02-28 00:00:00']);
}

function hazardAMarch(): DateTimeImmutable
{
    return RecognitionFixtures::utc('2026-03-31 00:00:00');
}

function hazardAApril(): DateTimeImmutable
{
    return RecognitionFixtures::utc('2026-04-30 00:00:00');
}

it('inv-12a: the release job holds the instructor balance row', function () {
    // A real implementation will:
    // 1. Create an instructor with allocations.
    // 2. Connection A starts a transaction and locks instructor_balances.
    // 3. Connection B attempts to lock the same instructor_balances row.
    // 4. Assert B blocks.
    // 5. Assert another instructor does NOT block.
    //
    // This must use two real database connections. Do not replace it with
    // two sequential calls on the same connection.
    $locked = 7;
    $other = 8;

    hazardAInstructor($locked);
    hazardAInstructor($other);

    // Move the watermark to 30 April first, so the March run below is stopped
    // by the Hazard B guard and writes NOTHING AT ALL.
    //
    // That is the whole point of choosing this target. A run that posts a row
    // also UPDATEs instructor_balances, and that UPDATE takes an exclusive row
    // lock by itself — so a test against a writing run would pass even with the
    // FOR UPDATE deleted, and would be proving nothing. With a run that writes
    // nothing, the only thing that can still be holding this row is the
    // FOR UPDATE the job takes before it reads the watermark (§9.4).
    (new ReleaseService('mysql'))->releaseScheduled($locked, hazardAApril());

    $entriesBefore = RecognitionFixtures::entries($locked)->count();

    $connA = $this->connA();

    // A's transaction wraps the release job's own, so the job's DB::transaction
    // nests as a savepoint and every row lock it took is still held here —
    // which is precisely how the job gets held "before commit".
    $connA->beginTransaction();

    try {
        (new ReleaseService('mysql'))->releaseScheduled($locked, hazardAMarch());

        // The guard fired: nothing was written, so nothing but the lock can
        // block B.
        expect(RecognitionFixtures::entries($locked)->count())->toBe($entriesBefore);

        // §9.4: the release job acquired the serialisation point, so nobody
        // else can have it.
        expect($this->blocks(fn () => $this->connB()
            ->table('instructor_balances')
            ->where('instructor_id', $locked)
            ->lockForUpdate()
            ->first()))->toBeTrue();

        // Contention is per-instructor: "jobs for different instructors never
        // wait for each other, so nothing slows down".
        expect($this->blocks(fn () => $this->connB()
            ->table('instructor_balances')
            ->where('instructor_id', $other)
            ->lockForUpdate()
            ->first()))->toBeFalse();
    } finally {
        $connA->rollBack();
    }
});

it('inv-12b: two release runs with different targets do not over-recognize', function () {
    // Watermark at end of February. Two allocations such that
    //   expected(March) = 100, expected(April) = 200.
    // Run the March release to completion, then the April release.
    // Assert the ledger total is 200, not 300.
    //
    // Then the real interleaving check: open the March run's transaction on A and
    // hold it before commit; assert the April run on B blocks (via blocks()).
    // Commit A, run B, assert total 200.
    //
    // WITHOUT the lock this produces 300 — both runs read `posted` as 0 from their
    // own snapshots. That is the bug (§6.2 Hazard A).
    $sequential = 7;
    $interleaved = 9;

    hazardAInstructor($sequential);
    hazardAInstructor($interleaved);

    // Sequential first. This is the case that passes trivially, which is why it
    // is not the test — it only establishes the expected numbers.
    $release = new ReleaseService('mysql');
    $release->releaseScheduled($sequential, hazardAMarch());

    expect(RecognitionFixtures::posted($sequential))->toBe(100);

    $release->releaseScheduled($sequential, hazardAApril());

    expect(RecognitionFixtures::posted($sequential))->toBe(200);

    // Now the interleaving, with two real connections.
    //
    // Prime B first: connB() is what sets innodb_lock_wait_timeout = 1 on that
    // session, so a genuine block surfaces in a second instead of sitting on
    // MySQL's 50-second default. ReleaseService('mysql_b') then reuses the same
    // connection instance, session variable and all.
    $this->connB();

    $connA = $this->connA();
    $connA->beginTransaction();

    $blocked = null;

    try {
        // Worker A: target March. Runs to the point of commit and stops there.
        (new ReleaseService('mysql'))->releaseScheduled($interleaved, hazardAMarch());

        // Worker B: target April, on a genuinely separate connection. Under
        // REPEATABLE READ and without the FOR UPDATE it would read posted = 0
        // from its own snapshot, compute 200 − 0, and post +200 on top of A's
        // +100. The lock is what stops it getting that far.
        $blocked = $this->blocks(fn () => (new ReleaseService('mysql_b'))
            ->releaseScheduled($interleaved, hazardAApril()));
    } finally {
        $connA->commit();
    }

    expect($blocked)->toBeTrue();

    // A's +100 is committed and B never posted anything.
    expect(RecognitionFixtures::posted($interleaved))->toBe(100);

    // B runs again now the lock is free: it reads posted = 100 under the lock
    // and posts the difference, not the whole of April.
    (new ReleaseService('mysql_b'))->releaseScheduled($interleaved, hazardAApril());

    expect(RecognitionFixtures::posted($interleaved))->toBe(200)
        ->and(RecognitionFixtures::posted($interleaved))->not->toBe(300)
        ->and(RecognitionFixtures::entries($interleaved))->toHaveCount(2)
        ->and(RecognitionFixtures::balance($interleaved)->recognized_through_at->format('Y-m-d H:i:s'))
        ->toBe('2026-04-30 00:00:00');
});

it('inv-19: attempt_count increments only when the slot is acquired')
    // A acquires the slot for payout #500 and holds the transaction open
    // (attempt 2 created, status 'sending').
    // B tries to acquire the slot on connection B.
    // Assert B blocks, and after A commits, B's attempt to acquire is REFUSED
    // (a live attempt exists) and attempt_count is still 2, not 3.
    //
    // The counter must not be burned by the loser. If it reaches 3, the gate is
    // relying on the unique constraint instead of doing the work itself (§10.2).
    ->todo();

it('inv-23: settlement and failure move the balance exactly once when replayed')
    // Settle a payout. Record the balance buckets.
    // Replay the exact same settlement call (same payout, same attempt).
    // Assert reserved and paid are UNCHANGED — the balance update is gated on the
    // payout transition's affected-row count (§10.6).
    //
    // Repeat for the failure path: fail a payout, replay, assert reserved and
    // available are unchanged and entries are not un-stamped twice.
    ->todo();
