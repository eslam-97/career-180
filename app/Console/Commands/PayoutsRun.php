<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payout\BatchService;
use App\Jobs\ClaimInstructorPayout;
use App\Models\InstructorBalance;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * §10.1 / §10.3: open the batch for a payout period and fan the claim out, one
 * job per instructor.
 *
 * §6.4: recognition is POSTED monthly, so the payout period is a month and this
 * command closes the one that has just been released.
 *
 * Running it twice for the same period is a supported, expected operation: the
 * batch is opened get-or-create (§10.1), so the second run reuses the same
 * frozen cutoff_at and max_entry_id rather than opening a second snapshot. That
 * is both invariant 25 and the recovery path when a claim job is lost.
 */
final class PayoutsRun extends Command
{
    protected $signature = 'payouts:run {--month= : Payout period as YYYY-MM, defaulting to last month}';

    protected $description = 'Open the payout batch for one period and claim every eligible instructor ledger entry into a payout';

    public function handle(BatchService $batches): int
    {
        try {
            $periodStart = $this->periodStart();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // §9.1: the Redis lock is an optimisation — it stops two overlapping
        // runs queueing the same work twice. It is not what makes the command
        // safe. The balance lock (§9.4) and UNIQUE(batch_id, instructor_id)
        // are, and both live inside a transaction where a lock cannot expire
        // halfway through.
        $lock = Cache::lock('payouts:run', 300);

        if (! $lock->get()) {
            $this->info('Another payout run is already in flight; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $batch = $batches->openFor($periodStart, $periodStart->modify('last day of this month'));

            $dispatched = 0;

            InstructorBalance::query()
                // §10.4: "the command dispatches jobs only for instructors with
                // available_minor > 0". Optimisation, not correctness — the
                // transaction is the guarantee, and this only avoids opening
                // one per instructor who has nothing to claim.
                ->where('available_minor', '>', 0)
                ->orderBy('instructor_id')
                // §17: chunked rather than loaded whole, and one job per
                // instructor so no worker ever holds two locks at once (§9.4).
                ->chunkById(500, function (Collection $balances) use ($batch, &$dispatched): void {
                    foreach ($balances as $balance) {
                        // §9.2: afterCommit() unconditionally. This command
                        // holds no transaction of its own, but the rule does
                        // not depend on that and a caller wrapping this one
                        // must not be able to break it.
                        ClaimInstructorPayout::dispatch(
                            $balance->instructor_id,
                            $batch->id,
                        )->afterCommit();

                        $dispatched++;
                    }
                }, 'instructor_id');

            $this->info(sprintf(
                'Batch %d (%s, cutoff %s, max entry %d): dispatched %d claim(s).',
                $batch->id,
                $periodStart->format('Y-m'),
                $batch->cutoff_at->format('Y-m-d H:i:s'),
                $batch->max_entry_id,
                $dispatched,
            ));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * §3.3: UTC, and midnight-aligned. The period is a whole month, so only its
     * first day is chosen here and the last is derived from it.
     */
    private function periodStart(): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $month = $this->option('month');

        if ($month === null) {
            // Scheduled after the release run on the 1st, so the period being
            // paid is last month.
            //
            // Through Carbon rather than `new DateTimeImmutable('now')`: the
            // app's clock is the one that can be frozen, and a command that
            // reads the wall clock directly is a command no test can pin down.
            return new DateTimeImmutable(
                now()->utc()->startOfMonth()->subMonth()->startOfDay()->format('Y-m-d H:i:s'),
                $utc,
            );
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new InvalidArgumentException("Expected --month as YYYY-MM, got '{$month}'.");
        }

        $first = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $utc);

        // createFromFormat does not reject an impossible month, it rolls it
        // over: '2026-13' becomes January 2027 and would open a batch for a
        // period nobody asked for. The round-trip is what actually catches it.
        if ($first === false || $first->format('Y-m') !== $month) {
            throw new InvalidArgumentException("'{$month}' is not a real month.");
        }

        return $first;
    }
}
