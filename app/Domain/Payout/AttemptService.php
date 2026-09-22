<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * §10.2: a payout is not an attempt.
 *
 *     payout           pending -> in_progress -> settled | failed | needs_review
 *     payout_attempt   sending -> succeeded | failed | unknown -> ...
 *
 * The property this class exists to hold:
 *
 *     attempt_count increments IF AND ONLY IF the worker acquires the slot.
 *
 * A condition on the payout's own status cannot give that — 'in_progress'
 * satisfies such a predicate for both workers, so the loser would increment and
 * only then discover via UNIQUE(active_payout_id) that it cannot insert, which
 * makes the constraint the mechanism and the gate the backstop. The gate below
 * does the work; the constraint stays the backstop (invariant 19).
 *
 *     TRANSACTION
 *         SELECT * FROM instructor_balances WHERE instructor_id = :iid FOR UPDATE
 *         SELECT * FROM payouts             WHERE id = :id           FOR UPDATE
 *
 *         abort unless  status IN ('pending', 'in_progress')
 *                  and  attempt_count < :ceiling
 *                  and  no attempt for this payout in ('sending', 'unknown')
 *
 *         UPDATE payouts SET status = 'in_progress', attempt_count = attempt_count + 1
 *         INSERT attempt (sending, key = hash(payout_id, n), lease_expires_at)
 *     COMMIT
 *
 * No provider call happens here (§10.3): a database lock is never held across a
 * network call.
 */
final class AttemptService
{
    /** §10.2: there is NO 'pending' attempt state. */
    private const STATUS_SENDING = 'sending';

    /**
     * §10.2: the statuses that mean an attempt is still in flight — the same
     * list the generated active_payout_id column is defined on.
     */
    private const LIVE_ATTEMPT_STATUSES = ['sending', 'unknown'];

    /**
     * §10.2: "excluding needs_review means a human-flagged payout cannot be
     * silently retried by a worker" — terminal for automation even though its
     * money is still reserved (§10.7, invariant 24).
     */
    private const ACQUIRABLE_PAYOUT_STATUSES = ['pending', 'in_progress'];

    /**
     * §16.2: the connection name is a seam so a two-connection test can drive
     * this on a second real connection and prove the locks below actually block.
     */
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * §10.2: "the ceiling belongs in this gate, not only in the sweeper (§10.7)
     * — otherwise the gate can hand out an attempt the sweeper would have
     * refused". One definition, read by both.
     */
    public static function ceiling(): int
    {
        return (int) config('payouts.attempt_ceiling');
    }

    /**
     * §9.1: "every key is derived deterministically — hash(payout_id,
     * attempt_no) — so a re-run produces the SAME key and the insert simply
     * fails rather than creating a duplicate." The provider deduplicates on the
     * same value (§11.3).
     */
    public static function idempotencyKey(int $payoutId, int $attemptNo): string
    {
        return hash('sha256', "payout:{$payoutId}:attempt:{$attemptNo}");
    }

    /** @return AttemptSlot|null null when this worker lost the race, or the payout is not acquirable. */
    public function acquire(int $payoutId): ?AttemptSlot
    {
        $db = $this->db();

        // §9.2: the closure stays pure — reads and writes only. The provider
        // call is the caller's job, after this commits (§11.1's boundary).
        return $db->transaction(function () use ($db, $payoutId): ?AttemptSlot {
            // §9.3: an unlocked read is only unsafe when a write depends on it.
            // instructor_id is frozen when the payout is created and never
            // edited, and it is used here solely to name the lock to take next.
            $instructorId = $db->table('payouts')->where('id', $payoutId)->value('instructor_id');

            if ($instructorId === null) {
                return null;
            }

            // §9.4: the instructor_balances row is the per-instructor
            // serialisation point, and this is the FIRST statement. Every read
            // below it — the payout row, the live-attempt check — is taken under
            // it, which is what makes them current reads rather than snapshot
            // reads (§10.2: "worker B blocks on the locks until A commits, then
            // reads payout_attempts under them as a current read").
            $balance = $db->table('instructor_balances')
                ->where('instructor_id', $instructorId)
                ->lockForUpdate()
                ->first();

            // §9.4: "a lock on a missing row locks nothing".
            if ($balance === null) {
                return null;
            }

            $payout = $db->table('payouts')->where('id', $payoutId)->lockForUpdate()->first();

            if ($payout === null) {
                return null;
            }

            // §10.2's gate — all three conditions, before anything is written.
            if (! in_array($payout->status, self::ACQUIRABLE_PAYOUT_STATUSES, true)) {
                return null;
            }

            if ((int) $payout->attempt_count >= self::ceiling()) {
                return null;
            }

            $live = $db->table('payout_attempts')
                ->where('payout_id', $payoutId)
                ->whereIn('status', self::LIVE_ATTEMPT_STATUSES)
                ->exists();

            // §10.2: "the loser exits" — and exits without touching the counter.
            // Falling through to attempt #3 here would put a second transfer on
            // the wire while the winner is still sending #2.
            if ($live) {
                return null;
            }

            $now = $this->now();

            // §11.1: the increment and the insert are one transaction, so
            // "crash before the first commit: no attempt row exists and
            // attempt_count was not incremented, because both are in the same
            // transaction". §10.7 re-dispatches that payout.
            $db->table('payouts')
                ->where('id', $payoutId)
                ->incrementEach(['attempt_count' => 1], [
                    'status' => 'in_progress',
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

            $attemptNo = (int) $payout->attempt_count + 1;
            $amountMinor = (int) $payout->amount_minor;
            $idempotencyKey = self::idempotencyKey($payoutId, $attemptNo);

            // §10.2: created already in 'sending', inside the transaction that
            // acquires the slot, "so no attempt can exist without a worker
            // having committed to calling the provider".
            $attemptId = (int) $db->table('payout_attempts')->insertGetId([
                'payout_id' => $payoutId,
                'attempt_no' => $attemptNo,
                'idempotency_key' => $idempotencyKey,
                'status' => self::STATUS_SENDING,
                'provider_reference' => null,
                // §10.2: the per-attempt evidence trail — what was asked, and
                // later what came back. Losing it is why an attempts counter
                // alone was rejected.
                'request_payload' => json_encode([
                    'amount_minor' => $amountMinor,
                    'idempotency_key' => $idempotencyKey,
                    'attempt_no' => $attemptNo,
                ], JSON_THROW_ON_ERROR),
                'response_payload' => null,
                'resolution_evidence' => null,
                'started_at' => $now->format('Y-m-d H:i:s'),
                // §11.1: the lease. Once it passes with the attempt still
                // 'sending', the sweeper's only legal move is -> 'unknown'.
                'lease_expires_at' => $now->modify('+'.$this->leaseSeconds().' seconds')->format('Y-m-d H:i:s'),
                'polled_at' => null,
                'poll_count' => 0,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);

            return new AttemptSlot(
                id: $attemptId,
                payoutId: $payoutId,
                instructorId: (int) $instructorId,
                attemptNo: $attemptNo,
                idempotencyKey: $idempotencyKey,
                amountMinor: $amountMinor,
            );
        });
    }

    private function leaseSeconds(): int
    {
        return (int) config('payouts.lease_seconds');
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
