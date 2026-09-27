<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * §10.3 / §10.4: claim one instructor's eligible ledger entries into one payout,
 * inside one transaction, and commit only if the claimed sum is positive.
 *
 *     TRANSACTION
 *         lock instructor_balances row                     (§9.4)
 *         insert payout (pending, amount NULL)
 *         claim ledger entries -> payout_id                (§10.1)
 *         sum = SUM(claimed)
 *
 *         sum <= 0  ->  ROLLBACK   no payout row, entries stay unclaimed
 *         sum >  0  ->  set amount; update cache; COMMIT   (§10.5)
 *     COMMIT
 *     --- then, and only then ---
 *         acquire slot + create attempt (§10.2), call provider
 *
 * That last line is ClaimInstructorPayout's, once this transaction has
 * committed and only for a payout this call actually created — which is why
 * claimResult() reports the `created` flag out of the transaction itself
 * (§10.3). `payouts:sweep-stranded` (§10.7) is recovery for a dispatch that was
 * lost, never the ordinary road out of here.
 *
 * No provider call happens here, and no lock is ever held across one. The order
 * of the statements below is the contract, not an optimisation.
 */
final class ClaimService
{
    /**
     * §10.1: the payable allowlist, enumerated rather than assumed. Today every
     * type in the table is payable, so this clause is a no-op — which is
     * exactly why it is written now. A future tax_withholding, audit_note or
     * accrual_memo added by someone who has never read ARCHITECTURE.md would
     * otherwise become payable by default (invariant 29).
     */
    private const PAYABLE_TYPES = ['release', 'release_correction', 'refund_adjustment'];

    private const STATUS_PENDING = 'pending';

    /**
     * §16.2: the connection name is a seam so a two-connection test can drive
     * this on a second real connection and prove the FOR UPDATE below actually
     * blocks. Null is the configured default, which production always uses.
     */
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * @return int|null the payout id, or null when this instructor produced no
     *                  payout in this batch — nothing eligible, a net-negative
     *                  claim (§10.4), or a batch that already has one (§11.4).
     */
    public function claim(int $instructorId, PayoutBatch $batch): ?int
    {
        return $this->claimResult($instructorId, $batch)->payoutId;
    }

