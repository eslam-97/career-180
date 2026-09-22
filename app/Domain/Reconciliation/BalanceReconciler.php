<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation;

use App\Domain\Recognition\ExpectedRecognition;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * §12: four checks, catching different classes of bug. Money passes through
 * four links — payment, allocations, ledger, balance cache — and each check
 * covers one link.
 *
 * This class DETECTS. It never repairs: "a materialised balance with no drift
 * detector is a materialised balance that will eventually be wrong without
 * anyone noticing", and equally, a detector that quietly repairs hides the bug
 * that caused the drift. §10.5 is what keeps the cache correct — every
 * transition updates it in the same transaction that moves the ledger rows.
 */
final class BalanceReconciler
{
    public const KIND_ALLOCATION_MISMATCH = 'allocation_mismatch';

    public const KIND_BUCKET_MISMATCH = 'bucket_mismatch';

    public const KIND_LEDGER_MISMATCH = 'ledger_mismatch';

    public const KIND_WATERMARK_DRIFT = 'watermark_drift';

    public const SUBJECT_PAYMENT = 'subscription_payment';

    public const SUBJECT_BALANCE = 'instructor_balance';

    /**
     * §12: "reserved == SUM(entries WHERE payout_id IN non-terminal payouts)".
     * A needs_review payout is non-terminal — invariant 24 keeps its entries
     * claimed rather than returning them — so its entries are reserved, not
     * released.
     */
    private const NON_TERMINAL_PAYOUT_STATUSES = ['pending', 'in_progress', 'needs_review'];

    /**
     * §1.1: level two verifies release + release_correction against
     * Σ released(). refund_adjustment is excluded, matching `posted` — those
     * entries are not derivable from released() and including them would make
     * the check fail by construction.
     */
    private const POSTED_TYPES = ['release', 'release_correction'];

    /**
     * §12 level zero asks whether a payment is fully allocated, which only has
     * an answer once allocation has had its chance. §5.1 dispatches the job
     * `afterCommit()` and `payments:allocate-missing` sweeps every five minutes,
     * so a payment confirmed moments before this runs is in flight, not broken.
     *
     * An hour is twelve sweep cycles. A payment still unallocated after that is
     * stuck, and the check is about the partial or missing set §5.1 says cannot
     * arise — not about a race with the queue.
     */
    private const ALLOCATION_GRACE_MINUTES = 60;

    public function __construct(private readonly ?string $connection = null) {}

    /**
     * All four levels, in order.
     *
     * @return array<int, ReconciliationFinding>
     */
    public function run(): array
    {
        return [
            ...$this->levelZero(),
            ...$this->levelOne(),
            ...$this->levelTwo(),
            ...$this->levelThree(),
        ];
    }

    /**
     * §12 level zero — every confirmed payment is fully allocated.
     *
     *     SUM(revenue_allocations.amount_minor) + platform_cut_minor == amount_minor
     *
     * §5.1's single transaction means a partial allocation set cannot arise and
     * AllocationService refuses one loudly if it finds one. But
     * `payments:allocate-missing` only picks up payments with *no* allocations,
     * so without this check a partial set created by a bug or a manual edit
     * would sit unnoticed. This is invariant 1, checked against live data
     * instead of only in tests.
     *
     * A confirmed payment with no allocations at all is reported too, once it is
     * past the grace window: the five-minutely sweeper should have cleared it by
     * then, so it is a finding rather than a payment in flight.
     *
     * @return array<int, ReconciliationFinding>
     */
    public function levelZero(): array
    {
        $db = $this->db();

        $confirmedBefore = now()->utc()->subMinutes(self::ALLOCATION_GRACE_MINUTES);

        $mismatched = $db->table('subscription_payments as p')
            ->leftJoin('revenue_allocations as ra', 'ra.payment_id', '=', 'p.id')
            // §5.1: confirmed means the provider gave us a reference. An
            // unconfirmed payment has no money to allocate yet.
            ->whereNotNull('p.provider_reference')
            // Still inside the grace window: allocation has not had its chance.
            //
            // paid_at rather than created_at, which §3.3 reserves for audit and
            // bars from every query filter — and rather than updated_at, which a
            // later edit would push forward, extending the window and delaying a
            // real detection. paid_at is written by the same conditional UPDATE
            // that sets provider_reference (§5.1), so it IS the confirmation
            // instant, and it never moves again.
            //
            // A confirmed payment with no paid_at is a broken row, so it is
            // checked rather than skipped: the window may only ever silence a
            // payment we can prove is recent.
            ->where(function (Builder $query) use ($confirmedBefore): void {
                $query->whereNull('p.paid_at')
                    ->orWhere('p.paid_at', '<=', $confirmedBefore);
            })
            ->groupBy('p.id', 'p.amount_minor', 'p.platform_cut_minor')
            ->havingRaw('COALESCE(SUM(ra.amount_minor), 0) + p.platform_cut_minor <> p.amount_minor')
            ->orderBy('p.id')
            ->get([
                'p.id',
                'p.amount_minor',
                'p.platform_cut_minor',
                $db->raw('COALESCE(SUM(ra.amount_minor), 0) AS allocated_minor'),
            ]);

        $findings = [];

        foreach ($mismatched as $payment) {
            $findings[] = new ReconciliationFinding(
                self::KIND_ALLOCATION_MISMATCH,
                self::SUBJECT_PAYMENT,
                (int) $payment->id,
                [
                    'amount_minor' => (int) $payment->amount_minor,
                    'allocated_minor' => (int) $payment->allocated_minor,
                    'platform_cut_minor' => (int) $payment->platform_cut_minor,
                    'shortfall_minor' => (int) $payment->amount_minor
                        - ((int) $payment->allocated_minor + (int) $payment->platform_cut_minor),
                ],
            );
        }

        return $findings;
    }

