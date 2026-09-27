<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Filament\Support\MinorUnits;
use Illuminate\Support\Facades\DB;

/**
 * The read side of the demos: the ledger state a viewer needs to see before and
 * after each scenario.
 *
 * Reads only. Nothing here writes, locks or decides — §9.4's rule is about
 * transactions that read or write an instructor's entries, payouts or attempts
 * with intent to change them, and a reporting read that gates nothing takes no
 * lock, the same as §12's reconciliation reads and §10.7's candidate read.
 */
final class DemoLedger
{
    /** §10.7 / §12: a payout holding its entries but not yet paid. */
    private const RESERVING_PAYOUT_STATUSES = ['pending', 'in_progress', 'needs_review'];

    /**
     * §12 level one, from the cache — the four buckets `instructor_balances`
     * maintains transactionally (§10.5).
     *
     * @return array{recognized: int, available: int, reserved: int, paid: int}
     */
    public static function cached(int $instructorId): array
    {
        $row = DB::table('instructor_balances')->where('instructor_id', $instructorId)->first();

        if ($row === null) {
            return ['recognized' => 0, 'available' => 0, 'reserved' => 0, 'paid' => 0];
        }

        return [
            'recognized' => (int) $row->recognized_minor,
            'available' => (int) $row->available_minor,
            'reserved' => (int) $row->reserved_minor,
            'paid' => (int) $row->paid_minor,
        ];
    }

    /**
     * §12 level one, from the ledger — the same four buckets recomputed from
     * the entries themselves:
     *
     *     available  == SUM(entries WHERE payout_id IS NULL)
     *     reserved   == SUM(entries WHERE payout_id IN non-terminal payouts)
     *     paid       == SUM(entries WHERE payout_id IN settled payouts)
     *
     * Printed beside the cache so a viewer can see the two agree rather than
     * take it on trust.
     *
     * @return array{recognized: int, available: int, reserved: int, paid: int}
     */
    public static function derived(int $instructorId): array
    {
        $entries = fn () => DB::table('ledger_entries')->where('instructor_id', $instructorId);

        $available = (int) $entries()->whereNull('payout_id')->sum('amount_minor');

        $reserved = (int) $entries()
            ->whereIn('payout_id', DB::table('payouts')
                ->whereIn('status', self::RESERVING_PAYOUT_STATUSES)
                ->select('id'))
            ->sum('amount_minor');

        $paid = (int) $entries()
            ->whereIn('payout_id', DB::table('payouts')->where('status', 'settled')->select('id'))
            ->sum('amount_minor');

        return [
            'recognized' => $available + $reserved + $paid,
            'available' => $available,
            'reserved' => $reserved,
            'paid' => $paid,
        ];
    }

    /**
     * Invariant 4: every ledger entry is in exactly one of the three buckets.
     * An entry stamped with a terminally failed payout is in none of them,
     * which is the state §10.6's single transaction exists to make impossible.
     */
    public static function orphanedEntries(int $instructorId): int
    {
        return DB::table('ledger_entries')
            ->where('instructor_id', $instructorId)
            ->whereNotNull('payout_id')
            ->whereIn('payout_id', DB::table('payouts')->where('status', 'failed')->select('id'))
            ->count();
    }

    /** @return array<int, array<int, string>> the instructor's ledger rows, as table cells. */
    public static function entryRows(int $instructorId): array
    {
        return DB::table('ledger_entries')
            ->where('instructor_id', $instructorId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $entry): array => [
                (string) $entry->id,
                $entry->type,
                self::money((int) $entry->amount_minor),
                // §3.3: three dates, three jobs. effective_at is the one §10.1
                // filters payout eligibility by, so it is the one shown.
                substr((string) $entry->effective_at, 0, 10),
                $entry->source_ref,
                $entry->payout_id === null ? '—' : (string) $entry->payout_id,
            ])
            ->all();
    }

    /** @return array<int, array<int, string>> the instructor's payouts, as table cells. */
    public static function payoutRows(int $instructorId): array
    {
        return DB::table('payouts')
            ->where('instructor_id', $instructorId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $payout): array => [
                (string) $payout->id,
                (string) $payout->batch_id,
                $payout->amount_minor === null ? 'NULL' : self::money((int) $payout->amount_minor),
                $payout->status,
                (string) $payout->attempt_count,
                $payout->settled_at === null ? '—' : substr((string) $payout->settled_at, 0, 19),
            ])
            ->all();
    }

    /** @return array<int, array<int, string>> the payout's attempts, as table cells. */
    public static function attemptRows(int $payoutId): array
    {
        return DB::table('payout_attempts')
            ->where('payout_id', $payoutId)
            ->orderBy('attempt_no')
            ->get()
            ->map(fn (object $attempt): array => [
                (string) $attempt->id,
                (string) $attempt->attempt_no,
                $attempt->status,
                // §11.3: the key is what the provider deduplicates on, so it is
                // shown — abbreviated, because it is a sha256.
                substr((string) $attempt->idempotency_key, 0, 12).'…',
                (string) $attempt->poll_count,
                $attempt->provider_reference ?? '—',
                $attempt->resolution_evidence === null ? '—' : 'recorded',
            ])
            ->all();
    }

    /** §12: the alerts a scenario raised. Detection, never repair. */
    public static function alertRows(string $subjectType, int $subjectId): array
    {
        return DB::table('reconciliation_alerts')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $alert): array => [
                (string) $alert->id,
                $alert->kind,
                $alert->subject_type.' '.$alert->subject_id,
                $alert->resolved_at === null ? 'open' : 'resolved',
            ])
            ->all();
    }

    /** §3: integer minor units all the way to the screen — no float, ever. */
    public static function money(int $minor): string
    {
        return MinorUnits::format($minor);
    }
}
