<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReleaseInstructorRecognition;
use App\Models\InstructorBalance;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * §6.4: recognition is computed daily but POSTED monthly, so the scheduled run
 * is monthly and its target is a posting period.
 *
 * §6.2: the target horizon T for the March run is 2026-03-31, which keys the row
 * on 'period:2026-03' and matches every worked example in the doc
 * (expected(Mar 31), expected(Apr 30)). The final day of a month is recognized
 * by the following run; the cumulative-delta formula means nothing is lost by
 * that, it is simply where the boundary sits.
 */
final class ReleaseRun extends Command
{
    protected $signature = 'release:run {--month= : Posting period as YYYY-MM, defaulting to last month}';

    protected $description = 'Post the scheduled recognition release for every instructor, for one posting period';

    public function handle(): int
    {
        try {
            $through = $this->targetHorizon();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // §9.1: the Redis lock is an optimisation — it stops two overlapping
        // runs queueing the same work twice. It is not what makes the command
        // safe. The watermark guard (§6.2, Hazard B) and the unique key are,
        // and both live inside the transaction where a lock cannot expire
        // halfway through.
        $lock = Cache::lock('release:run', 300);

        if (! $lock->get()) {
            $this->info('Another release run is already in flight; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $dispatched = 0;

            InstructorBalance::query()
                // §9.4: the balance row is the serialisation point, so the set
                // of instructors to release is exactly the set that has one.
                // Allocation creates it; an instructor with no balance row has
                // no allocations either.
                ->orderBy('instructor_id')
                // §17: chunked rather than loaded whole, and one job per
                // instructor so no worker ever holds two locks at once (§6.2).
                ->chunkById(500, function (Collection $balances) use ($through, &$dispatched): void {
                    foreach ($balances as $balance) {
                        // §9.2: afterCommit() unconditionally. This command
                        // holds no transaction of its own, but the rule does
                        // not depend on that and a caller wrapping this one
                        // must not be able to break it.
                        ReleaseInstructorRecognition::dispatch(
                            $balance->instructor_id,
                            $through->format('Y-m-d H:i:s'),
                        )->afterCommit();

                        $dispatched++;
                    }
                }, 'instructor_id');

            $this->info(sprintf(
                'Dispatched the %s release for %d instructor(s), horizon %s.',
                $through->format('Y-m'),
                $dispatched,
                $through->format('Y-m-d'),
            ));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * §3.3: UTC, and midnight-aligned so whole-day arithmetic in §6 has no
     * partial day to round away.
     */
    private function targetHorizon(): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $month = $this->option('month');

        if ($month === null) {
            // Scheduled on the 1st, so the period being closed is last month.
            //
            // Through Carbon rather than `new DateTimeImmutable('now')`: the
            // app's clock is the one that can be frozen, and a command that
            // reads the wall clock directly is a command no test can pin down.
            return new DateTimeImmutable(
                now()->utc()->startOfMonth()->subDay()->startOfDay()->format('Y-m-d H:i:s'),
                $utc,
            );
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new InvalidArgumentException("Expected --month as YYYY-MM, got '{$month}'.");
        }

        $first = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $utc);

        // createFromFormat does not reject an impossible month, it rolls it
        // over: '2026-13' becomes January 2027 and would post a period key
        // nobody asked for. The round-trip is what actually catches it.
        if ($first === false || $first->format('Y-m') !== $month) {
            throw new InvalidArgumentException("'{$month}' is not a real month.");
        }

        return $first->modify('last day of this month');
    }
}
