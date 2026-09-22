<?php

declare(strict_types=1);

namespace App\Domain\Refund;

use DateTimeImmutable;
use DomainException;

/**
 * §6.1: "whether a given refund sets access_ends_at is a business rule applied
 * by a policy class, not assumed by the ledger, and each kind has its own test".
 *
 * This is that class, and it is the only place the mapping lives:
 *
 *     termination_prorata   access ends at the refund's effective date
 *     termination_full      access ends at starts_at  -> effective_days = 0
 *     goodwill_partial      access unchanged          -> no recognition cap
 *
 * Pure and static, like Bps and LargestRemainder. It reads no database and
 * writes none: the effect on access is arithmetic on four dates, and keeping it
 * that way is what makes "one test per kind" a test of the rule rather than of
 * the plumbing around it.
 */
final class RefundPolicy
{
    public const TERMINATION_PRORATA = 'termination_prorata';

    public const TERMINATION_FULL = 'termination_full';

    public const GOODWILL_PARTIAL = 'goodwill_partial';

    /**
     * The value this refund would set `subscriptions.access_ends_at` to, or
     * null when this kind does not cap recognition at all.
     *
     * The window is the SUBSCRIPTION's starts_at/ends_at rather than the
     * payment's term_start/term_end: §6.1 names those columns, and they are the
     * ones §14's CHECK constrains.
     */
    public static function accessEndsAt(
        string $kind,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): ?DateTimeImmutable {
        return match ($kind) {
            // §7.1: "a prorated termination refund is assumed to correspond to
            // the unused portion of the term", so terminating access at the
            // refund date IS the student's remaining entitlement.
            self::TERMINATION_PRORATA => self::clamp($effectiveAt, $startsAt, $endsAt),

            // §6.1: "setting access_ends_at = starts_at makes a full refund
            // fall out of the existing clamp with no new code" —
            // effective_days = 0, and everything already recognized is clawed
            // back by the correction rather than by a special case.
            self::TERMINATION_FULL => $startsAt,

            // §6.1: access continues, so the recognition cap is NONE. The
            // entitlement reduction this kind needs is the counter-allocation
            // §19 lists as not implemented; it is deliberately not faked here
            // with an access change that would also cut off the student.
            self::GOODWILL_PARTIAL => null,

            // §14 makes refunds.kind an ENUM of exactly these three, so this is
            // unreachable from a stored row. It exists so a caller passing a
            // kind the design does not define fails here, loudly, rather than
            // silently taking the "no cap" branch of a default arm.
            default => throw new DomainException(
                "Refund kind '{$kind}' has no access policy; §6.1 defines only "
                .self::TERMINATION_PRORATA.', '.self::TERMINATION_FULL.' and '.self::GOODWILL_PARTIAL.'.'
            ),
        };
    }

    /**
     * §14: CHECK (access_ends_at >= starts_at AND access_ends_at <= ends_at).
     * A refund dated outside the term is a real event — backdated disputes and
     * late-processed chargebacks both produce one — so the date is clamped into
     * the window rather than rejected.
     *
     * Both edges fall out of §6 with no new rule. Dated after the term:
     * access_ends_at = ends_at, so effective_days == term_days and nothing is
     * capped, which is right because nothing was unearned. Dated before the
     * start: access_ends_at = starts_at, effective_days = 0, which is the
     * termination_full shape — also right, because nothing was earned.
     *
     * Only access_ends_at is clamped. The refunds row keeps the refund's own
     * effective_at, because §3.3 makes that the business-effective instant and
     * §10.1 makes it the payout-eligibility predicate.
     */
    private static function clamp(
        DateTimeImmutable $at,
        DateTimeImmutable $lower,
        DateTimeImmutable $upper,
    ): DateTimeImmutable {
        if ($at < $lower) {
            return $lower;
        }

        return $at > $upper ? $upper : $at;
    }
}
