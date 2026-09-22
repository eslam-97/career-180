<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payout\StrandedSweeper;
use App\Jobs\SendPayoutAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * §10.7: a payout in pending or in_progress with no active attempt and
 * attempt_count below the ceiling is re-dispatched; above the ceiling it is
 * failed via §10.6. needs_review is NEVER re-dispatched (invariant 24).
 *
 * This is also the normal road out of the claim (§10.3): a payout committed by
 * `payouts:run` is pending with no attempt, which is exactly the predicate
 * above. One state-driven path covers the ordinary case and the recovery case —
 * the attempt-creation job that the queue dropped, the worker that died before
 * the §10.2 transaction, the dispatch that never landed — so there is no case
 * where a reservation sits with nothing coming for it.
 */
final class PayoutsSweepStranded extends Command
{
    protected $signature = 'payouts:sweep-stranded';

    protected $description = 'Re-dispatch payouts with money reserved and no attempt in flight, and fail the ones past the ceiling';

    public function handle(StrandedSweeper $sweeper): int
    {
        // §9.1: an optimisation against two overlapping runs. The guarantee is
        // the §10.2 gate, which hands the slot to one worker and refuses the
        // rest, and UNIQUE(active_payout_id) behind it.
        $lock = Cache::lock('payouts:sweep-stranded', 300);

        if (! $lock->get()) {
            $this->info('Another stranded sweep is already in flight; nothing to do.');

            return self::SUCCESS;
        }

        try {
            ['dispatch' => $dispatch, 'failed' => $failed] = $sweeper->sweep();

            foreach ($dispatch as $payoutId) {
                // §9.2: afterCommit() unconditionally.
                SendPayoutAttempt::dispatch($payoutId)->afterCommit();
            }

            $this->info(sprintf(
                'Re-dispatched %d stranded payout(s); failed %d past the attempt ceiling.',
                count($dispatch),
                count($failed),
            ));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
