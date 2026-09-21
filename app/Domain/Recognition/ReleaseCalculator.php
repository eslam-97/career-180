<?php

declare(strict_types=1);

namespace App\Domain\Recognition;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * §6: allocate once, release progressively.
 *
 *     effective_end  = min(term_end, access_ends_at ?? term_end)
 *     effective_days = effective_end - term_start
 *     elapsed_days(t) = clamp( whole_days(t - term_start), 0, effective_days )
 *     released(t)     = floor( amount * elapsed_days(t) / term_days )
 *
 * The production implementation: fast and subtly correct. Its oracle is
 * ReferenceReleaseCalculator, which shares no code with it (§16.1).
 */
final class ReleaseCalculator
{
    // §3.3: all timestamps are stored and compared in UTC, so a day is always
    // 86,400 seconds. A boundary that shifts with a timezone is a boundary
    // that can double-count a day.
    private const SECONDS_PER_DAY = 86_400;

    /**
     * §4: term length is derived from the dates, never from a plan constant.
     * Terms are [starts_at, ends_at) — end exclusive.
     */
    public static function termDays(DateTimeImmutable $termStart, DateTimeImmutable $termEnd): int
    {
        $days = self::wholeDaysBetween($termStart, $termEnd);

        // §14: subscriptions carries CHECK (ends_at > starts_at).
        if ($days <= 0) {
            throw new InvalidArgumentException("Term must be at least one whole day, got {$days}.");
        }

        return $days;
    }

    /**
     * §6: effective_end = min(term_end, access_ends_at ?? term_end).
     *
     * A full refund sets access_ends_at = starts_at, which yields
     * effective_days = 0 and falls out of the existing clamp with no new code
     * (§6.1).
     */
    public static function effectiveDays(
        DateTimeImmutable $termStart,
        DateTimeImmutable $termEnd,
        ?DateTimeImmutable $accessEndsAt,
    ): int {
        $effectiveEnd = $accessEndsAt !== null && $accessEndsAt < $termEnd
            ? $accessEndsAt
            : $termEnd;

        // §14: CHECK (access_ends_at >= starts_at) means this cannot be
        // negative for valid data; clamping keeps a bad row from producing a
        // negative release rather than an exception deep in the release job.
        return max(0, self::wholeDaysBetween($termStart, $effectiveEnd));
    }

    /**
     * §6: clamp( whole_days(t - term_start), 0, effective_days ).
     *
     * One clamp does both jobs. Its lower bound stops a timestamp before
     * term_start producing a negative release; its upper bound makes the cap
     * structural rather than a separate min(), so there is no second
     * expression to keep in sync.
     */
    public static function elapsedDays(
        DateTimeImmutable $termStart,
        DateTimeImmutable $termEnd,
        ?DateTimeImmutable $accessEndsAt,
        DateTimeImmutable $at,
    ): int {
        $effectiveDays = self::effectiveDays($termStart, $termEnd, $accessEndsAt);

        return max(0, min(self::wholeDaysBetween($termStart, $at), $effectiveDays));
    }

    /**
     * §6: floor( amount * elapsed_days / term_days ).
     *
     * Public on purpose. §4 and §8 precondition 3 require the gross side and
     * every instructor share to be released against *the same* clamped
     * fraction; taking the already-clamped (elapsedDays, termDays) pair as
     * arguments is what makes that literally true rather than two code paths
     * that happen to agree.
     */
    public static function releasedFor(int $amount, int $elapsedDays, int $termDays): int
    {
        // §3: revenue_allocations.amount_minor is BIGINT UNSIGNED.
        if ($amount < 0) {
            throw new InvalidArgumentException("Amount must be a non-negative magnitude, got {$amount}.");
        }

        if ($elapsedDays < 0) {
            throw new InvalidArgumentException("Elapsed days must already be clamped at zero, got {$elapsedDays}.");
        }

        if ($termDays <= 0) {
            throw new InvalidArgumentException("Term days must be greater than zero, got {$termDays}.");
        }

        // §3: integer division only. intdiv() equals floor() here because both
        // operands are non-negative, which the guards above enforce.
        return intdiv($amount * $elapsedDays, $termDays);
    }

    /**
     * §6.2 writes this as released(alloc, t). The full form for callers that
     * hold dates rather than a pre-computed day pair.
     */
    public static function released(
        int $amount,
        DateTimeImmutable $termStart,
        DateTimeImmutable $termEnd,
        ?DateTimeImmutable $accessEndsAt,
        DateTimeImmutable $at,
    ): int {
        return self::releasedFor(
            $amount,
            self::elapsedDays($termStart, $termEnd, $accessEndsAt, $at),
            self::termDays($termStart, $termEnd),
        );
    }

    /**
     * Whole UTC days from $from to $to, floored.
     *
     * §3.3: floored, not truncated. intdiv() rounds toward zero, so a
     * timestamp half a day before the term start would come back as 0 rather
     * than -1. The clamp hides that at the lower bound, but a day-counting
     * helper that is wrong for negative inputs is a trap for the next caller.
     */
    private static function wholeDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        $days = intdiv($seconds, self::SECONDS_PER_DAY);

        if ($seconds < 0 && $seconds % self::SECONDS_PER_DAY !== 0) {
            $days--;
        }

        return $days;
    }
}
