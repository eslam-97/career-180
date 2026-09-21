<?php

// Invariants 1, 2, 7, 8, 9, 10, 11 — ARCHITECTURE.md §3, §5.4, §5.5, §6, §8

use App\Domain\Allocation\EqualSplitAllocator;
use App\Domain\Money\Bps;
use App\Domain\Recognition\ReferenceReleaseCalculator;
use App\Domain\Recognition\ReleaseCalculator;

// Randomised runs are seeded. An unreproducible red run in a money repo tells
// you almost nothing, so every loop below can be replayed exactly.

/** §3.3: all timestamps are stored and compared in UTC. */
function moneyTestEpoch(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
}

/** A UTC instant $days whole days from the term start. */
function moneyTestDay(int $days): DateTimeImmutable
{
    return moneyTestEpoch()->modify("{$days} days");
}

it('inv-01: allocations plus platform cut equal the payment exactly', function () {
    // Over 500 randomised (gross, bps, instructorCount) triples:
    //   sum(allocations) + platformCut === gross, exactly. No tolerance.
    // Include grosses that do not divide evenly and bps that do not divide evenly.
    mt_srand(1_000_001);

    $allocator = new EqualSplitAllocator;

    // §5.5 worked example, pinned by hand: the leftover piastre goes to the
    // lowest instructor id, and the three shares plus the cut are the gross.
    $pool = Bps::pool(35_000, 2_000);
    $cut = Bps::cut(35_000, 2_000);
    $allocations = $allocator->allocate($pool, [12, 3, 7]);

    expect($allocations[3]['amount_minor'])->toBe(9_334)
        ->and($allocations[7]['amount_minor'])->toBe(9_333)
        ->and($allocations[12]['amount_minor'])->toBe(9_333)
        ->and(array_sum(array_column($allocations, 'amount_minor')) + $cut)->toBe(35_000);

    $cases = [];

    // Grosses and rates chosen so neither division comes out even: grosses a
    // piastre off a multiple of the instructor count, and rates that are not
    // whole percentages.
    foreach ([10_001, 10_003, 99_999, 1, 7, 35_001] as $gross) {
        foreach ([1, 333, 2_000, 3_333, 9_999] as $bps) {
            foreach ([1, 2, 3, 7] as $count) {
                $cases[] = [$gross, $bps, $count];
            }
        }
    }

    for ($i = count($cases); $i < 500; $i++) {
        $cases[] = [mt_rand(1, 100_000_000), mt_rand(0, 10_000), mt_rand(1, 19)];
    }

    foreach ($cases as [$gross, $bps, $count]) {
        // Ids are deliberately neither 1..n nor sorted, so the ascending-id
        // tie-break in §5.5 is exercised against arbitrary keys.
        $ids = [];
        while (count($ids) < $count) {
            $ids[mt_rand(1, 500)] = true;
        }
        $ids = array_keys($ids);
        shuffle($ids);

        $pool = Bps::pool($gross, $bps);
        $cut = Bps::cut($gross, $bps);
        $allocations = $allocator->allocate($pool, $ids);
        $sum = array_sum(array_column($allocations, 'amount_minor'));

        $context = "gross={$gross} bps={$bps} instructors={$count}";

        // §5.5: asserted, not assumed — exactly, no tolerance.
        expect($sum + $cut)->toBe($gross, $context)
            // §8 precondition 1: the allocator distributes the pool exactly.
            ->and($sum)->toBe($pool, $context);
    }
});

