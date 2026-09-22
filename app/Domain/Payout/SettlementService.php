<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Provider\Outcome;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * §10.6: each terminal transition is exactly one transaction.
 *
 *     claim      available -> reserved      §10.3
 *     success    reserved  -> paid          recordSuccess()
 *     failure    reserved  -> available     failPayout()
 *
 * "Split any of them across two commits and a crash in between leaves entries in
 * no bucket at all — a direct violation of §12" (invariant 4).
 *
 * Two rules govern every method here:
 *
 *  - §9.4 — instructor_balances FOR UPDATE first, then the payout. This class
 *    writes attempts, payouts and ledger entries, so it takes the serialisation
 *    point before it reads any of them.
 *  - §10.6 — "the balance update is the one without a natural guard", so it is
 *    gated on the payout transition's affected-row count. Three individually
 *    conditional statements are not automatically safe together: a replayed
 *    settlement finds status = 'settled', affects zero rows on the payout, and
 *    would still move reserved -> paid a second time if the cache update ran
 *    unconditionally (invariant 23).
 */
final class SettlementService
{
    /**
     * §11.2: the outcome predicate. 'unresolved' is in it deliberately — it
     * means "the provider has not told us yet", not "it failed", and a
     * definitive answer arriving a day later is still authoritative. "Excluding
     * unresolved would silently drop a confirmed transfer, which is the same
     * class of bug as double-paying" (invariant 21).
     */
    private const RECORDABLE_ATTEMPT_STATUSES = ['sending', 'unknown', 'unresolved'];

    /** §10.6: an attempt in either of these is still alive, so the payout cannot be failed. */
    private const LIVE_ATTEMPT_STATUSES = ['sending', 'unknown'];

    /**
     * §10.6: the payout statuses a terminal transition may move FROM. 'settled'
     * and 'failed' are absent because they are terminal; 'pending' is absent
     * because a payout with no attempt has nothing to settle or fail.
     */
    private const TRANSITIONABLE_PAYOUT_STATUSES = ['in_progress', 'needs_review'];

    /** §10.6 / §14: the one path knowingly unrecoverable by machine (§19). */
    private const KIND_LATE_SUCCESS = 'late_success_on_failed_payout';

    private const SUBJECT_PAYOUT = 'payout';

