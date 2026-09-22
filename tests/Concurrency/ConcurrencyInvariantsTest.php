<?php

// Invariants 12, 19, 23 — ARCHITECTURE.md §6.2 Hazard A, §9.4, §10.2, §10.6

use App\Domain\Payout\AttemptService;
use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Outcome;
use App\Domain\Recognition\ReleaseService;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use Tests\Concurrency\TwoConnections;
use Tests\Support\PayoutFixtures;
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

/**
 * A payout whose attempt #1 is over, so two workers are racing for #2 — the
 * situation §9.1 names ("two workers creating attempt #2 concurrently") and
 * §11.4 summarises as "payout lock; loser aborts without incrementing".
 */
function attemptRacePayout(int $instructorId): Payout
{
    $payout = PayoutFixtures::claimedPayout($instructorId, 300);
    $first = PayoutFixtures::acquire($payout);

    // Terminal, so active_payout_id generates NULL and the slot is genuinely
    // available to whichever worker gets there first.
    PayoutFixtures::forceAttemptStatus($first->id, 'failed');

    return $payout;
}

function attemptCount(int $payoutId): int
{
    return (int) Payout::query()->findOrFail($payoutId)->attempt_count;
}

it('inv-19: attempt_count increments only when the slot is acquired', function () {
    // A acquires the slot for payout #500 and holds the transaction open
    // (attempt 2 created, status 'sending').
    // B tries to acquire the slot on connection B.
    // Assert B blocks, and after A commits, B's attempt to acquire is REFUSED
    // (a live attempt exists) and attempt_count is still 2, not 3.
    //
    // The counter must not be burned by the loser. If it reaches 3, the gate is
    // relying on the unique constraint instead of doing the work itself (§10.2).
    $instructor = 71;

    $payout = attemptRacePayout($instructor);

    expect(attemptCount($payout->id))->toBe(1);

    // Prime B first: connB() is what sets innodb_lock_wait_timeout = 1 on that
    // session, so a genuine block surfaces in a second instead of sitting on
    // MySQL's 50-second default. AttemptService('mysql_b') then reuses the same
    // connection instance, session variable and all.
    $this->connB();

    $connA = $this->connA();

    // A's transaction wraps the service's own, so its DB::transaction nests as a
    // savepoint and every row lock it took is still held here.
    $connA->beginTransaction();

    try {
        $slot = (new AttemptService('mysql'))->acquire($payout->id);

        expect($slot)->not->toBeNull()
            ->and($slot->attemptNo)->toBe(2);

        // §9.4: A holds the serialisation point, so nobody else can have it.
        expect($this->blocks(fn () => $this->connB()
            ->table('instructor_balances')
            ->where('instructor_id', $instructor)
            ->lockForUpdate()
            ->first()))->toBeTrue();

        // §10.2: "worker B blocks on the locks until A commits". It cannot read
        // payout_attempts past them, which is exactly why that check is a
        // current read and not a snapshot read.
        expect($this->blocks(fn () => (new AttemptService('mysql_b'))->acquire($payout->id)))->toBeTrue();
    } finally {
        $connA->commit();
    }

    // --- and now, with the lock free, B tries again ---
    //
    // This is the assertion the invariant is named for. B is refused because a
    // live attempt exists, and the refusal costs nothing: the counter still reads
    // 2. If it read 3, the gate would be leaning on UNIQUE(active_payout_id)
    // instead of doing its own work — the constraint as mechanism and the gate as
    // backstop, which is the inverse of §10.2.
    expect((new AttemptService('mysql_b'))->acquire($payout->id))->toBeNull()
        ->and(attemptCount($payout->id))->toBe(2)
        ->and((int) $this->connB()->table('payouts')->where('id', $payout->id)->value('attempt_count'))->toBe(2)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(2)
        // §10.2 / invariant 18: one attempt in flight, and it is A's.
        ->and(PayoutAttempt::query()
            ->where('payout_id', $payout->id)
            ->whereIn('status', ['sending', 'unknown'])
            ->count())->toBe(1);
});