it('inv-02: the pool is floored and the cut is the remainder', function () {
    // For randomised (gross, bps):
    //   pool === intdiv(gross * (10000 - bps), 10000)
    //   cut  === gross - pool
    //   pool <= gross
    // Explicitly assert gross=10001, bps=2000 gives pool=8000, cut=2001 — NOT 8001/2000.
    // That case is the whole point of §5.4.
    mt_srand(1_000_002);

    // §5.4: the direction test. Flooring the CUT instead of the pool would
    // give 8001/2000 here — it sums just as exactly, and hands the sub-piastre
    // residue to instructors instead of the platform, contradicting §8.
    expect(Bps::pool(10_001, 2_000))->toBe(8_000)
        ->and(Bps::cut(10_001, 2_000))->toBe(2_001)
        ->and(Bps::pool(10_001, 2_000))->not->toBe(8_001)
        ->and(Bps::cut(10_001, 2_000))->not->toBe(2_000);

    // §5.4: the even case from the same worked example.
    expect(Bps::pool(35_000, 2_000))->toBe(28_000)
        ->and(Bps::cut(35_000, 2_000))->toBe(7_000);

    // The two rate boundaries: the platform takes nothing, and everything.
    expect(Bps::pool(10_001, 0))->toBe(10_001)
        ->and(Bps::cut(10_001, 0))->toBe(0)
        ->and(Bps::pool(10_001, 10_000))->toBe(0)
        ->and(Bps::cut(10_001, 10_000))->toBe(10_001);

    for ($i = 0; $i < 500; $i++) {
        $gross = mt_rand(0, 1_000_000_000);
        $bps = mt_rand(0, 10_000);

        $pool = Bps::pool($gross, $bps);
        $cut = Bps::cut($gross, $bps);

        $context = "gross={$gross} bps={$bps}";

        expect($pool)->toBe(intdiv($gross * (10_000 - $bps), 10_000), $context)
            ->and($cut)->toBe($gross - $pool, $context)
            // §8 precondition 2: pool <= gross is what the residual-claimant
            // proof leans on, and it is a consequence of flooring the pool.
            ->and($pool)->toBeLessThanOrEqual($gross, $context)
            ->and($cut)->toBeGreaterThanOrEqual(0, $context);
    }
});

it('inv-07: released matches the reference implementation', function () {
    // 1000 randomised (amount, termDays, effectiveDays, t).
    // ReleaseCalculator::released(...) === ReferenceReleaseCalculator::released(...)
    // The reference is a day-by-day accumulator that shares NO code with production.
    // This is the only oracle for the recognition model (§16.1).
    mt_srand(1_000_007);

    $termStart = moneyTestEpoch();

    // §16.1 worked examples, specified by hand from the requirements rather
    // than copied from either implementation. 90-day term, allocation 9,000.
    $termEnd = moneyTestDay(90);

    foreach ([-5 => 0, 0 => 0, 30 => 3_000, 45 => 4_500, 90 => 9_000] as $day => $expected) {
        expect(ReleaseCalculator::released(9_000, $termStart, $termEnd, null, moneyTestDay($day)))
            ->toBe($expected, "day={$day}")
            ->and(ReferenceReleaseCalculator::released(9_000, $termStart, $termEnd, null, moneyTestDay($day)))
            ->toBe($expected, "day={$day}");
    }

    // ...terminated day 45, then day 60 and day 90 -> 4,500.
    foreach ([60, 90] as $day) {
        expect(ReleaseCalculator::released(9_000, $termStart, $termEnd, moneyTestDay(45), moneyTestDay($day)))
            ->toBe(4_500, "day={$day}")
            ->and(ReferenceReleaseCalculator::released(9_000, $termStart, $termEnd, moneyTestDay(45), moneyTestDay($day)))
            ->toBe(4_500, "day={$day}");
    }

    for ($i = 0; $i < 1_000; $i++) {
        $amount = mt_rand(0, 10_000_000);
        // Bounded: the reference walks the term one day at a time on purpose.
        $termDays = mt_rand(1, 400);
        $effectiveDays = mt_rand(0, $termDays);
        // Exercise the null path too, where access runs the full term.
        $accessEndsAt = $effectiveDays === $termDays && mt_rand(0, 1) === 1
            ? null
            : moneyTestDay($effectiveDays);
        $day = mt_rand(-30, $termDays + 60);
        $termEnd = moneyTestDay($termDays);

        $context = "amount={$amount} termDays={$termDays} effectiveDays={$effectiveDays} day={$day}";

        expect(ReleaseCalculator::released($amount, $termStart, $termEnd, $accessEndsAt, moneyTestDay($day)))
            ->toBe(
                ReferenceReleaseCalculator::released($amount, $termStart, $termEnd, $accessEndsAt, moneyTestDay($day)),
                $context,
            );
    }
});