    /** §16.2: the connection seam, as on ClaimService and AttemptService. */
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * §10.6's settlement transaction, plus its "late success" branch.
     *
     * @return bool true when this call is the one that settled the payout.
     */
    public function recordSuccess(int $attemptId, Outcome $outcome): bool
    {
        return $this->forAttempt($attemptId, function (Connection $db, object $attempt, object $payout) use ($outcome): bool {
            $now = $this->now();

            // §11.2: recorded from sending, unknown AND unresolved.
            $recorded = $db->table('payout_attempts')
                ->where('id', $attempt->id)
                ->whereIn('status', self::RECORDABLE_ATTEMPT_STATUSES)
                ->update([
                    'status' => 'succeeded',
                    'provider_reference' => $outcome->reference,
                    'response_payload' => json_encode($outcome->payload, JSON_THROW_ON_ERROR),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

            $affected = $db->table('payouts')
                ->where('id', $payout->id)
                ->whereIn('status', self::TRANSITIONABLE_PAYOUT_STATUSES)
                ->update([
                    'status' => 'settled',
                    'settled_at' => $now->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

            if ($affected === 0) {
                // §10.6, "late success on an already-failed payout": the
                // precondition narrows this window but cannot close it (§11.3).
                // The entries may already belong to another payout, so no money
                // moves. The attempt is still recorded as succeeded — dropping
                // it would be the same class of bug — and a human is paged.
                //
                // Guarded on $recorded so a repeated poll re-raises nothing: the
                // second call finds the attempt already 'succeeded', updates
                // zero rows, and writes no second alert.
                if ($recorded === 1 && $payout->status === 'failed') {
                    $this->alertLateSuccess($db, $payout, $attempt, $outcome);
                }

                // Otherwise this is a replay against an already-settled payout
                // (invariant 23) — commit, and move no money.
                return false;
            }

            // §10.5: reserved -= amount, paid += amount. Gated on $affected
            // above, and always `SET col = col + ?`.
            $this->moveBalance($db, (int) $payout->instructor_id, [
                'reserved_minor' => -(int) $payout->amount_minor,
                'paid_minor' => (int) $payout->amount_minor,
            ], $now);

            return true;
        }) ?? false;
    }

    /**
     * §11.1: a definitive failure from the provider. It resolves the ATTEMPT
     * only — the payout's own failure is §10.6's separate transaction, because
     * the entries may yet be sent by a further attempt ("provider permanent
     * failure → new attempt, new key, entries stay claimed", §11.4).
     */
    public function recordFailure(int $attemptId, Outcome $outcome): bool
    {
        return $this->recordAttemptOutcome($attemptId, 'failed', self::RECORDABLE_ATTEMPT_STATUSES, $outcome);
    }

    /**
     * §11: "a timeout is not a failure, and neither is a crash after dispatch".
     * Both collapse into this, and only from 'sending' — the same single legal
     * transition the lease sweeper has (§11.1).
     */
    public function recordUnknown(int $attemptId, Outcome $outcome): bool
    {
        return $this->recordAttemptOutcome($attemptId, 'unknown', ['sending'], $outcome);
    }

    /**
     * §11.2: polling exhausted its backoff. The attempt becomes 'unresolved' and
     * the payout moves to needs_review — money still claimed, a human alerted,
     * NEVER auto-released. That is us giving up, not the provider saying no.
     */
    public function markUnresolved(int $attemptId): bool
    {
        return $this->forAttempt($attemptId, function (Connection $db, object $attempt, object $payout): bool {
            $now = $this->now();

            $moved = $db->table('payout_attempts')
                ->where('id', $attempt->id)
                ->where('status', 'unknown')
                ->update([
                    'status' => 'unresolved',
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

            if ($moved === 0) {
                return false;
            }

            // §10.7: needs_review is non-terminal for money — its entries stay
            // reserved, which is why §12 counts them in the reserved bucket —
            // and terminal for automation. No balance moves here.
            $db->table('payouts')
                ->where('id', $payout->id)
                ->where('status', 'in_progress')
                ->update([
                    'status' => 'needs_review',
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

            return true;
        }) ?? false;
    }

    /**
     * §11.2: one poll that came back UNKNOWN. Recorded so the backoff can be
     * counted, and so "asked 12 times, no clear answer" is evidence rather than
     * folklore.
     *
     * @return int the attempt's poll count after this poll.
     */
    public function recordPoll(int $attemptId): int
    {
        return $this->forAttempt($attemptId, function (Connection $db, object $attempt, object $payout): int {
            $now = $this->now();

            $db->table('payout_attempts')
                ->where('id', $attempt->id)
                ->incrementEach(['poll_count' => 1], [
                    'polled_at' => $now->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

            return (int) $attempt->poll_count + 1;
        }) ?? 0;
    }

    /**
     * §10.6's failure transaction, preconditions included.
     *
     * $evidence records a human resolving an 'unresolved' attempt as failed
     * (§11.2) — "an assertion about evidence OUTSIDE the system. It is
     * permitted, it is recorded with that evidence, and low-rate polling
     * continues indefinitely afterwards so a contradicting success is detected
     * rather than lost". The attempt therefore STAYS 'unresolved'; only the
     * payout becomes failed.
     *
     * @return bool true when this call is the one that failed the payout.
     */
    public function failPayout(int $payoutId, ?string $evidence = null): bool
    {
        return $this->forPayout($payoutId, function (Connection $db, object $payout) use ($evidence): bool {
            // §10.6: "the attempt precondition is the fix for a real money bug".
            // Without it a payout could be failed while an attempt was still
            // unknown and polling; the later poll would set that attempt
            // succeeded with the entries already released back to available —
            // the provider moved the money and the system believes it did not
            // (invariant 20).
            $live = $db->table('payout_attempts')
                ->where('payout_id', $payout->id)
                ->whereIn('status', self::LIVE_ATTEMPT_STATUSES)
                ->exists();

            if ($live) {
                return false;
            }

            $succeeded = $db->table('payout_attempts')
                ->where('payout_id', $payout->id)
                ->where('status', 'succeeded')
                ->exists();

            if ($succeeded) {
                return false;
            }

            $now = $this->now();

            $affected = $db->table('payouts')
                ->where('id', $payout->id)
                ->whereIn('status', self::TRANSITIONABLE_PAYOUT_STATUSES)
                ->update([
                    'status' => 'failed',
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

            // §10.6: "someone else resolved it; COMMIT and move no money".
            if ($affected === 0) {
                return false;
            }

            if ($evidence !== null) {
                $db->table('payout_attempts')
                    ->where('payout_id', $payout->id)
                    ->where('status', 'unresolved')
                    ->update([
                        'resolution_evidence' => $evidence,
                        'updated_at' => $now->format('Y-m-d H:i:s'),
                    ]);
            }

            // §10.6: the un-stamp IS naturally idempotent, so a transaction
            // retried after a deadlock is safe. The balance move below is not,
            // which is why it is gated on $affected.
            $db->table('ledger_entries')
                ->where('payout_id', $payout->id)
                ->update(['payout_id' => null]);

            // §10.5: reserved -= amount, available += amount (invariant 32).
            $this->moveBalance($db, (int) $payout->instructor_id, [
                'reserved_minor' => -(int) $payout->amount_minor,
                'available_minor' => (int) $payout->amount_minor,
            ], $now);

            return true;
        }) ?? false;
    }

    /** §11.1's outcome recording, with the predicate the transition is legal from. */
    private function recordAttemptOutcome(int $attemptId, string $status, array $from, Outcome $outcome): bool
    {
        return $this->forAttempt($attemptId, function (Connection $db, object $attempt) use ($status, $from, $outcome): bool {
            $now = $this->now();

            return $db->table('payout_attempts')
                ->where('id', $attempt->id)
                ->whereIn('status', $from)
                ->update([
                    'status' => $status,
                    'response_payload' => json_encode($outcome->payload, JSON_THROW_ON_ERROR),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]) === 1;
        }) ?? false;
    }

    /**
     * §10.6 / §19: the alert, and nothing else. No repair is attempted, because
     * none is possible by machine — the entries may already belong to a
     * different payout (§11.3).
     */
    private function alertLateSuccess(Connection $db, object $payout, object $attempt, Outcome $outcome): void
    {
        $now = $this->now();

        $db->table('reconciliation_alerts')->insert([
            'kind' => self::KIND_LATE_SUCCESS,
            'subject_type' => self::SUBJECT_PAYOUT,
            'subject_id' => (int) $payout->id,
            'detail' => json_encode([
                'attempt_id' => (int) $attempt->id,
                'attempt_no' => (int) $attempt->attempt_no,
                'idempotency_key' => $attempt->idempotency_key,
                'provider_reference' => $outcome->reference,
                'payout_status' => $payout->status,
                'amount_minor' => (int) $payout->amount_minor,
                'instructor_id' => (int) $payout->instructor_id,
            ], JSON_THROW_ON_ERROR),
            'detected_at' => $now->format('Y-m-d H:i:s'),
            'resolved_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /** §10.5: every update is `SET col = col + ?`, never a read-modify-write in PHP. */
    private function moveBalance(Connection $db, int $instructorId, array $deltas, DateTimeImmutable $now): void
    {
        $db->table('instructor_balances')
            ->where('instructor_id', $instructorId)
            ->incrementEach($deltas, ['updated_at' => $now->format('Y-m-d H:i:s')]);
    }

    /**
     * One transaction, §9.4's lock order: balance first, then the payout, then
     * the attempt re-read under both.
     *
     * @template T
     *
     * @param  Closure(Connection, object, object): T  $fn
     * @return T|null
     */
    private function forAttempt(int $attemptId, Closure $fn): mixed
    {
        $db = $this->db();

        // §9.3: the attempt's payout_id is frozen at insert and never edited; it
        // is read unlocked only to name the rows to lock.
        $payoutId = $db->table('payout_attempts')->where('id', $attemptId)->value('payout_id');

        if ($payoutId === null) {
            return null;
        }

        return $this->forPayout((int) $payoutId, function (Connection $db, object $payout) use ($attemptId, $fn): mixed {
            $attempt = $db->table('payout_attempts')->where('id', $attemptId)->first();

            if ($attempt === null) {
                return null;
            }

            return $fn($db, $attempt, $payout);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(Connection, object): T  $fn
     * @return T|null
     */
    private function forPayout(int $payoutId, Closure $fn): mixed
    {
        $db = $this->db();

        // §9.2: reads and writes only inside the closure. DB::transaction
        // retries on deadlock, so a dispatch in here would fire twice.
        return $db->transaction(function () use ($db, $payoutId, $fn): mixed {
            $instructorId = $db->table('payouts')->where('id', $payoutId)->value('instructor_id');

            if ($instructorId === null) {
                return null;
            }

            // §9.4: the serialisation point, first.
            $balance = $db->table('instructor_balances')
                ->where('instructor_id', $instructorId)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                return null;
            }

            $payout = $db->table('payouts')->where('id', $payoutId)->lockForUpdate()->first();

            if ($payout === null) {
                return null;
            }

            return $fn($db, $payout);
        });
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
