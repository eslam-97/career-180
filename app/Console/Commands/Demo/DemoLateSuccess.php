<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Outcome;
use App\Domain\Provider\Scenario;
use App\Domain\Provider\ScriptedProvider;
use App\Jobs\PollPayoutAttempt;
use App\Jobs\SendPayoutAttempt;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\ReconciliationAlert;

/**
 * §11.4: "Late success on an already-failed payout → no money moved; alert +
 * manual record → detected, not silent".
 *
 * §11.3 calls this "the one window that cannot be closed": a human resolves an
 * `unresolved` attempt as failed on outside evidence, the entries return to
 * `available`, and only then does the provider confirm the original transfer.
 * The entries may already belong to a different payout, so no automatic
 * correction is possible.
 *
 * The design narrows the window — §10.6's precondition prevents every
 * machine-driven version of it — and then says so in §19 rather than claiming
 * it solved. What this demo shows is the difference between a bug that is
 * detected and a bug that is silent. Invariant 22.
 */
final class DemoLateSuccess extends DemoCommand
{
    protected $signature = 'demo:late-success';

    protected $description = '§11.4: a provider confirms a transfer after a human already ruled the payout failed — no money moves, an alert is raised';

    protected function matrixRow(): string
    {
        return 'Late success on an already-failed payout → no money moved; alert + manual record → detected, not silent';
    }

    protected function scenario(): void
    {
        $instructorId = $this->world->freshInstructorId();
        $payout = $this->claimedPayout($instructorId);
        $reserved = $this->buckets($instructorId, 'Claimed: available → reserved');

        $provider = $this->silence($payout);
        $attempt = $this->pollUntilWeGiveUp($instructorId, $payout, $reserved);
        $returned = $this->theHumanRules($instructorId, $payout, $attempt, $reserved);

        $this->theAnswerArrivesAnyway($instructorId, $payout, $attempt, $returned, $provider);

        $this->moneyOutcome('detected, not silent — no money moved, and a human is paged with the evidence');
    }

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

        $this->world->release($instructorId, '2023-01');

        $payout = Payout::query()->findOrFail($this->world->claim($instructorId, $this->world->batch($month))->payoutId);

        $this->say(sprintf('payout %d · %s · status %s', $payout->id, DemoLedger::money($payout->amount_minor), $payout->status));

