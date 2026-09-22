<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §10.1: a batch is a snapshot, and what makes it one is the pair of values
 * frozen at creation:
 *
 *     cutoff_at     business-time eligibility  (effective_at <= cutoff_at)
 *     max_entry_id  the high-water mark        (id           <= max_entry_id)
 *
 *     10:00   batch N created, max_entry_id = 5000, cutoff_at = 10:00
 *     10:05   late refund arrives:  id = 5001,  effective_at = 09:00
 *
 * Entry 5001 passes the date test and fails the id test, so replaying batch N
 * claims exactly what it claimed the first time. The entry is not lost — it
 * satisfies both predicates for batch N+1.
 */
final class BatchService
{
    // §9.1: ER_DUP_ENTRY. Two servers opening the same period; the loser reads
    // back the winner's row rather than creating a second snapshot.
    private const ERR_DUPLICATE = 1062;

    private const STATUS_OPEN = 'open';

    /**
     * §16.2: the connection name is a seam so a two-connection test can drive
     * this on a second real connection. Null is the configured default, which
     * is what production always uses.
     */
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * Get-or-create, keyed on the period by UNIQUE(period_start, period_end).
     *
     * An existing batch is returned **untouched**. Re-freezing cutoff_at or
     * max_entry_id on a replay would make the batch claim a different entry set
     * than it did the first time, which is precisely what §10.1 forbids. That
     * is also why this is the recovery path when a claim job is lost: running
     * the command again resumes the same snapshot instead of opening a new one.
     */
    public function openFor(DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): PayoutBatch
    {
        $db = $this->db();

        $existing = $this->find($periodStart, $periodEnd);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $db->table('payout_batches')->insert([
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
                // §10.1: the doc's worked example creates the batch *at* its
                // cutoff. Through the app's clock, never the wall clock, so a
                // test can pin it.
                'cutoff_at' => $this->now()->format('Y-m-d H:i:s'),
                // §10.1: the high-water mark, frozen here and never again.
                // No rows yet means zero, which the claim predicate reads as
                // "claim nothing" rather than "claim everything".
                'max_entry_id' => (int) $db->table('ledger_entries')->max('id'),
                // §14 never enumerates the batch statuses. This one is
                // informational: nothing reads it and nothing gates on it.
                'status' => self::STATUS_OPEN,
                'created_at' => $this->now()->format('Y-m-d H:i:s'),
                'updated_at' => $this->now()->format('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== self::ERR_DUPLICATE) {
                throw $e;
            }

            // Somebody opened this period between the read above and this
            // insert. Their snapshot is the snapshot; there is only ever one.
            //
            // Deliberately not insertOrIgnore: MySQL's INSERT IGNORE downgrades
            // strict-mode errors to warnings, and max_entry_id is a real value
            // this table must not receive silently clamped.
        }

        $batch = $this->find($periodStart, $periodEnd);

        if ($batch === null) {
            // Unreachable: the insert either succeeded or lost to a row that is
            // still there. Stated rather than assumed, because returning null
            // here would look like "no work to do" further up.
            throw new RuntimeException(sprintf(
                'Failed to open or read the payout batch for %s..%s.',
                $periodStart->format('Y-m-d'),
                $periodEnd->format('Y-m-d'),
            ));
        }

        return $batch;
    }

    private function find(DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): ?PayoutBatch
    {
        // Model::on(null) is the configured default connection, so this one
        // call covers both production and the two-connection tests.
        return PayoutBatch::on($this->connection)
            ->where('period_start', $periodStart->format('Y-m-d'))
            ->where('period_end', $periodEnd->format('Y-m-d'))
            ->first();
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
