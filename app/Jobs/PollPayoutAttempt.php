<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payout\SettlementService;
use App\Domain\Provider\OutcomeStatus;
use App\Domain\Provider\PaymentProvider;
use App\Models\PayoutAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * §11: the status query. "The only way out is to ASK, using the same reference
 * we sent."
 *
 *     unknown    -> { succeeded | failed | unresolved }   via status query only
 *     unresolved -> { succeeded | failed }                via late poll or human evidence
 *
 * This job never sends anything. It is the second of §11.3's two required
 * layers: the idempotency key lets the provider deduplicate a replayed request,
 * and this query covers the case where we never learn the first attempt's
 * outcome. A blind retry in place of this query is the double payment.
 */
final class PollPayoutAttempt implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesPayoutAttempts;
    use SerializesModels;

    /** §11: the two states a status query is asked from. */
    private const POLLABLE = ['unknown', 'unresolved'];

    public function __construct(public readonly int $attemptId) {}

    public function handle(SettlementService $settlement, PaymentProvider $provider): void
    {
        $attempt = PayoutAttempt::query()->find($this->attemptId);

        if ($attempt === null) {
            return;
        }

        // Already definitive. A queued poll that lands after the answer arrived
        // is a no-op, not a second opinion.
        if (! in_array($attempt->status, self::POLLABLE, true)) {
            return;
        }

        // §11.3: the SAME key that was sent. A poll that derived a new key would
        // be asking about a transfer nobody made (invariant 30).
        $outcome = $provider->status($attempt->idempotency_key);

        if ($outcome->status === OutcomeStatus::Success) {
            // §11.2: recorded from 'unknown' AND from 'unresolved'. An answer a
            // day later is still authoritative — and if the payout was already
            // failed, §10.6 moves no money and raises the alert (invariant 22).
            $settlement->recordSuccess($attempt->id, $outcome);

            return;
        }

        if ($outcome->status === OutcomeStatus::Failure) {
            $this->afterDefinitiveFailure($settlement, $attempt->id, (int) $attempt->payout_id, $outcome);

            return;
        }

        // Still "I cannot tell you". Count the poll and wait longer.
        $polls = $settlement->recordPoll($attempt->id);

        // §11.2: an attempt that already gave up on a schedule is polled at a
        // low rate indefinitely by payouts:poll-open, so a contradicting success
        // is detected rather than lost. There is no further state to move it to.
        if ($attempt->status === 'unresolved') {
            return;
        }

        if ($polls >= (int) config('payouts.max_polls')) {
            // §11.2: "when polling exhausts its backoff the attempt becomes
            // unresolved and the payout moves to needs_review — money still
            // claimed, a human alerted, never auto-released".
            $settlement->markUnresolved($attempt->id);

            return;
        }

        PollPayoutAttempt::dispatch($attempt->id)
            ->delay(now()->addSeconds($this->pollDelaySeconds($polls)))
            ->afterCommit();
    }
}