        return $payout;
    }

    /** The other half of §11's uncertainty: nothing recorded, and no answer either. */
    private function silence(Payout $payout): ScriptedProvider
    {
        $this->step('The provider goes silent — nothing recorded on its side, and no reply');

        $provider = $this->scripted(Scenario::TimeoutBeforeSend);

        $this->runJob(new SendPayoutAttempt($payout->id));

        $this->note("status() will answer UNKNOWN from here on — which is §11.2's road into 'unresolved'");

        return $provider;
    }

    /** §11.2: polling exhausts its backoff. That is US giving up, not the provider saying no. */
    private function pollUntilWeGiveUp(int $instructorId, Payout $payout, array $reserved): PayoutAttempt
    {
        $maxPolls = (int) config('payouts.max_polls');

        $this->step("We ask {$maxPolls} times, on a backoff, and never get an answer");

        $attemptId = (int) PayoutAttempt::query()->where('payout_id', $payout->id)->value('id');

        for ($i = 0; $i < $maxPolls; $i++) {
            // The poll chain normally re-dispatches itself on the backoff in
            // config('payouts.poll_backoff_seconds'). The null queue drops that
            // dispatch, so the demo runs the chain here — same jobs, no waiting.
            $this->runJob(new PollPayoutAttempt($attemptId));
        }

        $attempt = PayoutAttempt::query()->findOrFail($attemptId);

        $this->attempts($payout->id);
        $this->payouts($instructorId);
        $this->buckets($instructorId, 'Polling exhausted — still nothing moved', $reserved);

        $this->check("the attempt is 'unresolved' — not failed", $attempt->status === 'unresolved');
        $this->check("the payout is 'needs_review' — a human is alerted, never auto-released", Payout::query()->find($payout->id)->status === 'needs_review');
        $this->checkEquals('the money is still reserved', $reserved['reserved'], DemoLedger::cached($instructorId)['reserved']);
        $this->checkEquals("invariant 24  needs_review is excluded from the sweeper's status list", 0, $this->sweepWouldRedispatch($payout->id));

        return $attempt;
    }

    /** §10.7 / invariant 24: what `payouts:sweep-stranded` would pick this payout up as. */
    private function sweepWouldRedispatch(int $payoutId): int
    {
        $this->call('payouts:sweep-stranded');

        return Payout::query()->where('id', $payoutId)->where('status', 'needs_review')->count() === 1 ? 0 : 1;
    }

    /**
     * §11.2: "resolving unresolved → failed by human judgement is an assertion
     * about evidence OUTSIDE the system. It is permitted, it is recorded with
     * that evidence, and low-rate polling continues indefinitely afterwards."
     */
    private function theHumanRules(int $instructorId, Payout $payout, PayoutAttempt $attempt, array $reserved): array
    {
        $this->step('A human reads the provider\'s dashboard and rules the payout failed');

        app(SettlementService::class)->failPayout(
            $payout->id,
            'demo: operator checked the provider dashboard on '.now()->utc()->toDateString().', no transfer listed',
        );

        $returned = $this->buckets($instructorId, 'Failed: reserved → available', $reserved);

        $this->attempts($payout->id);
        $this->entries($instructorId);

        $this->check("the payout is 'failed'", Payout::query()->find($payout->id)->status === 'failed');
        $this->check("the attempt STAYS 'unresolved', with its evidence", PayoutAttempt::query()->find($attempt->id)->status === 'unresolved');
        $this->check('§11.2  the evidence is recorded on the attempt', PayoutAttempt::query()->find($attempt->id)->resolution_evidence !== null);
        $this->checkEquals('invariant 32  the entries returned to available', $reserved['reserved'], $returned['available']);
        $this->say('The attempt is not marked failed, because the provider never said so. It stays');
        $this->say('under watch: `payouts:poll-open` keeps asking, hourly, indefinitely.');

        return $returned;
    }

    /** Invariant 22: the answer that turns up a day late is still authoritative. */
    private function theAnswerArrivesAnyway(
        int $instructorId,
        Payout $payout,
        PayoutAttempt $attempt,
        array $returned,
        ScriptedProvider $provider,
    ): void {
        $this->step('A day later, the settlement file says the transfer DID go through');

        // §11.2: a definitive answer from a settlement file or a human reading
        // the provider's dashboard. It changes what the provider will say from
        // now on; it does not create a transfer that was not made.
        $provider->resolveLate($attempt->idempotency_key, Outcome::success('txf_late_'.$payout->id));

        $this->note('`payouts:poll-open` is the hourly low-rate poll that keeps unresolved attempts under watch');
        $this->call('payouts:poll-open');
        $this->runJob(new PollPayoutAttempt($attempt->id));

        $this->attempts($payout->id);
        $this->buckets($instructorId, 'After the late confirmation — nothing moved', $returned);
        $this->alerts('payout', $payout->id);

        $alerts = ReconciliationAlert::query()
            ->where('subject_type', 'payout')
            ->where('subject_id', $payout->id)
            ->where('kind', 'late_success_on_failed_payout')
            ->count();

        $this->check("invariant 21  the success was recorded on the attempt, from 'unresolved'", PayoutAttempt::query()->find($attempt->id)->status === 'succeeded');
        $this->check("the payout stays 'failed' — §10.6's precondition refused the transition", Payout::query()->find($payout->id)->status === 'failed');
        $this->checkEquals('invariant 22  no money moved', $returned['paid'], DemoLedger::cached($instructorId)['paid']);
        $this->checkEquals('invariant 22  exactly one alert was raised', 1, $alerts);

        $this->step('And a repeated poll does not raise a second alert');

        $this->runJob(new PollPayoutAttempt($attempt->id));

        $this->checkEquals('still exactly one alert', 1, ReconciliationAlert::query()
            ->where('subject_type', 'payout')
            ->where('subject_id', $payout->id)
            ->count());

        $this->say('Dropping the success would be the same class of bug as double-paying, so it is');
        $this->say('recorded. No repair is attempted: the entries may already belong to another');
        $this->say('payout, and §19 lists this as known rather than claiming it solved.');
    }
}
