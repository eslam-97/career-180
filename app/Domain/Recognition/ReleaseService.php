<?php

declare(strict_types=1);

namespace App\Domain\Recognition;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * §6.2: the release job — cumulative delta, serialised, monotonic.
 *
 *     W           = instructor_balances.recognized_through_at
 *     expected(t) = Σ over that instructor's allocations of released(alloc, t)
 *     posted      = Σ of that instructor's release + release_correction entries
 *     delta       = expected(t) − posted
 *
 * Two callers, one formula, differing only in what `t` is:
 *
 *     scheduled release for target T   t = T   advances W → T; no-op if T ≤ W
 *     event correction (refund)        t = W   watermark held
 *
 * Always the cumulative total, never an increment. A missed month is healed by
 * the next run on its own, which is the property the whole design is built on.
 */
final class ReleaseService
{
    private const TYPE_RELEASE = 'release';

    private const TYPE_RELEASE_CORRECTION = 'release_correction';

    /**
     * §1.1: `posted` is release + release_correction and deliberately NOT
     * refund_adjustment. That type records an adjustment not derivable from
     * released() — a goodwill credit, a chargeback, a manual correction — so
     * including it here would make the job compute a delta that silently
     * reverses the refund (invariant 6).
     */
    private const POSTED_TYPES = [self::TYPE_RELEASE, self::TYPE_RELEASE_CORRECTION];

    /**
     * §16.2: "concurrency needs real concurrency". The connection name is a
     * seam so a two-connection test can drive this service on a second real
     * connection and prove the FOR UPDATE below actually blocks. Null is the
     * configured default, which is what production always uses.
     */
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * §6.2: the scheduled run. `t = T`, and the watermark advances to T.
     *
     * $through is the recognition horizon: the last UTC midnight of the posting
     * month, so the March run passes 2026-03-31 and keys its row on
     * 'period:2026-03'.
     */
    public function releaseScheduled(int $instructorId, DateTimeImmutable $through): void
    {
        $this->post(
            instructorId: $instructorId,
            scheduled: true,
            target: $through,
            sourceRef: null,
            effectiveAt: null,
        );
    }

    /**
     * §6.2: the event correction. `t = W`, and the watermark is NOT touched.
     *
     * Corrections legitimately move the cumulative total backward — that is
     * their purpose — and they do so without advancing time, so the delta is
     * purely the effect of the cap change and never contaminated by elapsed
     * days (invariant 15).
     *
     * $sourceRef keys on the *event* ('refund:9812'), never on the period: a
     * refund landing between two runs of the same month would otherwise compute
     * a nonzero delta it could not insert (§6.2, §14).
     *
     * $effectiveAt is the triggering event's business date, which §3.3 keeps
     * distinct from both other dates on the row: a refund dated 15 March and
     * processed 3 April against a watermark of 31 March posts period_start
     * 2026-04, recognized_through_at 2026-03-31, effective_at 2026-03-15. It is
     * also the payout-eligibility predicate (§10.1), so it cannot be derived
     * from W or from now().
     */
    public function correct(int $instructorId, string $sourceRef, DateTimeImmutable $effectiveAt): void
    {
        $this->post(
            instructorId: $instructorId,
            scheduled: false,
            target: null,
            sourceRef: $sourceRef,
            effectiveAt: $effectiveAt,
        );
    }