it('inv-08: released is never negative, including before the term starts', function () {
    // t = termStart - 5 days  ->  0
    // t = termStart - 1 year  ->  0
    // Randomised t below the start  ->  always 0, never negative.
    mt_srand(1_000_008);

    $termStart = moneyTestEpoch();
    $termEnd = moneyTestDay(90);

    expect(ReleaseCalculator::released(9_334, $termStart, $termEnd, null, moneyTestDay(-5)))->toBe(0)
        ->and(ReleaseCalculator::released(9_334, $termStart, $termEnd, null, moneyTestDay(-365)))->toBe(0);

    for ($i = 0; $i < 500; $i++) {
        $amount = mt_rand(0, 10_000_000);
        $termDays = mt_rand(1, 400);
        $effectiveDays = mt_rand(0, $termDays);
        $day = mt_rand(-2_000, -1);

        $released = ReleaseCalculator::released(
            $amount,
            $termStart,
            moneyTestDay($termDays),
            moneyTestDay($effectiveDays),
            moneyTestDay($day),
        );

        $context = "amount={$amount} termDays={$termDays} effectiveDays={$effectiveDays} day={$day}";

        // §6: the clamp's lower bound stops a timestamp before term_start
        // producing a negative release. Both halves are asserted, so a
        // negative value can never read as a pass.
        expect($released)->toBe(0, $context)
            ->and($released)->toBeGreaterThanOrEqual(0, $context);
    }
});

it('inv-09: released never exceeds the cap, and equals the full amount at term end', function () {
    // released(t) <= floor(amount * effectiveDays / termDays), for all t
    // With no early termination: released(termEnd) === amount, exactly.
    // Worked case: amount 9334, term 90, terminated day 45
    //   day 30 -> 3111, day 60 -> 4667, day 90 -> 4667
    mt_srand(1_000_009);

    $termStart = moneyTestEpoch();
    $ninetyDayTerm = moneyTestDay(90);
    $terminated = moneyTestDay(45);

    expect(ReleaseCalculator::released(9_334, $termStart, $ninetyDayTerm, $terminated, moneyTestDay(30)))->toBe(3_111)
        ->and(ReleaseCalculator::released(9_334, $termStart, $ninetyDayTerm, $terminated, moneyTestDay(60)))->toBe(4_667)
        ->and(ReleaseCalculator::released(9_334, $termStart, $ninetyDayTerm, $terminated, moneyTestDay(90)))->toBe(4_667);

    for ($i = 0; $i < 500; $i++) {
        $amount = mt_rand(0, 10_000_000);
        $termDays = mt_rand(1, 400);
        $effectiveDays = mt_rand(0, $termDays);
        $termEnd = moneyTestDay($termDays);
        $accessEndsAt = moneyTestDay($effectiveDays);

        // §6: the cap is not a separate expression in the implementation — it
        // is what the clamp's upper bound produces. Written out here from the
        // invariant's own wording so the test is not simply a restatement of
        // the code it checks.
        $cap = intdiv($amount * $effectiveDays, $termDays);

        foreach ([-10, 0, 1, intdiv($termDays, 2), $effectiveDays, $termDays, $termDays + 365] as $day) {
            $context = "amount={$amount} termDays={$termDays} effectiveDays={$effectiveDays} day={$day}";

            expect(ReleaseCalculator::released($amount, $termStart, $termEnd, $accessEndsAt, moneyTestDay($day)))
                ->toBeLessThanOrEqual($cap, $context);
        }

        $context = "amount={$amount} termDays={$termDays}";

        // §6: no special case at term end. With no early termination
        // effective_days == term_days, so the clamp yields term_days and the
        // residue lost to flooring across the term is recovered exactly.
        expect(ReleaseCalculator::released($amount, $termStart, $termEnd, null, $termEnd))
            ->toBe($amount, $context)
            ->and(ReleaseCalculator::released($amount, $termStart, $termEnd, null, moneyTestDay($termDays + 400)))
            ->toBe($amount, $context);
    }
});