    /**
     * The same claim, reported in full: the payout id AND whether this call is
     * what created it. §10.3's attempt dispatch hangs off that second value,
     * and nothing but the transaction below may decide it (§9.4).
     */
    public function claimResult(int $instructorId, PayoutBatch $batch): ClaimResult
    {
        $db = $this->db();

        try {
            // The try wraps the transaction, never its body: DB::transaction()
            // retries its closure on deadlock, and the closure stays pure —
            // reads and writes only, no dispatch, no HTTP call (§9.2).
            return $db->transaction(function () use ($db, $instructorId, $batch): ClaimResult {
                // §9.4: the instructor_balances row is the per-instructor
                // serialisation point, and this is the FIRST statement in the
                // transaction. Every read below it — the existing-payout check,
                // the claim, the sum — is taken under it.
                $balance = $db->table('instructor_balances')
                    ->where('instructor_id', $instructorId)
                    ->lockForUpdate()
                    ->first();

                // §9.4: "a lock on a missing row locks nothing". Allocation
                // creates the row; with no row there is no serialisation point,
                // so nothing may be claimed.
                if ($balance === null) {
                    return ClaimResult::none();
                }

                // §11.4, "command run twice": one payout per instructor per
                // batch. Read under the lock, so a second run — or the rerun
                // that recovers a lost claim job — is a no-op rather than an
                // auto-increment burn. UNIQUE(batch_id, instructor_id) stays
                // the guarantee behind it (invariant 25).
                //
                // §10.3: reported as `existing`, never `created` — this payout
                // may already have an attempt in flight, and the caller must
                // not start a second one for it.
                $existing = $db->table('payouts')
                    ->where('batch_id', $batch->id)
                    ->where('instructor_id', $instructorId)
                    ->value('id');

                if ($existing !== null) {
                    return ClaimResult::existing((int) $existing);
                }

                // §10.4: the id must exist before the entries can be stamped
                // with it, but the amount is not known until after the claim.
                // This NULL never survives the transaction — CHECK
                // (amount_minor IS NULL OR amount_minor > 0) permits the
                // intermediate, and the transaction structure is what
                // guarantees it is never committed (invariant 17).
                $payoutId = (int) $db->table('payouts')->insertGetId([
                    'batch_id' => $batch->id,
                    'instructor_id' => $instructorId,
                    'amount_minor' => null,
                    'status' => self::STATUS_PENDING,
                    // §10.2: only a worker acquiring the slot moves this.
                    'attempt_count' => 0,
                    'settled_at' => null,
                    'created_at' => $this->now()->format('Y-m-d H:i:s'),
                    'updated_at' => $this->now()->format('Y-m-d H:i:s'),
                ]);

                // §10.1: the claim query, and every predicate is load-bearing.
                // effective_at is business-time eligibility; max_entry_id is
                // frozen at batch creation and is what makes the batch a
                // genuine snapshot, so a backdated entry that arrived after the
                // batch was opened passes the date test and fails this one
                // (invariant 28). The type allowlist is invariant 29.
                //
                // §9.3: ownership is decided by this conditional UPDATE. A
                // claimed count of zero needs no branch of its own — it sums to
                // zero and falls into the rollback below.
                $db->table('ledger_entries')
                    ->where('instructor_id', $instructorId)
                    ->whereNull('payout_id')
                    ->whereIn('type', self::PAYABLE_TYPES)
                    ->where('id', '<=', $batch->max_entry_id)
                    ->where('effective_at', '<=', $batch->cutoff_at->format('Y-m-d H:i:s'))
                    ->update(['payout_id' => $payoutId]);

                // §10.4: the sign is checked AFTER the claim, because the
                // entries are already stamped by the time the sum is known. A
                // claimed set can hold both releases and negative corrections,
                // so this is not necessarily positive.
                $sum = (int) $db->table('ledger_entries')
                    ->where('payout_id', $payoutId)
                    ->sum('amount_minor');

                if ($sum <= 0) {
                    // §10.4: "ROLLBACK — no payout row, entries stay
                    // unclaimed". Debt is not a payout: the negative entries go
                    // back to being unclaimed, exactly where a positive balance
                    // lives, and the next batch sweeps them up with no
                    // carry-forward mechanism (invariant 33).
                    //
                    // The whole transaction is discarded rather than a
                    // zero-amount row being written and deleted afterwards —
                    // that row would be visible to another transaction in
                    // between, which is invariant 17.
                    throw new NonPositiveClaim;
                }

                $db->table('payouts')
                    ->where('id', $payoutId)
                    ->update([
                        'amount_minor' => $sum,
                        'updated_at' => $this->now()->format('Y-m-d H:i:s'),
                    ]);

                // §10.5: the cache is maintained by the transaction that moves
                // the ledger rows, never by reconciliation. A claim moves
                // available -> reserved and leaves recognized untouched, which
                // is exactly what §12's bucket identity asserts.
                //
                // Every update is `SET col = col + ?`, never a
                // read-modify-write in PHP.
                $db->table('instructor_balances')
                    ->where('instructor_id', $instructorId)
                    ->incrementEach([
                        'available_minor' => -$sum,
                        'reserved_minor' => $sum,
                    ], ['updated_at' => $this->now()->format('Y-m-d H:i:s')]);

                // §10.3: the one branch that inserted a payout and is about to
                // commit it, so the one branch that may report `created`. The
                // flag leaves the transaction as its return value — a separate
                // read outside it would let two concurrent workers both claim
                // authorship of the same row (§9.4).
                return ClaimResult::created($payoutId);
            });
        } catch (NonPositiveClaim) {
            // §10.5: "claim rolled back — none. The whole transaction is
            // discarded." There is nothing to undo here, and nothing to report
            // upward but the absence of a payout.
            return ClaimResult::none();
        }
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /** §3.3: all timestamps are stored and compared in UTC. */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(now()->utc()->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }
}
