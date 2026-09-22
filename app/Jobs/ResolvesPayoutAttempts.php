<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payout\AttemptService;
use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Outcome;
use Illuminate\Support\Facades\DB;

/**
 * The two steps both payout jobs take after the provider has answered, kept in
 * one place so the send path and the poll path cannot drift apart. Neither of
 * them moves money — that is SettlementService's, in one transaction each
 * (§10.6).
 */
trait ResolvesPayoutAttempts
{
    /**
     * §11.4: "provider permanent failure -> new attempt, new key, entries stay
     * claimed". The attempt is resolved first, then either a fresh attempt is
     * queued or, at the ceiling, the payout itself is failed through §10.6 —
     * with that transaction's preconditions, so a live or succeeded attempt
     * still stops it (invariant 20).
     */
    private function afterDefinitiveFailure(
        SettlementService $settlement,
        int $attemptId,
        int $payoutId,
        Outcome $outcome,
    ): void {
        $settlement->recordFailure($attemptId, $outcome);

        $attemptCount = (int) DB::table('payouts')->where('id', $payoutId)->value('attempt_count');

        if ($attemptCount < AttemptService::ceiling()) {
            // §9.2: afterCommit() unconditionally — a worker must not pick this
            // up before the failure it follows is visible.
            SendPayoutAttempt::dispatch($payoutId)->afterCommit();

            return;
        }

        $settlement->failPayout($payoutId);
    }

    /**
     * §11.2: the backoff. The last entry is reused if max_polls is ever raised
     * past the list, so the poller degrades to a slow poll rather than to none.
     */
    private function pollDelaySeconds(int $pollsSoFar): int
    {
        /** @var list<int> $backoff */
        $backoff = config('payouts.poll_backoff_seconds');

        return $backoff[$pollsSoFar] ?? $backoff[array_key_last($backoff)];
    }
}
