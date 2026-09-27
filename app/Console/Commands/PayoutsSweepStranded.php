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
 * RECOVERY ONLY. The ordinary road out of the claim is §10.3's:
 * ClaimInstructorPayout commits the claim and then dispatches the attempt
 * itself. What reaches this command is what that path lost — the
 * attempt-creation job the queue dropped, the worker that died before the
 * §10.2 transaction, the retry dispatch that never landed — so there is no case
 * where a reservation sits with nothing coming for it.
 *
 * The distinction is not cosmetic. If this were the normal path, every payout
 * would wait a sweeper tick before any provider call, and the recovery path
 * would never be exercised AS a recovery path: it would always find work, so a
 * bug in it would look like ordinary throughput rather than a missing dispatch.
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
