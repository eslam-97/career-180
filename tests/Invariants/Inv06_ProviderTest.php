<?php

// Invariants 30, 31, 32 — ARCHITECTURE.md §11.1, §11.2, §16.3

use App\Domain\Payout\AttemptService;
use App\Domain\Provider\Scenario;
use App\Jobs\PollPayoutAttempt;
use App\Jobs\SendPayoutAttempt;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PayoutFixtures;
use Tests\Support\RecognitionFixtures;

function inv06Now(): void
{
    test()->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));
}

function inv06Attempt(int $attemptId): PayoutAttempt
{
    return PayoutAttempt::query()->findOrFail($attemptId);
}

it('inv-30: an attempt past its lease resolves by status query, never by resending', function () {
    // Attempt in 'sending' with lease_expires_at in the past.
    // Run the lease sweeper.
    // Assert: status -> 'unknown', and provider send() was NOT called again.
    // Then assert the poller calls status() with the SAME idempotency key.
    $instructor = 161;

    inv06Now();

    $provider = PayoutFixtures::scripted(Scenario::Success);

    $payout = PayoutFixtures::claimedPayout($instructor, 300);

    // §16.3: "a crashing worker is simulated by committing the attempt and then
    // throwing before the outcome is recorded". The slot is acquired and the
    // worker dies right there — nothing was sent, but the system cannot know
    // that, which is the whole point of §11.1.
    $slot = PayoutFixtures::acquire($payout);

    expect(inv06Attempt($slot->id)->status)->toBe('sending')
        ->and($provider->sendCalls())->toBe(0);

    PayoutFixtures::expireLease($slot->id);

    Queue::fake();

    $this->artisan('payouts:sweep-leases')->assertSuccessful();

    // §11.1: "the sweeper's ONLY legal transition is sending -> unknown. It
    // never marks an attempt failed and never resends."
    expect(inv06Attempt($slot->id)->status)->toBe('unknown')
        ->and($provider->sendCalls())->toBe(0)
        ->and(Payout::query()->findOrFail($payout->id)->attempt_count)->toBe(1)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(1);

    Queue::assertNotPushed(SendPayoutAttempt::class);
    Queue::assertPushed(PollPayoutAttempt::class);

    app()->call([new PollPayoutAttempt($slot->id), 'handle']);

    // §11.3: the recovery is a question, asked with the key that was sent. A
    // poll that derived a new key would be asking about a transfer nobody made.
    expect($provider->statusKeys())->toBe([$slot->idempotencyKey])
        ->and($slot->idempotencyKey)->toBe(AttemptService::idempotencyKey($payout->id, 1))
        ->and($provider->sendCalls())->toBe(0)
        // Nothing was ever sent, so the provider has nothing stored: the answer
        // is UNKNOWN, the claim is held, and the attempt stays pollable.
        ->and(inv06Attempt($slot->id)->status)->toBe('unknown')
        ->and(inv06Attempt($slot->id)->poll_count)->toBe(1)
        ->and(PayoutFixtures::buckets($instructor))
        ->toBe(['recognized' => 300, 'available' => 0, 'reserved' => 300, 'paid' => 0]);
});

