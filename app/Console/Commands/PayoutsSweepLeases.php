<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payout\LeaseSweeper;
use App\Jobs\PollPayoutAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * §11.1: attempts whose lease has expired become 'unknown', and are then asked
 * about. The sweeper's only legal transition is sending -> unknown: it never
 * marks an attempt failed and never resends (invariant 30).
 */
final class PayoutsSweepLeases extends Command
{
    protected $signature = 'payouts:sweep-leases';

    protected $description = 'Move attempts whose lease has expired from sending to unknown, and poll them by key';

    public function handle(LeaseSweeper $sweeper): int
    {
        // §9.1: the Redis lock is an optimisation — it stops two overlapping
        // runs queueing the same polls. It is not what makes this safe. The
        // conditional UPDATE under the balance lock is (§9.3, §9.4).
        $lock = Cache::lock('payouts:sweep-leases', 300);

        if (! $lock->get()) {
            $this->info('Another lease sweep is already in flight; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $swept = $sweeper->sweep();

            foreach ($swept as $attemptId) {
                // §11: the lease has already expired, so the question is asked
                // now rather than on a backoff. §9.2: afterCommit(), always.
                PollPayoutAttempt::dispatch($attemptId)->afterCommit();
            }

            $this->info(sprintf('Swept %d expired lease(s) to unknown.', count($swept)));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
