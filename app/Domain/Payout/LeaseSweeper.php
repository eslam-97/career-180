<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * §11.1: the lease sweeper.
 *
 *     UPDATE payout_attempts
 *        SET status = 'unknown'
 *      WHERE status = 'sending'
 *        AND lease_expires_at < NOW();
 *
 * "The sweeper's ONLY legal transition is sending -> unknown. It never marks an
 * attempt failed and never resends." From unknown the normal status-polling path
 * applies, and because unknown is still non-terminal, active_payout_id keeps a
 * second attempt from being created underneath it (invariant 30).
 *
 * Deliberately conservative: a worker that died in the microsecond between the
 * commit and the HTTP call sent nothing, but the system cannot know that, so it
 * waits out the lease and asks the provider rather than assuming.
 *
 * The single statement above is split per attempt here, because §9.4 requires
 * the instructor's balance row to be held before writing that instructor's
 * attempts and one statement cannot hold a lock per row it touches. The
 * candidate read takes no lock — it is a batch-level read that only decides
 * which attempts to act on, and every decision is re-made under the locks.
 */
final class LeaseSweeper
{
    /** §16.2: the connection seam, as on every other service in this namespace. */
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * @return list<int> the attempts that moved to 'unknown'. The caller polls
     *                   them — dispatching from in here would put a side effect
     *                   inside a transaction that retries on deadlock (§9.2).
     */
    public function sweep(): array
    {
        $db = $this->db();
        $now = $this->now();

        $candidates = $db->table('payout_attempts')
            ->where('status', 'sending')
            ->where('lease_expires_at', '<', $now->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->pluck('id');

        $swept = [];

        foreach ($candidates as $attemptId) {
            if ($this->expire((int) $attemptId, $now)) {
                $swept[] = (int) $attemptId;
            }
        }

        return $swept;
    }

    /** One attempt, one transaction, §9.4's lock order. */
    private function expire(int $attemptId, DateTimeImmutable $now): bool
    {
        $db = $this->db();

        return (bool) $db->transaction(function () use ($db, $attemptId, $now): bool {
            // §9.3: payout_id and instructor_id are frozen at insert; they are
            // read unlocked only to name the rows to lock.
            $payoutId = $db->table('payout_attempts')->where('id', $attemptId)->value('payout_id');

            if ($payoutId === null) {
                return false;
            }

            $instructorId = $db->table('payouts')->where('id', $payoutId)->value('instructor_id');

            if ($instructorId === null) {
                return false;
            }

            // §9.4: the serialisation point, before the attempt is touched.
            $balance = $db->table('instructor_balances')
                ->where('instructor_id', $instructorId)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                return false;
            }

            $db->table('payouts')->where('id', $payoutId)->lockForUpdate()->first();

            // §11.1: the whole sweeper, in one conditional statement. The
            // predicate is re-evaluated under the locks, so an attempt that
            // resolved between the candidate read and here is left alone. There
            // is no branch that can write 'failed' and none that can resend.
            return $db->table('payout_attempts')
                ->where('id', $attemptId)
                ->where('status', 'sending')
                ->where('lease_expires_at', '<', $now->format('Y-m-d H:i:s'))
                ->update([
                    'status' => 'unknown',
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]) === 1;
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
