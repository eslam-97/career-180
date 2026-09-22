<?php

declare(strict_types=1);

namespace App\Domain\Recognition;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;

/**
 * §6.2: expected(t) = Σ over all that instructor's allocations of
 * released(alloc, t).
 *
 * One implementation, shared by the release job (§6.2) and level-two
 * reconciliation (§12). §12 already states plainly that level two is not
 * independent of the job — "since §6.2 the release job computes this same
 * expression" — so the two computing it twice would buy no independence, only
 * the chance of them drifting apart. Independence is bought instead by the
 * reference implementation in §16.1, which shares no code with either.
 */
final class ExpectedRecognition
{
    /**
     * The clamp lives in ReleaseCalculator and is reused rather than
     * reimplemented: §8 precondition 3 requires the gross side and every
     * instructor share to release against the same clamped fraction, and two
     * copies of the formula is exactly how that stops being true.
     *
     * access_ends_at is read from the subscription on every call rather than
     * denormalised onto the allocation, for the same reason (§8).
     */
    public static function forInstructor(Connection $db, int $instructorId, DateTimeImmutable $t): int
    {
        // §6.4: a grouped read over the instructor's allocations, never a
        // per-allocation ledger row — tracking a posted amount per allocation
        // would require exactly the hidden state the aggregation avoids.
        $allocations = $db->table('revenue_allocations as ra')
            ->join('subscription_payments as p', 'p.id', '=', 'ra.payment_id')
            ->join('subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->where('ra.instructor_id', $instructorId)
            ->orderBy('ra.id')
            ->get(['ra.amount_minor', 'p.term_start', 'p.term_end', 's.access_ends_at']);

        $expected = 0;

        foreach ($allocations as $allocation) {
            $expected += ReleaseCalculator::released(
                (int) $allocation->amount_minor,
                self::utc($allocation->term_start),
                self::utc($allocation->term_end),
                $allocation->access_ends_at === null ? null : self::utc($allocation->access_ends_at),
                $t,
            );
        }

        return $expected;
    }

    /** §3.3: all timestamps are stored and compared in UTC. */
    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