    /**
     * §12 level one — the cache agrees with the ledger.
     *
     *     available  == SUM(entries WHERE payout_id IS NULL)       -- may be negative
     *     reserved   == SUM(entries WHERE payout_id IN non-terminal payouts)
     *     paid       == SUM(entries WHERE payout_id IN settled payouts)
     *     recognized == available + reserved + paid
     *
     * @return array<int, ReconciliationFinding>
     */
    public function levelOne(): array
    {
        $db = $this->db();
        $findings = [];

        foreach ($this->instructorIds() as $instructorId) {
            $buckets = $this->ledgerBuckets($db, $instructorId);
            $cache = $this->balance($db, $instructorId);

            $cached = [
                'available_minor' => $cache === null ? 0 : (int) $cache->available_minor,
                'reserved_minor' => $cache === null ? 0 : (int) $cache->reserved_minor,
                'paid_minor' => $cache === null ? 0 : (int) $cache->paid_minor,
                'recognized_minor' => $cache === null ? 0 : (int) $cache->recognized_minor,
            ];

            $identity = $cached['available_minor'] + $cached['reserved_minor'] + $cached['paid_minor'];

            $agrees = $cached['available_minor'] === $buckets['available_minor']
                && $cached['reserved_minor'] === $buckets['reserved_minor']
                && $cached['paid_minor'] === $buckets['paid_minor']
                && $cached['recognized_minor'] === $identity
                // §12: "every entry is in exactly one of the three buckets at
                // all times". An entry stamped with a payout that is neither
                // non-terminal nor settled belongs to no bucket, and the only
                // way to see it is that the three sums do not add back up to
                // the ledger total.
                && $buckets['ledger_total_minor'] === $buckets['available_minor']
                    + $buckets['reserved_minor'] + $buckets['paid_minor'];

            if ($agrees) {
                continue;
            }

            $findings[] = new ReconciliationFinding(
                self::KIND_BUCKET_MISMATCH,
                self::SUBJECT_BALANCE,
                $instructorId,
                [
                    'cached' => $cached,
                    'ledger' => $buckets,
                    'cached_bucket_sum_minor' => $identity,
                    'balance_row_missing' => $cache === null,
                ],
            );
        }

        return $findings;
    }

    /**
     * §12 level two — the ledger agrees with what was sold.
     *
     *     SUM(release + release_correction) == Σ over allocations of released(alloc, watermark)
     *
     * Honest caveat, stated in §12 itself: since §6.2 the release job computes
     * this same expression, so this does not independently verify released().
     * It catches missed runs, partial writes, manual edits and constraint
     * bypasses; it does not catch wrong arithmetic. That is protected
     * separately, by the reference implementation in §16.1.
     *
     * @return array<int, ReconciliationFinding>
     */
    public function levelTwo(): array
    {
        $db = $this->db();
        $findings = [];

        foreach ($this->instructorIds() as $instructorId) {
            $balance = $this->balance($db, $instructorId);

            $watermark = $balance?->recognized_through_at === null
                ? null
                : $this->utc($balance->recognized_through_at);

            $posted = (int) $db->table('ledger_entries')
                ->where('instructor_id', $instructorId)
                ->whereIn('type', self::POSTED_TYPES)
                ->sum('amount_minor');

            // No watermark means nothing has been released, so nothing may have
            // been posted either.
            $expected = $watermark === null
                ? 0
                : ExpectedRecognition::forInstructor($db, $instructorId, $watermark);

            if ($posted === $expected) {
                continue;
            }

            $findings[] = new ReconciliationFinding(
                self::KIND_LEDGER_MISMATCH,
                self::SUBJECT_BALANCE,
                $instructorId,
                [
                    'posted_minor' => $posted,
                    'expected_minor' => $expected,
                    'drift_minor' => $posted - $expected,
                    'watermark' => $watermark?->format('Y-m-d H:i:s'),
                ],
            );
        }

        return $findings;
    }

