<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollPayoutAttempt;
use App\Models\PayoutAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * §11.2: "low-rate polling continues indefinitely afterwards so a contradicting
 * success is detected rather than lost".
 *
 * Two open states, and both matter:
 *
 *  - `unknown` — the poll chain exists, but a dropped job would otherwise leave
 *    the attempt unasked-about forever. This is the backstop for that.
 *  - `unresolved` — we gave up on a schedule, not on the answer. When a human
 *    resolves such a payout as failed the payout becomes 'failed' while the
 *    attempt stays 'unresolved' with its evidence (§11.2), so this command keeps
 *    asking — and a late success then lands on §10.6's alert path rather than
 *    being silently dropped (invariant 22).
 */
final class PayoutsPollOpen extends Command
{
    protected $signature = 'payouts:poll-open';

    protected $description = 'Ask the provider again about every attempt still unknown or unresolved';

    public function handle(): int
    {
        // §9.1: an optimisation. Polling twice is harmless — the status query
        // sends nothing and the outcome predicate is conditional (§11.1).
        $lock = Cache::lock('payouts:poll-open', 300);

        if (! $lock->get()) {
            $this->info('Another open-attempt poll is already in flight; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $polled = 0;

            PayoutAttempt::query()
                ->whereIn('status', ['unknown', 'unresolved'])
                ->orderBy('id')
                // §17: chunked rather than loaded whole.
                ->chunkById(500, function (Collection $attempts) use (&$polled): void {
                    foreach ($attempts as $attempt) {
                        // §9.2: afterCommit() unconditionally.
                        PollPayoutAttempt::dispatch($attempt->id)->afterCommit();

                        $polled++;
                    }
                });

            $this->info(sprintf('Queued %d status quer%s.', $polled, $polled === 1 ? 'y' : 'ies'));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
