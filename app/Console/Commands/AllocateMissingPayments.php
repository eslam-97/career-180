<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AllocatePaymentRevenue;
use App\Models\SubscriptionPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * §5.3: the instructor set is frozen onto the payment so "a lost job can be
 * re-dispatched from the database alone". This is the thing that re-dispatches
 * it. Without it that sentence describes a capability nobody exercises.
 *
 * The allocation-side mirror of §10.7, and safe for the same reason: the
 * predicate is an explicit status condition rather than a negated one, and the
 * guard that makes a re-dispatch harmless is a constraint —
 * UNIQUE(payment_id, instructor_id) — not a lock that can expire mid-operation.
 */
final class AllocateMissingPayments extends Command
{
    protected $signature = 'payments:allocate-missing';

    protected $description = 'Re-dispatch allocation for confirmed payments whose allocation job never landed';

    public function handle(): int
    {
        // §9.1: the Redis lock is an optimisation — it stops two overlapping
        // runs queueing the same work twice. It is not what makes the command
        // safe; the unique key is. A lock that expires mid-run changes nothing
        // about the outcome, only about how much wasted work happens.
        $lock = Cache::lock('payments:allocate-missing', 300);

        if (! $lock->get()) {
            $this->info('Another sweep is already running; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $dispatched = 0;

            SubscriptionPayment::query()
                // §5.1: confirmed only. Dispatching for an unconfirmed payment
                // would queue a job AllocationService refuses every time, since
                // there is no money to allocate until the provider says so.
                ->confirmed()
                // The stranded set: money in, nothing split. A payment mid-flight
                // in another worker is picked up too, and that is fine — the
                // duplicate job loses to the unique key and becomes a no-op.
                ->whereDoesntHave('allocations')
                ->orderBy('id')
                // §17: the ledger is unbounded, so the sweep is chunked rather
                // than loading every stranded payment into memory at once.
                ->chunkById(500, function (Collection $payments) use (&$dispatched): void {
                    foreach ($payments as $payment) {
                        // §9.2: afterCommit() even here. The command holds no
                        // transaction of its own, but the rule is unconditional
                        // and a caller wrapping this one must not break it.
                        AllocatePaymentRevenue::dispatch($payment->id)->afterCommit();

                        $dispatched++;
                    }
                });

            $this->info("Re-dispatched allocation for {$dispatched} stranded payment(s).");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
