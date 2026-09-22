<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Recognition\ExpectedRecognition;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * §6.5: pending = Σ released(alloc, now) − posted.
 *
 * "Recognized economically but not yet posted to the ledger" (§1). §13 makes it
 * the only figure on the screen that is computed rather than read, and §6.5 is
 * explicit about why it stays derived: only one number can be authoritative, and
 * it must be the one a payout claims against. Pending is never payable and is
 * never written anywhere.
 *
 * §19 records the cost honestly: this will slow for an instructor holding
 * thousands of concurrent allocations. It is computed for one instructor at a
 * time, never for a list.
 */
final class PendingRecognition
{
    /**
     * §6.2: `posted` is release + release_correction and deliberately NOT
     * refund_adjustment — the same set ReleaseService and BalanceReconciler
     * use, for the same reason. expected(t) is derived from released(), which
     * knows nothing about a goodwill credit or a chargeback; subtracting a
     * refund_adjustment here would show the instructor money still coming to
     * them that the refund has already taken away. That is invariant 6's
     * failure wearing a different hat.
     */
    private const POSTED_TYPES = ['release', 'release_correction'];

    /**
     * §9.4 deliberately not applied: the rule binds every *money-moving*
     * transaction, and §9.3 states the distinction as "the absence of an
     * unlocked read that a write depends on". This screen never writes, so
     * there is nothing to serialise — and taking FOR UPDATE on a page load
     * would block the release job behind a browser tab. Do not add a lock here.
     */
    public static function forInstructor(int $instructorId): int
    {
        $db = DB::connection();

        // §14: UNIQUE(instructor_id, type, source_ref) covers this predicate,
        // so it is an index range over one instructor rather than a scan. §13
        // bars aggregating the ledger for the cached figures — recognized,
        // available, reserved and paid all come off the single balance row —
        // but `posted` has no cached form: recognized_minor covers all entry
        // types (§1.1) and so is the wrong number by exactly the
        // refund_adjustment total.
        $posted = (int) $db->table('ledger_entries')
            ->where('instructor_id', $instructorId)
            ->whereIn('type', self::POSTED_TYPES)
            ->sum('amount_minor');

        return ExpectedRecognition::forInstructor($db, $instructorId, self::now()) - $posted;
    }

    /** §3.3: all timestamps are stored and compared in UTC. */
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(now()->utc()->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }
}
