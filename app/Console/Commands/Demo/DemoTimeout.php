<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Domain\Provider\Scenario;
use App\Domain\Provider\ScriptedProvider;
use App\Jobs\PollPayoutAttempt;
use App\Jobs\SendPayoutAttempt;
use App\Models\Payout;
use App\Models\PayoutAttempt;

/**
 * §11.4: "Provider timeout → `unknown`, claim held, polled by key → never a
 * blind retry", and the row below it, "Success then delayed confirmation →
 * poll resolves `unknown → succeeded` → no second transfer".
 *
 * The brief's hardest case, and the reason the fake provider keeps durable
 * state (§16.3): the transfer IS made and THEN the call throws. Throwing
 * without recording a transfer would test our error handling; this tests the
 * money-already-moved case.
 *
 * Invariants 30 and 31.
 */
final class DemoTimeout extends DemoCommand
{
    protected $signature = 'demo:timeout';

    protected $description = '§11.4: the provider times out after the money moved — the attempt goes unknown, is polled by key, and no second transfer happens';

    protected function matrixRow(): string
    {
        return 'Provider timeout → unknown, claim held, polled by key → never a blind retry';
    }

    protected function scenario(): void
    {
        $instructorId = $this->world->freshInstructorId();
        $payout = $this->claimedPayout($instructorId);

        $reserved = $this->buckets($instructorId, 'Claimed: available → reserved');

        $provider = $this->theTimeout($payout);
        $key = $this->afterTheTimeout($instructorId, $payout, $reserved, $provider);

        $this->thePoll($instructorId, $payout, $reserved, $key, $provider);

        $this->moneyOutcome('one transfer, balance correct — the money moved once and the ledger agrees');
    }

    /** Setup, through the real pipeline: paid → allocated → released → claimed. */
    private function claimedPayout(int $instructorId): Payout
    {
        $month = $this->world->freshMonth();

        $this->step('Setup: one 90-day subscription at 900.00, released for its first month, then claimed');

        $this->world->paidSubscription(
            [$instructorId],
            amountMinor: 90_000,
            platformRateBps: 2_000,
            termStart: DemoWorld::utc('2023-01-01 00:00:00'),
            termDays: 90,
        );

        // §6: released(t) = floor(72,000 × 30 / 90) = 24,000 at the first
        // month's horizon. Recognition is progressive; the payout pays what has
        // been recognized, not what was allocated.
        $this->world->release($instructorId, '2023-01');

        $result = $this->world->claim($instructorId, $this->world->batch($month));

        $payout = Payout::query()->findOrFail($result->payoutId);

        $this->say(sprintf(
            'payout %d · %s · status %s — the entries are stamped and the money is reserved',
            $payout->id,
            DemoLedger::money($payout->amount_minor),
            $payout->status,
        ));

        return $payout;
    }

    /** §16.3: the transfer is recorded FIRST and the call throws second. */
    private function theTimeout(Payout $payout): ScriptedProvider
    {
        $this->step('The provider times out — after it has already made the transfer');

        $provider = $this->scripted(Scenario::TimeoutAfterSuccess);

        $this->note('§10.3: the claim committed first. No database lock is ever held across the call.');
        $this->runJob(new SendPayoutAttempt($payout->id));

        return $provider;
    }

    /**
     * The state the system is left in: uncertainty with a name, and the money
     * still claimed.
     */
    private function afterTheTimeout(
        int $instructorId,
        Payout $payout,
        array $reserved,
        ScriptedProvider $provider,
    ): string {
        $attempt = PayoutAttempt::query()->where('payout_id', $payout->id)->firstOrFail();

        $this->attempts($payout->id);
        $this->payouts($instructorId);
        $this->buckets($instructorId, 'After the timeout — nothing moved', $reserved);

        $this->check("the attempt is 'unknown', not 'failed'", $attempt->status === 'unknown');
        $this->check("the payout is 'in_progress' — the claim is held", Payout::query()->find($payout->id)->status === 'in_progress');
        $this->checkEquals('the entries are still reserved, not returned to available', $reserved['reserved'], DemoLedger::cached($instructorId)['reserved']);
        $this->checkEquals('the provider made exactly one transfer', 1, $provider->transferCount($attempt->idempotency_key));

        $this->say('Recording this as `failed` is the double-payment bug §11 opens with: the entries');
        $this->say('would return to available, the next batch would claim them, and the provider is');
        $this->say('holding a completed transfer nobody knows about.');

        return $attempt->idempotency_key;
    }

    /** §11.3: the status query, by the SAME key. The second of the two required layers. */
    private function thePoll(
        int $instructorId,
        Payout $payout,
        array $reserved,
        string $key,
        ScriptedProvider $provider,
    ): void {
        $this->step('The only way out is to ASK — using the same key we sent');

        $attemptId = (int) PayoutAttempt::query()->where('payout_id', $payout->id)->value('id');

        $this->runJob(new PollPayoutAttempt($attemptId));

        $this->attempts($payout->id);
        $this->payouts($instructorId);
        $this->buckets($instructorId, 'After the poll — reserved → paid', $reserved);

        $settled = Payout::query()->findOrFail($payout->id);
        $after = DemoLedger::cached($instructorId);

        $this->check("the attempt resolved 'unknown' → 'succeeded' by status query", PayoutAttempt::query()->find($attemptId)->status === 'succeeded');
        $this->check("the payout is 'settled'", $settled->status === 'settled');
        $this->checkEquals('reserved is now zero', 0, $after['reserved']);
        $this->checkEquals('paid is the payout amount', (int) $payout->amount_minor, $after['paid']);

        // Invariant 31, asserted against the PROVIDER's own books rather than
        // our attempt rows — which is the only way this test is not vacuous.
        $this->checkEquals('invariant 31  exactly one transfer, on the provider\'s side', 1, $provider->transferCount($key));
        $this->checkEquals('invariant 30  send() was called once; the resolution came from status()', 1, $provider->sendCalls());
        $this->check('§11.3  the status query used the same idempotency key that was sent', $provider->statusKeys() === [$key]);
    }
}
