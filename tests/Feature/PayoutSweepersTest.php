<?php

// ARCHITECTURE.md §10.7, §11.1, §11.2 — the three scheduled commands, and the
// one relationship between the configured numbers that has to hold.

use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Outcome;
use App\Domain\Provider\Scenario;
use App\Jobs\PollPayoutAttempt;
use App\Jobs\SendPayoutAttempt;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PayoutFixtures;
use Tests\Support\RecognitionFixtures;

function sweeperTestNow(): void
{
    test()->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));
}

it('leases outlive the provider timeout', function () {
    // §11.1: the lease is what turns silence into a status query. If it expired
    // before the worker's own call to the provider had timed out, the sweeper
    // would move a LIVE attempt to unknown while its worker was still waiting
    // for an answer — two things resolving the same attempt at once.
    expect((int) config('payouts.lease_seconds'))
        ->toBeGreaterThan((int) config('payouts.provider_timeout_seconds'))
        // §10.2 / §10.7: the same ceiling for the gate and the sweeper, and it
        // has to allow at least one attempt.
        ->and((int) config('payouts.attempt_ceiling'))->toBeGreaterThan(0)
        ->and((int) config('payouts.max_polls'))->toBeGreaterThan(0)
        ->and(config('payouts.poll_backoff_seconds'))->not->toBeEmpty();
});

it('the stranded sweeper re-dispatches a claimed payout with no attempt', function () {
    sweeperTestNow();

    $payout = PayoutFixtures::claimedPayout(201, 300);

    expect($payout->status)->toBe('pending')
        ->and($payout->attempt_count)->toBe(0);

    Queue::fake();

    $this->artisan('payouts:sweep-stranded')
        ->expectsOutputToContain('Re-dispatched 1 stranded payout(s)')
        ->assertSuccessful();

    // §10.7: "a payout in pending or in_progress with no active attempt and
    // attempt_count below the ceiling is re-dispatched". This is also the
    // ordinary road out of the claim (§10.3).
    Queue::assertPushed(SendPayoutAttempt::class, fn (SendPayoutAttempt $job) => $job->payoutId === $payout->id);
});

it('the stranded sweeper fails a payout that has exhausted the ceiling', function () {
    sweeperTestNow();

    config()->set('payouts.attempt_ceiling', 1);

    $settlement = new SettlementService;
    $payout = PayoutFixtures::claimedPayout(202, 300);
    $slot = PayoutFixtures::acquire($payout);

    // The attempt is over and no further one may be created, but the dispatch
    // that would have failed the payout never landed.
    $settlement->recordFailure($slot->id, Outcome::failure());

    Queue::fake();

    $this->artisan('payouts:sweep-stranded')
        ->expectsOutputToContain('failed 1 past the attempt ceiling')
        ->assertSuccessful();

    Queue::assertNotPushed(SendPayoutAttempt::class);

    // §10.7: "above the ceiling it moves to failed via §10.6" — same
    // transaction, same preconditions, entries back to available.
    expect(Payout::query()->findOrFail($payout->id)->status)->toBe('failed')
        ->and(PayoutFixtures::buckets(202))
        ->toBe(['recognized' => 300, 'available' => 300, 'reserved' => 0, 'paid' => 0]);
});

it('the lease sweeper only touches expired sending attempts', function () {
    sweeperTestNow();

    PayoutFixtures::scripted(Scenario::Success);

    $expired = PayoutFixtures::acquire(PayoutFixtures::claimedPayout(203, 300));
    $live = PayoutFixtures::acquire(PayoutFixtures::claimedPayout(204, 300));

    PayoutFixtures::expireLease($expired->id);

    Queue::fake();

    $this->artisan('payouts:sweep-leases')
        ->expectsOutputToContain('Swept 1 expired lease(s) to unknown')
        ->assertSuccessful();

    // §11.1: sending -> unknown, and nothing else. The attempt whose lease is
    // still running is left alone, because its worker may be mid-call.
    expect(PayoutAttempt::query()->findOrFail($expired->id)->status)->toBe('unknown')
        ->and(PayoutAttempt::query()->findOrFail($live->id)->status)->toBe('sending');

    Queue::assertPushed(PollPayoutAttempt::class, fn (PollPayoutAttempt $job) => $job->attemptId === $expired->id);
    Queue::assertNotPushed(SendPayoutAttempt::class);
});

it('the open-attempt poll asks about unknown and unresolved attempts only', function () {
    sweeperTestNow();

    $settlement = new SettlementService;

    $unknown = PayoutFixtures::acquire(PayoutFixtures::claimedPayout(205, 300));
    $settlement->recordUnknown($unknown->id, Outcome::unknown());

    $unresolved = PayoutFixtures::acquire(PayoutFixtures::claimedPayout(206, 300));
    $settlement->recordUnknown($unresolved->id, Outcome::unknown());
    $settlement->markUnresolved($unresolved->id);

    $settled = PayoutFixtures::acquire(PayoutFixtures::claimedPayout(207, 300));
    $settlement->recordSuccess($settled->id, Outcome::success('txf_done'));

    $sending = PayoutFixtures::acquire(PayoutFixtures::claimedPayout(208, 300));

    Queue::fake();

    $this->artisan('payouts:poll-open')
        ->expectsOutputToContain('Queued 2 status queries')
        ->assertSuccessful();

    // §11.2: "low-rate polling continues indefinitely afterwards so a
    // contradicting success is detected rather than lost" — which is why
    // 'unresolved' is in this set and a settled attempt is not. A 'sending'
    // attempt is excluded because its worker still owns it (§11.1).
    Queue::assertPushed(PollPayoutAttempt::class, fn (PollPayoutAttempt $job) => $job->attemptId === $unknown->id);
    Queue::assertPushed(PollPayoutAttempt::class, fn (PollPayoutAttempt $job) => $job->attemptId === $unresolved->id);
    Queue::assertNotPushed(PollPayoutAttempt::class, fn (PollPayoutAttempt $job) => $job->attemptId === $settled->id);
    Queue::assertNotPushed(PollPayoutAttempt::class, fn (PollPayoutAttempt $job) => $job->attemptId === $sending->id);
});