    /**
     * §12 level three — the cursor agrees with the ledger.
     *
     *     instructor_balances.recognized_through_at == MAX(ledger_entries.recognized_through_at)
     *
     * The stored watermark is an operational cursor used for concurrency
     * control (§9.4); the ledger remains the audit truth. This check is what
     * keeps the cursor honest — a cursor that has drifted ahead of the ledger
     * would silently suppress legitimate release runs (§6.2, Hazard B).
     *
     * @return array<int, ReconciliationFinding>
     */
    public function levelThree(): array
    {
        $db = $this->db();
        $findings = [];

        foreach ($this->instructorIds() as $instructorId) {
            $balance = $this->balance($db, $instructorId);

            $cursor = $balance?->recognized_through_at === null
                ? null
                : $this->utc($balance->recognized_through_at)->format('Y-m-d H:i:s');

            $ledgerMax = $db->table('ledger_entries')
                ->where('instructor_id', $instructorId)
                ->max('recognized_through_at');

            $ledgerMax = $ledgerMax === null ? null : $this->utc($ledgerMax)->format('Y-m-d H:i:s');

            if ($cursor === $ledgerMax) {
                continue;
            }

            $findings[] = new ReconciliationFinding(
                self::KIND_WATERMARK_DRIFT,
                self::SUBJECT_BALANCE,
                $instructorId,
                [
                    'cursor' => $cursor,
                    'ledger_max' => $ledgerMax,
                ],
            );
        }

        return $findings;
    }

    /**
     * Every instructor the ledger or the cache knows about. An instructor with
     * ledger rows but no balance row is itself a finding, so the driving set is
     * the union rather than either table alone.
     *
     * @return Generator<int, int>
     */
    private function instructorIds(): Generator
    {
        $db = $this->db();

        $rows = $db->table('instructor_balances')
            ->select('instructor_id')
            ->union($db->table('ledger_entries')->select('instructor_id')->distinct())
            ->orderBy('instructor_id')
            // §17: the ledger is unbounded, so the driving set is streamed
            // rather than materialised. The per-instructor checks below are one
            // small query each, which is what a nightly job can afford and a
            // request path could not (§13).
            ->cursor();

        foreach ($rows as $row) {
            yield (int) $row->instructor_id;
        }
    }

    /**
     * §12 level one: the three buckets, computed from the ledger rather than
     * read from the cache — that is the whole point of the check.
     *
     * @return array<string, int>
     */
    private function ledgerBuckets(Connection $db, int $instructorId): array
    {
        $nonTerminal = "'".implode("', '", self::NON_TERMINAL_PAYOUT_STATUSES)."'";

        $row = $db->table('ledger_entries as le')
            ->leftJoin('payouts as po', 'po.id', '=', 'le.payout_id')
            ->where('le.instructor_id', $instructorId)
            ->first([
                $db->raw('COALESCE(SUM(CASE WHEN le.payout_id IS NULL THEN le.amount_minor END), 0) AS available_minor'),
                $db->raw("COALESCE(SUM(CASE WHEN po.status IN ({$nonTerminal}) THEN le.amount_minor END), 0) AS reserved_minor"),
                $db->raw("COALESCE(SUM(CASE WHEN po.status = 'settled' THEN le.amount_minor END), 0) AS paid_minor"),
                $db->raw('COALESCE(SUM(le.amount_minor), 0) AS ledger_total_minor'),
            ]);

        return [
            'available_minor' => (int) ($row->available_minor ?? 0),
            'reserved_minor' => (int) ($row->reserved_minor ?? 0),
            'paid_minor' => (int) ($row->paid_minor ?? 0),
            'ledger_total_minor' => (int) ($row->ledger_total_minor ?? 0),
        ];
    }

    private function balance(Connection $db, int $instructorId): ?object
    {
        return $db->table('instructor_balances')
            ->where('instructor_id', $instructorId)
            ->first();
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /** §3.3: all timestamps are stored and compared in UTC. */
    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
