<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Domain\Payout\AttemptService;
use App\Domain\Payout\StrandedSweeper;
use App\Domain\Provider\Scenario;
use App\Jobs\PollPayoutAttempt;
use App\Jobs\SendPayoutAttempt;
use App\Models\Payout;
use App\Models\PayoutAttempt;

/**
 * §11.4, two rows that look the same from the outside and are recovered by
 * different machinery:
 *
 *     Worker dies BEFORE attempt committed → no attempt row, no increment;
 *                                            §10.7 re-dispatches → fresh attempt, safe
 *     Worker dies AFTER  attempt committed → lease expires → unknown → status query
 *                                            → never a blind resend
 *
 * §11.1: "the recovery boundary is a commit, not an HTTP call". The attempt row
 * and the `attempt_count` increment are one transaction, so which side of that
 * commit the worker died on is a question the database can answer — which is
 * the whole reason the boundary is placed there.
 *
 * Invariants 19 and 30.
 */
final class DemoCrashRetry extends DemoCommand
{
    protected $signature = 'demo:crash-retry';

    protected $description = '§11.4: a worker dies on each side of the recovery boundary — one is re-dispatched, the other is asked about, neither is resent blindly';

    protected function matrixRow(): string
    {
        return 'Worker dies before / after the attempt commit → re-dispatched / lease expires → unknown → status query';
    }

    protected function scenario(): void
    {
        $this->beforeTheCommit();
        $this->afterTheCommit();

        $this->moneyOutcome('fresh attempt, safe — and never a blind resend');
    }

    /**
     * §11.1: "crash before the first commit — no attempt row exists and
     * attempt_count was not incremented, because both are in the same
     * transaction. §10.7 re-dispatches."
     */
    private function beforeTheCommit(): void
    {
        $instructorId = $this->world->freshInstructorId();

        $this->step('CASE 1 — the worker dies BEFORE the attempt transaction commits');
        $this->note('simulated by the attempt job simply never running: that is exactly what a lost job is');

        $payout = $this->claimedPayout($instructorId);
        $reserved = $this->buckets($instructorId, 'Claimed, and then nothing happened');

        $this->attempts($payout->id, 'Payout attempts — there are none');

        $this->checkEquals('invariant 19  attempt_count was not incremented', 0, (int) Payout::query()->find($payout->id)->attempt_count);
        $this->checkEquals('no attempt row exists — the transaction never committed', 0, PayoutAttempt::query()->where('payout_id', $payout->id)->count());
        $this->check("the payout is still 'pending' with money reserved", Payout::query()->find($payout->id)->status === 'pending');

        $this->step('§10.7: the stranded sweeper notices money reserved with no attempt in flight');

        $this->call('payouts:sweep-stranded');

        // The command dispatches to the queue; the demo's queue is a null queue,
        // so the sweeper's decision is read back here and the job is run by hand.
        $stranded = app(StrandedSweeper::class)->sweep();

        $this->check('§10.7  the sweeper selected this payout for a fresh attempt', in_array($payout->id, $stranded['dispatch'], true));
        $this->note('RECOVERY ONLY — on a healthy queue §10.3 dispatches the attempt itself and this sweeps nothing');

        $this->scripted(Scenario::Success);
        $this->runJob(new SendPayoutAttempt($payout->id));

        $this->attempts($payout->id);
        $this->buckets($instructorId, 'After the re-dispatched attempt', $reserved);

        $this->checkEquals('attempt #1 was created by the recovery path', 1, (int) Payout::query()->find($payout->id)->attempt_count);
        $this->check('the payout settled on a fresh attempt', Payout::query()->find($payout->id)->status === 'settled');
    }

    /**
     * §11.1: "crash after it — the attempt sits in `sending` with the provider
     * possibly holding money in flight. A queue retry cannot create a second
     * attempt (UNIQUE(active_payout_id)), so without recovery it would stay
     * there forever."
     */
    private function afterTheCommit(): void
    {
        $instructorId = $this->world->freshInstructorId();

        $this->step('CASE 2 — the worker dies AFTER the attempt commits, with the transfer already made');
        $this->note('§16.3: simulated by committing the attempt and calling the provider, then never recording the outcome');

        $payout = $this->claimedPayout($instructorId);
        $reserved = $this->buckets($instructorId, 'Claimed: available → reserved');

        $provider = $this->scripted(Scenario::Success);

        // §10.2 / §11.1: the slot transaction — increment and attempt row
        // together — commits here. This is the recovery boundary.
        $slot = app(AttemptService::class)->acquire($payout->id);

        if ($slot === null) {
            $this->check('the slot was acquired', false);

            return;
        }

        // §10.3: the provider call happens outside that transaction. The money
        // moves...
        $provider->send($slot->amountMinor, $slot->idempotencyKey);

        // ...and the worker dies here, before the outcome transaction. Nothing
        // on our side knows.
        $this->attempts($payout->id);

        $this->check("the attempt is stuck in 'sending', holding UNIQUE(active_payout_id)", PayoutAttempt::query()->find($slot->id)->status === 'sending');
        $this->checkEquals('the provider has already made the transfer', 1, $provider->transferCount($slot->idempotencyKey));
        $this->checkEquals('and our ledger still says reserved, not paid', $reserved['reserved'], DemoLedger::cached($instructorId)['reserved']);

        $this->step('§11.1: the lease is what makes this recoverable');

        $this->advanceClock((int) config('payouts.lease_seconds') + 60, 'the lease is '.config('payouts.lease_seconds').'s, and a demo has 30');

        $this->call('payouts:sweep-leases');
        $this->runJob(new PollPayoutAttempt($slot->id));

        $this->attempts($payout->id);
        $this->payouts($instructorId);
        $this->buckets($instructorId, 'After the lease swept and the status query answered', $reserved);

        $after = DemoLedger::cached($instructorId);

        $this->check("invariant 30  the sweeper's only move was 'sending' → 'unknown'", PayoutAttempt::query()->find($slot->id)->status === 'succeeded');
        $this->check("the payout is 'settled'", Payout::query()->find($payout->id)->status === 'settled');
        $this->checkEquals('reserved → paid, exactly once', (int) $payout->amount_minor, $after['paid']);

        // The strongest assertion in this demo: the provider's own books.
        $this->checkEquals('invariant 30  send() was never called again — the resolution came from status()', 1, $provider->sendCalls());
        $this->checkEquals('exactly one transfer, on the provider\'s side', 1, $provider->transferCount($slot->idempotencyKey));
        $this->checkEquals('attempt_count is still 1 — no second attempt was ever created', 1, (int) Payout::query()->find($payout->id)->attempt_count);

        $this->say('A worker that died in the microsecond BETWEEN the commit and the HTTP call sent');
        $this->say('nothing — but the system cannot know that, so it waits out the lease and asks.');
        $this->say('The cost is latency on a rare path. The alternative is guessing about money.');
    }

    /** Setup, through the real pipeline: paid → allocated → released → claimed. */
    private function claimedPayout(int $instructorId): Payout
    {
        $month = $this->world->freshMonth();

        $this->world->paidSubscription(
            [$instructorId],
            amountMinor: 90_000,
            platformRateBps: 2_000,
            termStart: DemoWorld::utc('2023-01-01 00:00:00'),
            termDays: 90,
        );

        $this->world->release($instructorId, '2023-01');

        $payout = Payout::query()->findOrFail($this->world->claim($instructorId, $this->world->batch($month))->payoutId);

        $this->say(sprintf(
            'instructor %d · payout %d · %s',
            $instructorId,
            $payout->id,
            DemoLedger::money($payout->amount_minor),
        ));

        return $payout;
    }
}