it('inv-19: an interrupted slot acquisition leaves no attempt and no increment', function () {
    // The assertion the held-open test above cannot make. There, A's transaction
    // wraps everything it did, so a §10.2 that incremented in one transaction and
    // inserted in another would look identical from B until the outer commit.
    //
    // Here A is interrupted BETWEEN the two. B holds the gap in
    // UNIQUE(idempotency_key) where attempt #2's row has to go — and nothing
    // else, so A still takes both its locks and still passes the gate. A
    // increments, its INSERT blocks on that gap, and A dies on the lock timeout.
    // §11.1's "no attempt row exists AND attempt_count was not incremented,
    // because both are in the same transaction" is the only thing stopping a
    // burned counter from surviving that.
    //
    // Put a commit between the increment and the insert and this test goes red:
    // attempt_count reads 2 with no attempt to show for it, and the next worker
    // creates #3. If it does not go red, it is asserting nothing.
    $instructor = 72;

    $payout = attemptRacePayout($instructor);

    $connA = $this->connA();
    $connB = $this->connB();

    // A must surface the block as a fast, deterministic exception rather than
    // sitting on MySQL's 50-second default, the same way connB() does.
    $connA->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $connB->beginTransaction();

        // A locking read on a key that does not exist takes the gap in the
        // unique index, so A's INSERT waits — and only its insert. B takes no
        // lock on instructor_balances and none on payouts, which is what keeps
        // A running all the way to the write.
        $connB->table('payout_attempts')
            ->where('idempotency_key', AttemptService::idempotencyKey($payout->id, 2))
            ->lockForUpdate()
            ->first();

        expect($this->blocks(fn () => (new AttemptService('mysql'))->acquire($payout->id)))->toBeTrue();
    } finally {
        $connB->rollBack();
        $connA->statement('SET SESSION innodb_lock_wait_timeout = 50');
    }

    expect(attemptCount($payout->id))->toBe(1)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(1)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->where('attempt_no', 2)->exists())->toBeFalse();

    // §10.7: nothing is stranded by that — the slot is still there to be taken,
    // and the next attempt is #2, not #3.
    $slot = (new AttemptService('mysql'))->acquire($payout->id);

    expect($slot)->not->toBeNull()
        ->and($slot->attemptNo)->toBe(2)
        ->and(attemptCount($payout->id))->toBe(2);
});

it('inv-23: settlement and failure move the balance exactly once when replayed', function () {
    // Settle a payout. Record the balance buckets.
    // Replay the exact same settlement call (same payout, same attempt).
    // Assert reserved and paid are UNCHANGED — the balance update is gated on the
    // payout transition's affected-row count (§10.6).
    //
    // Repeat for the failure path: fail a payout, replay, assert reserved and
    // available are unchanged and entries are not un-stamped twice.
    $settledInstructor = 73;
    $failedInstructor = 74;

    $settlement = new SettlementService('mysql');

    // The replays go through a SECOND connection on purpose: a job retried on
    // another worker is the case this has to survive, and a replay on the same
    // connection could ride on state that worker happens to still hold.
    $replay = new SettlementService('mysql_b');

    // --- settlement ---
    $payout = PayoutFixtures::claimedPayout($settledInstructor, 300);
    $slot = PayoutFixtures::acquire($payout);

    expect($settlement->recordSuccess($slot->id, Outcome::success('txf_a')))->toBeTrue();

    $afterSettlement = PayoutFixtures::buckets($settledInstructor);

    expect($afterSettlement)
        ->toBe(['recognized' => 300, 'available' => 0, 'reserved' => 0, 'paid' => 300]);

    // §10.6: "a replayed settlement would find status = 'settled', affect zero
    // rows on the payout, and then still move reserved -> paid a second time if
    // the cache update ran unconditionally".
    expect($settlement->recordSuccess($slot->id, Outcome::success('txf_a')))->toBeFalse()
        ->and($replay->recordSuccess($slot->id, Outcome::success('txf_a')))->toBeFalse()
        ->and(PayoutFixtures::buckets($settledInstructor))->toBe($afterSettlement)
        ->and(Payout::query()->findOrFail($payout->id)->status)->toBe('settled')
        ->and(PayoutAttempt::query()->findOrFail($slot->id)->status)->toBe('succeeded')
        // §12 level one: the cache still agrees with the ledger.
        ->and(PayoutFixtures::ledgerBuckets($settledInstructor))->toBe($afterSettlement);

    // --- failure ---
    $failing = PayoutFixtures::claimedPayout($failedInstructor, 400);
    $failingSlot = PayoutFixtures::acquire($failing);

    PayoutFixtures::forceAttemptStatus($failingSlot->id, 'failed');

    expect($settlement->failPayout($failing->id))->toBeTrue();

    $afterFailure = PayoutFixtures::buckets($failedInstructor);

    expect($afterFailure)
        ->toBe(['recognized' => 400, 'available' => 400, 'reserved' => 0, 'paid' => 0]);

    // §10.6: the ledger un-stamp is naturally idempotent (WHERE payout_id = :id);
    // the balance move is not, which is why it is gated the same way settlement's
    // is. Un-stamping twice would be harmless; moving the money twice would not.
    expect($settlement->failPayout($failing->id))->toBeFalse()
        ->and($replay->failPayout($failing->id))->toBeFalse()
        ->and(PayoutFixtures::buckets($failedInstructor))->toBe($afterFailure)
        ->and(Payout::query()->findOrFail($failing->id)->status)->toBe('failed')
        ->and(LedgerEntry::query()->where('payout_id', $failing->id)->count())->toBe(0)
        ->and(LedgerEntry::query()->where('instructor_id', $failedInstructor)->whereNull('payout_id')->count())->toBe(1)
        ->and(PayoutFixtures::ledgerBuckets($failedInstructor))->toBe($afterFailure)
        // Invariant 4: nothing is left stamped by a failed payout.
        ->and(PayoutFixtures::orphanedEntries())->toBe(0);
});