it('inv-10: released never decreases as time moves forward', function () {
    // For a fixed accessEndsAt, walk t from termStart-5 to termEnd+30 one day at a time
    // and assert each value is >= the previous one.
    $termStart = moneyTestEpoch();
    $termDays = 90;
    $termEnd = moneyTestDay($termDays);
    $amount = 9_334;

    // null is the no-termination case; day 0 is the full refund of §6.1, where
    // access_ends_at = starts_at makes effective_days 0.
    $accessEndsAtCases = [null, moneyTestDay(0), moneyTestDay(1), moneyTestDay(45), moneyTestDay(89), $termEnd];

    foreach ($accessEndsAtCases as $accessEndsAt) {
        $previous = null;
        $label = $accessEndsAt?->format('Y-m-d') ?? 'null';

        for ($day = -5; $day <= $termDays + 30; $day++) {
            $released = ReleaseCalculator::released($amount, $termStart, $termEnd, $accessEndsAt, moneyTestDay($day));

            if ($previous !== null) {
                // §6: monotonic by construction — elapsed_days is
                // non-decreasing in t, and the clamp only ever holds it still.
                expect($released)->toBeGreaterThanOrEqual($previous, "accessEndsAt={$label} day={$day}");
            }

            $previous = $released;
        }
    }
});

it('inv-11: the platform share is never negative', function () {
    // For randomised (gross, bps, instructorCount, termDays, effectiveDays, t):
    //   grossReleased(t) - sum(instructorReleased(t)) >= 0
    // Both sides must use the SAME clamped fraction (§8, precondition 3).
    mt_srand(1_000_011);

    $allocator = new EqualSplitAllocator;
    $termStart = moneyTestEpoch();

    // §8 worked example: day 45 of the 35,000 gross at 2000 bps, split three
    // ways — 17,500 - (4,667 + 4,666 + 4,666) = 3,501.
    $ninetyDayTerm = moneyTestDay(90);
    $fixedTermDays = ReleaseCalculator::termDays($termStart, $ninetyDayTerm);
    $fixedElapsed = ReleaseCalculator::elapsedDays($termStart, $ninetyDayTerm, null, moneyTestDay(45));

    $fixedShares = array_map(
        fn (int $amount): int => ReleaseCalculator::releasedFor($amount, $fixedElapsed, $fixedTermDays),
        [9_334, 9_333, 9_333],
    );

    expect(ReleaseCalculator::releasedFor(35_000, $fixedElapsed, $fixedTermDays))->toBe(17_500)
        ->and($fixedShares)->toBe([4_667, 4_666, 4_666])
        ->and(ReleaseCalculator::releasedFor(35_000, $fixedElapsed, $fixedTermDays) - array_sum($fixedShares))->toBe(3_501);

    for ($i = 0; $i < 500; $i++) {
        $gross = mt_rand(1, 100_000_000);
        $bps = mt_rand(0, 10_000);
        $count = mt_rand(1, 19);
        $termDays = mt_rand(1, 400);
        $effectiveDays = mt_rand(0, $termDays);
        $day = mt_rand(-30, $termDays + 60);

        $termEnd = moneyTestDay($termDays);
        $accessEndsAt = moneyTestDay($effectiveDays);

        $pool = Bps::pool($gross, $bps);
        $allocations = $allocator->allocate($pool, range(1, $count));

        // §4 / §8 precondition 3: the fraction is computed ONCE per
        // (payment, instant) and passed to both sides. Letting each side
        // derive its own is precisely the drift the proof forbids, so the
        // test must not do it either or it stops testing the real thing.
        $actualTermDays = ReleaseCalculator::termDays($termStart, $termEnd);
        $elapsedDays = ReleaseCalculator::elapsedDays($termStart, $termEnd, $accessEndsAt, moneyTestDay($day));

        $grossReleased = ReleaseCalculator::releasedFor($gross, $elapsedDays, $actualTermDays);

        $instructorReleased = 0;
        foreach ($allocations as $allocation) {
            $instructorReleased += ReleaseCalculator::releasedFor($allocation['amount_minor'], $elapsedDays, $actualTermDays);
        }

        $context = "gross={$gross} bps={$bps} instructors={$count} termDays={$termDays} effectiveDays={$effectiveDays} day={$day}";

        expect($grossReleased - $instructorReleased)->toBeGreaterThanOrEqual(0, $context);
    }
});
