<?php
// Invariants 1, 2, 7, 8, 9, 10, 11 — ARCHITECTURE.md §3, §5.4, §5.5, §6, §8

it('inv-01: allocations plus platform cut equal the payment exactly')
    // Over 500 randomised (gross, bps, instructorCount) triples:
    //   sum(allocations) + platformCut === gross, exactly. No tolerance.
    // Include grosses that do not divide evenly and bps that do not divide evenly.
    ->todo();

it('inv-02: the pool is floored and the cut is the remainder')
    // For randomised (gross, bps):
    //   pool === intdiv(gross * (10000 - bps), 10000)
    //   cut  === gross - pool
    //   pool <= gross
    // Explicitly assert gross=10001, bps=2000 gives pool=8000, cut=2001 — NOT 8001/2000.
    // That case is the whole point of §5.4.
    ->todo();

it('inv-07: released matches the reference implementation')
    // 1000 randomised (amount, termDays, effectiveDays, t).
    // ReleaseCalculator::released(...) === ReferenceReleaseCalculator::released(...)
    // The reference is a day-by-day accumulator that shares NO code with production.
    // This is the only oracle for the recognition model (§16.1).
    ->todo();

it('inv-08: released is never negative, including before the term starts')
    // t = termStart - 5 days  ->  0
    // t = termStart - 1 year  ->  0
    // Randomised t below the start  ->  always 0, never negative.
    ->todo();

it('inv-09: released never exceeds the cap, and equals the full amount at term end')
    // released(t) <= floor(amount * effectiveDays / termDays), for all t
    // With no early termination: released(termEnd) === amount, exactly.
    // Worked case: amount 9334, term 90, terminated day 45
    //   day 30 -> 3111, day 60 -> 4667, day 90 -> 4667
    ->todo();

it('inv-10: released never decreases as time moves forward')
    // For a fixed accessEndsAt, walk t from termStart-5 to termEnd+30 one day at a time
    // and assert each value is >= the previous one.
    ->todo();

it('inv-11: the platform share is never negative')
    // For randomised (gross, bps, instructorCount, termDays, effectiveDays, t):
    //   grossReleased(t) - sum(instructorReleased(t)) >= 0
    // Both sides must use the SAME clamped fraction (§8, precondition 3).
    ->todo();