it('inv-31: timeout after success resolves to paid with exactly one transfer', function () {
    // ScriptedProvider in TIMEOUT_AFTER_SUCCESS mode: it RECORDS the transfer,
    // then throws. Money has moved as far as the provider is concerned.
    // Run the payout. Assert attempt -> 'unknown'.
    // Run the poller. status() returns SUCCESS. Assert payout -> 'settled'.
    // Assert the provider's internal transfer count for that key is exactly 1.
    // If the provider throws BEFORE recording, this test is vacuous — assert the
    // transfer count directly, not just the final status.
    $instructor = 171;

    inv06Now();

    Queue::fake();

    $provider = PayoutFixtures::scripted(Scenario::TimeoutAfterSuccess);

    $payout = PayoutFixtures::claimedPayout($instructor, 4_563);

    app()->call([new SendPayoutAttempt($payout->id), 'handle']);

    $attempt = PayoutAttempt::query()->where('payout_id', $payout->id)->sole();
    $key = AttemptService::idempotencyKey($payout->id, 1);

    // §11: "a timeout is not a failure". The money moved; our side heard nothing.
    expect($attempt->status)->toBe('unknown')
        ->and($attempt->idempotency_key)->toBe($key)
        // The assertion that makes the rest non-vacuous: the transfer really was
        // recorded before the throw (§16.3).
        ->and($provider->transferCount($key))->toBe(1)
        ->and($provider->sendCalls())->toBe(1)
        // The claim is held while we do not know (§11.4, "provider timeout").
        ->and(PayoutFixtures::buckets($instructor))
        ->toBe(['recognized' => 4_563, 'available' => 0, 'reserved' => 4_563, 'paid' => 0]);

    Queue::assertPushed(PollPayoutAttempt::class);

    app()->call([new PollPayoutAttempt($attempt->id), 'handle']);

    expect($provider->statusKeys())->toBe([$key])
        ->and(inv06Attempt($attempt->id)->status)->toBe('succeeded')
        ->and(inv06Attempt($attempt->id)->provider_reference)->not->toBeNull()
        ->and(Payout::query()->findOrFail($payout->id)->status)->toBe('settled')
        // §10.5: reserved -> paid, and recognized untouched.
        ->and(PayoutFixtures::buckets($instructor))
        ->toBe(['recognized' => 4_563, 'available' => 0, 'reserved' => 0, 'paid' => 4_563])
        // One transfer. Never two — not from the poll, and not from a resend
        // that never happened.
        ->and($provider->transferCount($key))->toBe(1)
        ->and($provider->sendCalls())->toBe(1)
        ->and(PayoutAttempt::query()->where('payout_id', $payout->id)->count())->toBe(1);
});

it('inv-32: a failed payout returns its entries and the next batch claims them once', function () {
    // Payout fails definitively (no live attempts, none succeeded).
    // Assert entries return to payout_id = NULL and available increases.
    // Run the next batch. Assert those entries are claimed exactly once,
    // by exactly one new payout.
    $instructor = 181;

    inv06Now();

    Queue::fake();

    // One attempt allowed, so a definitive failure exhausts the ceiling in a
    // single call and §10.7's "above the ceiling it moves to failed via §10.6"
    // runs as part of the same path a retry-exhausted payout takes.
    config()->set('payouts.attempt_ceiling', 1);

    $provider = PayoutFixtures::scripted(Scenario::Failure);

    $first = PayoutFixtures::claimedPayout($instructor, 300);
    $entry = LedgerEntry::query()->where('instructor_id', $instructor)->sole();

    expect($entry->payout_id)->toBe($first->id);

    app()->call([new SendPayoutAttempt($first->id), 'handle']);

    expect(Payout::query()->findOrFail($first->id)->status)->toBe('failed')
        ->and(PayoutAttempt::query()->where('payout_id', $first->id)->sole()->status)->toBe('failed')
        ->and($provider->sendCalls())->toBe(1)
        // §10.6: the entries are un-stamped in the same transaction that failed
        // the payout — never a commit in between (invariant 4).
        ->and($entry->fresh()->payout_id)->toBeNull()
        ->and(PayoutFixtures::buckets($instructor))
        ->toBe(['recognized' => 300, 'available' => 300, 'reserved' => 0, 'paid' => 0])
        ->and(PayoutFixtures::orphanedEntries())->toBe(0);

    // §10.4's netting: the next batch takes every unclaimed payable entry.
    $second = PayoutFixtures::claim($instructor);

    expect($second->id)->not->toBe($first->id)
        ->and($second->amount_minor)->toBe(300)
        ->and($entry->fresh()->payout_id)->toBe($second->id)
        // Exactly once, by exactly one payout: no entry claimed twice, and no
        // second payout created for the same money.
        ->and(LedgerEntry::query()->where('instructor_id', $instructor)->count())->toBe(1)
        ->and(Payout::query()->where('instructor_id', $instructor)->count())->toBe(2)
        ->and(Payout::query()->where('instructor_id', $instructor)->where('status', 'failed')->count())->toBe(1)
        ->and(PayoutFixtures::buckets($instructor))
        ->toBe(['recognized' => 300, 'available' => 0, 'reserved' => 300, 'paid' => 0])
        ->and(PayoutFixtures::ledgerBuckets($instructor))->toBe(PayoutFixtures::buckets($instructor));
});
