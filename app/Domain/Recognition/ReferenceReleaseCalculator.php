<?php

declare(strict_types=1);

namespace App\Domain\Recognition;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * §16.1: the test oracle for the recognition model.
 *
 * A deliberately naive day-by-day accumulator. It walks the term one day at a
 * time and adds the allocation up once per elapsed day, where ReleaseCalculator
 * multiplies. Slow and obviously correct; the production version is fast and
 * subtly correct.
 *
 * DO NOT REFACTOR THIS TO SHARE CODE WITH ReleaseCalculator. Its entire value
 * is that it is an independent second opinion. Constraint-style invariants
 * cannot catch an expression that is wrong in a consistent way — a release
 * function that returned half of everything would satisfy every bound in §15
 * and agree with level-two reconciliation, because reconciliation computes the
 * same expression. Only an oracle that does not share the suspect sum catches
 * it. Deduplicating these two files would silently delete that protection.
 *
 * §3.3: all timestamps are UTC, so stepping by calendar days and stepping by
 * 86,400 seconds are the same walk. That is an assumption of the model, not of
 * this class.
 */
final class ReferenceReleaseCalculator
{
    public static function released(
        int $amount,
        DateTimeImmutable $termStart,
        DateTimeImmutable $termEnd,
        ?DateTimeImmutable $accessEndsAt,
        DateTimeImmutable $at,
    ): int {
        if ($amount < 0) {
            throw new InvalidArgumentException("Amount must be a non-negative magnitude, got {$amount}.");
        }

        // Count the whole days in the term by stepping through it one day at
        // a time, taking only steps that land on or before the end.
        $termDays = 0;
        $cursor = $termStart;

        while (true) {
            $next = $cursor->modify('+1 day');

            if ($next > $termEnd) {
                break;
            }

            $cursor = $next;
            $termDays++;
        }

        if ($termDays <= 0) {
            throw new InvalidArgumentException("Term must be at least one whole day, got {$termDays}.");
        }

        // The last day access is granted for: whichever of the term end and
        // the access termination comes first.
        $effectiveEnd = $termEnd;

        if ($accessEndsAt !== null && $accessEndsAt < $effectiveEnd) {
            $effectiveEnd = $accessEndsAt;
        }

        // Count the days of access the same way, one step at a time.
        $effectiveDays = 0;
        $cursor = $termStart;

        while (true) {
            $next = $cursor->modify('+1 day');

            if ($next > $effectiveEnd) {
                break;
            }

            $cursor = $next;
            $effectiveDays++;
        }

        // Walk forward from the start, one day at a time, counting the days
        // that have both elapsed and been granted. Asked about an instant
        // before the term begins, the very first step already overshoots and
        // the count stays 0 — the lower clamp is not written anywhere, it is
        // simply what walking forwards does.
        $elapsedDays = 0;
        $cursor = $termStart;

        while ($elapsedDays < $effectiveDays) {
            $next = $cursor->modify('+1 day');

            if ($next > $at) {
                break;
            }

            $cursor = $next;
            $elapsedDays++;
        }

        // Hand over one allocation's worth of numerator per elapsed day by
        // repeated addition, then divide once at the very end. This is the
        // point of the whole class: the production version reaches the same
        // numerator with a single multiplication, so the two files have no
        // expression in common to be wrong together.
        $numerator = 0;

        for ($day = 0; $day < $elapsedDays; $day++) {
            $numerator += $amount;
        }

        return intdiv($numerator, $termDays);
    }
}
