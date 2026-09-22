<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payout\AttemptService;
use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Outcome;
use App\Domain\Provider\OutcomeStatus;
use App\Domain\Provider\PaymentProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * §10.2 / §10.3: acquire the slot, then — outside the transaction — call the
 * provider.
 *
 *     TRANSACTION
 *         acquire slot, insert attempt (sending, lease_expires_at = now + lease)
 *     COMMIT                                     <-  §11.1's recovery boundary
 *         call provider
 *     TRANSACTION
 *         record outcome  WHERE status IN ('sending', 'unknown', 'unresolved')
 *     COMMIT
 *
 * A database lock is never held across the network call (§10.3), and a crash on
 * either side of the boundary is recoverable: before it, no attempt row and no
 * increment, so §10.7 re-dispatches; after it, the lease expires and §11.1's
 * sweeper moves the attempt to 'unknown' to be polled — never resent.
 *
 * Deliberately NOT ShouldBeUnique, for the reason §9.1 gives: a Redis lock is an
 * optimisation and can expire mid-operation. The guarantees here are the §10.2
 * gate, UNIQUE(active_payout_id) and UNIQUE(idempotency_key).
 */
final class SendPayoutAttempt implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesPayoutAttempts;
    use SerializesModels;

    public function __construct(public readonly int $payoutId) {}

    public function handle(
        AttemptService $attempts,
        SettlementService $settlement,
        PaymentProvider $provider,
    ): void {
        $slot = $attempts->acquire($this->payoutId);

        // §10.2: "the loser exits". Also the path for a payout that is settled,
        // failed, needs_review (§10.7, invariant 24) or at the ceiling — the
        // gate refuses, and nothing here may second-guess it.
        if ($slot === null) {
            return;
        }

        try {
            $outcome = $provider->send($slot->amountMinor, $slot->idempotencyKey);
        } catch (Throwable $e) {
            // §11: "a timeout is not a failure, and neither is a crash after
            // dispatch". The money may well have moved (§16.3), so the attempt
            // becomes 'unknown' and the only way out is to ask the provider,
            // using the same key. Recording 'failed' here is the double-payment
            // bug in §11's opening paragraph.
            $settlement->recordUnknown($slot->id, Outcome::unknown([
                'error' => $e->getMessage(),
            ]));

            $this->poll($slot->id);

            return;
        }

        match ($outcome->status) {
            OutcomeStatus::Success => $settlement->recordSuccess($slot->id, $outcome),
            OutcomeStatus::Failure => $this->afterDefinitiveFailure($settlement, $slot->id, $this->payoutId, $outcome),
            // The provider answered, and its answer was "I cannot tell you".
            // Identical handling to a timeout: hold the claim, poll by key.
            OutcomeStatus::Unknown => $this->unknown($settlement, $slot->id, $outcome),
        };
    }

    private function unknown(SettlementService $settlement, int $attemptId, Outcome $outcome): void
    {
        $settlement->recordUnknown($attemptId, $outcome);

        $this->poll($attemptId);
    }

    private function poll(int $attemptId): void
    {
        // §9.2: afterCommit(), and dispatched from outside any transaction of
        // ours — a dispatch inside DB::transaction() would fire twice on a
        // deadlock retry (§9.2).
        PollPayoutAttempt::dispatch($attemptId)
            ->delay(now()->addSeconds($this->pollDelaySeconds(0)))
            ->afterCommit();
    }
}