    /**
     * §6.2's transaction block. The order of the statements is the contract,
     * not an optimisation.
     */
    private function post(
        int $instructorId,
        bool $scheduled,
        ?DateTimeImmutable $target,
        ?string $sourceRef,
        ?DateTimeImmutable $effectiveAt,
    ): void {
        $db = $this->db();

        // §9.2: the closure stays pure — reads and writes only. DB::transaction
        // retries it on deadlock, so a side effect in here would fire twice.
        $db->transaction(function () use (
            $db,
            $instructorId,
            $scheduled,
            $target,
            $sourceRef,
            $effectiveAt,
        ): void {
            // §9.4: the instructor_balances row is the per-instructor
            // serialisation point, and this is the FIRST statement in the
            // transaction. It is what makes the `posted` read below safe (§6.2,
            // Hazard A) — and, because it is first, it is also what establishes
            // this transaction's read view, so every plain read after it sees
            // everything committed before the lock was taken. A refund
            // committed a moment before we acquired the lock is therefore
            // visible to expected(t).
            $balance = $db->table('instructor_balances')
                ->where('instructor_id', $instructorId)
                ->lockForUpdate()
                ->first();

            // §9.4: "a lock on a missing row locks nothing". Allocation creates
            // the row; with no row there is no serialisation point, so nothing
            // may be posted.
            if ($balance === null) {
                return;
            }

            $watermark = $balance->recognized_through_at === null
                ? null
                : $this->utc($balance->recognized_through_at);

            if ($scheduled) {
                // §6.2, Hazard B: a scheduled release may only ADVANCE the
                // watermark. This guard is inside the locked section on
                // purpose — reading W before the lock would read it from this
                // transaction's own snapshot, and acting on that stale value is
                // the bug the guard exists to stop.
                if ($watermark !== null && $target <= $watermark) {
                    return;
                }

                $t = $target;
            } else {
                // §6.2: "a correction firing before any release exists sees
                // expected = 0, posted = 0, delta = 0" — correct, since nothing
                // was recognized to correct.
                if ($watermark === null) {
                    return;
                }

                $t = $watermark;
            }

            // §1.1: refund_adjustment is excluded. See POSTED_TYPES.
            $posted = (int) $db->table('ledger_entries')
                ->where('instructor_id', $instructorId)
                ->whereIn('type', self::POSTED_TYPES)
                ->sum('amount_minor');

            $delta = ExpectedRecognition::forInstructor($db, $instructorId, $t) - $posted;

            // §6.2: delta == 0 → post nothing.
            //
            // Nothing else happens either: the watermark is NOT advanced on a
            // zero delta. Invariant 16 and §12 level three require
            // recognized_through_at == MAX(ledger.recognized_through_at), and an
            // instructor whose term has finished yields delta == 0 on every run
            // from then on — advancing here would put the cursor permanently
            // ahead of the ledger. A lagging W costs nothing, because the next
            // run recomputes expected(t) from scratch rather than from W.
            if ($delta === 0) {
                return;
            }

            // §6.2: delta > 0 → release; delta < 0 → release_correction. One
            // operation, two triggers — "release correction and refund
            // adjustment are the same operation", one fired by a time advance,
            // the other by an event.
            $type = $delta > 0 ? self::TYPE_RELEASE : self::TYPE_RELEASE_CORRECTION;

            $db->table('ledger_entries')->insert([
                'instructor_id' => $instructorId,
                'type' => $type,
                // §3: SIGNED, and the sign is the whole mechanism.
                'amount_minor' => $delta,
                // §6.3: period_start is when the row was POSTED, not the span it
                // recognizes. A missed March means April's row carries March's
                // recognition too, and that is stated rather than hidden.
                'period_start' => ($scheduled ? $t : $this->now())
                    ->modify('first day of this month')
                    ->format('Y-m-d'),
                // §3.3: the recognition horizon this row accounts for. For a
                // correction this is W, unchanged — a correction does not
                // advance time (invariant 15).
                'recognized_through_at' => $t->format('Y-m-d H:i:s'),
                // §3.3: the business-effective instant. For a scheduled run the
                // business event IS the passage of time to T; for a correction
                // it is the triggering event's own date.
                'effective_at' => ($scheduled ? $t : $effectiveAt)->format('Y-m-d H:i:s'),
                // §14: NOT NULL with a deterministic value, because MySQL
                // permits many NULLs in a unique index. With
                // UNIQUE(instructor_id, type, source_ref) this is the constraint
                // half of invariant 26.
                'source_ref' => $scheduled ? 'period:'.$t->format('Y-m') : $sourceRef,
                // §10.1: unclaimed. The payout claim is what stamps this.
                'payout_id' => null,
                // §14: ledger_entries has created_at but no updated_at, so
                // LedgerEntry sets UPDATED_AT = null and this is set by hand.
                'created_at' => $this->now()->format('Y-m-d H:i:s'),
            ]);

            // §10.5: the cache is maintained by the transaction that moves the
            // ledger rows, never by reconciliation. `recognized` moves on the
            // delta whatever the entry type, and `available` moves with it — a
            // negative delta drives available negative, which is debt and nets
            // against future recognition with no manual step (§10.4).
            $extra = ['updated_at' => $this->now()->format('Y-m-d H:i:s')];

            if ($scheduled) {
                // §6.2: "recognized_through_at = :t — scheduled runs only".
                $extra['recognized_through_at'] = $t->format('Y-m-d H:i:s');
            }

            // §10.5: every update is `SET col = col + ?`, never a
            // read-modify-write in PHP.
            $db->table('instructor_balances')
                ->where('instructor_id', $instructorId)
                ->incrementEach([
                    'recognized_minor' => $delta,
                    'available_minor' => $delta,
                ], $extra);
        });
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

    private function now(): DateTimeImmutable
    {
        return $this->utc(now()->utc()->format('Y-m-d H:i:s'));
    }
}
