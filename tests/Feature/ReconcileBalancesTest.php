<?php

// ARCHITECTURE.md §12 — four checks, one per link in the chain:
// payment -> allocations -> ledger -> balance cache.

use App\Domain\Recognition\ReleaseService;
use App\Models\InstructorBalance;
use App\Models\ReconciliationAlert;
use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;
use Tests\Support\RecognitionFixtures;

/**
 * An instructor whose ledger, cache and cursor all agree, released to term end
 * so that expected(t) is flat from here on.
 *
 *     term 2026-03-01 .. 2026-05-01  =  61 whole days, allocation 204
 *     released(31 May) = floor(204 × 61/61) = 204   (elapsed clamped at 61)
 */
function reconcileCleanInstructor(int $instructorId): SubscriptionPayment
{
    $payment = RecognitionFixtures::allocation(
        $instructorId,
        204,
        RecognitionFixtures::utc('2026-03-01 00:00:00'),
        RecognitionFixtures::utc('2026-05-01 00:00:00'),
    );

    app(ReleaseService::class)->releaseScheduled(
        $instructorId,
        RecognitionFixtures::utc('2026-05-31 00:00:00'),
    );

    return $payment;
}

it('passes all four levels on consistent data and raises nothing', function () {
    reconcileCleanInstructor(7);

    expect(RecognitionFixtures::posted(7))->toBe(204);

    $this->artisan('reconcile:balances')
        ->expectsOutputToContain('Reconciliation clean')
        ->assertExitCode(0);

    expect(ReconciliationAlert::query()->count())->toBe(0);
});

it('level zero: detects a confirmed payment that is not fully allocated', function () {
    reconcileCleanInstructor(7);

    // A second instructor with no releases at all, so only level zero can fire
    // for this payment: with no watermark and no entries, levels one to three
    // are trivially in agreement.
    $payment = RecognitionFixtures::allocation(
        12,
        5_000,
        RecognitionFixtures::utc('2026-03-01 00:00:00'),
        RecognitionFixtures::utc('2026-05-01 00:00:00'),
    );

    // §12: "a partial set created by a bug or a manual edit would sit
    // unnoticed" — payments:allocate-missing only picks up payments with NO
    // allocations, so this is the gap the check exists to close.
    RevenueAllocation::query()
        ->where('payment_id', $payment->id)
        ->update(['amount_minor' => 4_000]);

    $this->artisan('reconcile:balances')->assertExitCode(1);

    $alert = ReconciliationAlert::query()->sole();

    expect($alert->kind)->toBe('allocation_mismatch')
        ->and($alert->subject_type)->toBe('subscription_payment')
        ->and($alert->subject_id)->toBe($payment->id)
        ->and($alert->detail['shortfall_minor'])->toBe(1_000)
        ->and($alert->resolved_at)->toBeNull();
});

/**
 * A confirmed payment with no allocations at all, confirmed $confirmedAt.
 *
 * The instructor has no releases, so levels one to three are trivially in
 * agreement and only level zero can have anything to say about it.
 */
function reconcileUnallocatedPayment(DateTimeInterface $confirmedAt): SubscriptionPayment
{
    $payment = RecognitionFixtures::allocation(
        12,
        5_000,
        RecognitionFixtures::utc('2026-03-01 00:00:00'),
        RecognitionFixtures::utc('2026-05-01 00:00:00'),
    );

    // The state §5.1's single transaction produces before the allocation job
    // lands: money in, nothing split.
    RevenueAllocation::query()->where('payment_id', $payment->id)->delete();

    SubscriptionPayment::query()->whereKey($payment->id)->update(['paid_at' => $confirmedAt]);

    return $payment;
}

it('level zero: leaves a payment confirmed minutes ago for its allocation job', function () {
    reconcileCleanInstructor(7);

    // §5.1 dispatches the allocation job afterCommit; the five-minutely sweeper
    // has not even run once yet. This payment is in flight, not broken.
    reconcileUnallocatedPayment(now()->utc()->subMinutes(5));

    $this->artisan('reconcile:balances')
        ->expectsOutputToContain('Reconciliation clean')
        ->assertExitCode(0);

    expect(ReconciliationAlert::query()->count())->toBe(0);
});

it('level zero: reports the same payment once it is genuinely stuck', function () {
    reconcileCleanInstructor(7);

    // Two hours is twenty-four missed sweep cycles. Nothing is coming.
    $payment = reconcileUnallocatedPayment(now()->utc()->subHours(2));

    $this->artisan('reconcile:balances')->assertExitCode(1);

    $alert = ReconciliationAlert::query()->sole();

    expect($alert->kind)->toBe('allocation_mismatch')
        ->and($alert->subject_id)->toBe($payment->id)
        ->and($alert->detail['allocated_minor'])->toBe(0)
        ->and($alert->detail['shortfall_minor'])->toBe(5_000);
});

it('level zero: checks a confirmed payment with no paid_at rather than exempting it', function () {
    reconcileCleanInstructor(7);

    // A broken row: confirmed, but with nothing to date the confirmation by.
    // The grace window may only ever silence a payment provably recent, so this
    // one is checked — erring toward the alert, never toward the silence.
    $payment = reconcileUnallocatedPayment(now()->utc()->subHours(2));
    SubscriptionPayment::query()->whereKey($payment->id)->update(['paid_at' => null]);

    $this->artisan('reconcile:balances')->assertExitCode(1);

    expect(ReconciliationAlert::query()->sole()->kind)->toBe('allocation_mismatch');
});

it('level one: detects a balance cache that disagrees with the ledger', function () {
    reconcileCleanInstructor(7);

    // §10.5 keeps this in step inside the transaction that moves the ledger.
    // Moving it by hand is the drift the check exists to find.
    InstructorBalance::query()->whereKey(7)->update(['available_minor' => 999]);

    $this->artisan('reconcile:balances')->assertExitCode(1);

    $alert = ReconciliationAlert::query()->sole();

    expect($alert->kind)->toBe('bucket_mismatch')
        ->and($alert->subject_type)->toBe('instructor_balance')
        ->and($alert->subject_id)->toBe(7)
        ->and($alert->detail['cached']['available_minor'])->toBe(999)
        ->and($alert->detail['ledger']['available_minor'])->toBe(204);
});

it('level two: detects a refund whose targeted recompute never ran', function () {
    $payment = reconcileCleanInstructor(7);

    // §6.1: access terminated, which caps recognition — but no correction was
    // posted. The ledger now claims more than was sold.
    //
    // Chosen because it moves expected(W) and nothing else: the allocations are
    // untouched (level zero clean), the cache still matches the ledger (level
    // one clean) and the cursor still matches MAX(ledger) (level three clean).
    RecognitionFixtures::terminateAccess($payment, RecognitionFixtures::utc('2026-04-01 00:00:00'));

    $this->artisan('reconcile:balances')->assertExitCode(1);

    $alert = ReconciliationAlert::query()->sole();

    // released(W) with effective_days 31: floor(204 × 31/61) = 103.
    expect($alert->kind)->toBe('ledger_mismatch')
        ->and($alert->subject_id)->toBe(7)
        ->and($alert->detail['posted_minor'])->toBe(204)
        ->and($alert->detail['expected_minor'])->toBe(103)
        ->and($alert->detail['drift_minor'])->toBe(101);
});

it('level three: detects a cursor that has drifted ahead of the ledger', function () {
    reconcileCleanInstructor(7);

    // §12: "a cursor that has drifted ahead of the ledger would silently
    // suppress legitimate release runs".
    //
    // Pushed past term end on purpose: expected(t) is already clamped at the
    // full 204 there, so level two stays clean and only the cursor is wrong.
    InstructorBalance::query()
        ->whereKey(7)
        ->update(['recognized_through_at' => '2026-06-30 00:00:00']);

    $this->artisan('reconcile:balances')->assertExitCode(1);

    $alert = ReconciliationAlert::query()->sole();

    expect($alert->kind)->toBe('watermark_drift')
        ->and($alert->subject_id)->toBe(7)
        ->and($alert->detail['cursor'])->toBe('2026-06-30 00:00:00')
        ->and($alert->detail['ledger_max'])->toBe('2026-05-31 00:00:00');
});

it('detects but never repairs', function () {
    reconcileCleanInstructor(7);

    InstructorBalance::query()->whereKey(7)->update(['available_minor' => 999]);

    $this->artisan('reconcile:balances')->assertExitCode(1);

    // §12: reconciliation exists to detect drift, not to repair it. "A system
    // that quietly repairs itself hides the bug that caused the problem."
    expect(RecognitionFixtures::balance(7)->available_minor)->toBe(999)
        ->and(RecognitionFixtures::posted(7))->toBe(204)
        ->and(RecognitionFixtures::entries(7))->toHaveCount(1);
});

it('keeps failing loudly without piling up a duplicate alert each night', function () {
    reconcileCleanInstructor(7);

    InstructorBalance::query()->whereKey(7)->update(['available_minor' => 999]);

    $this->artisan('reconcile:balances')->assertExitCode(1);
    $this->artisan('reconcile:balances')->assertExitCode(1);

    // The exit code is what makes the failure loud, and it is unconditional.
    // The alert is an open item, so a second night's run leaves the standing
    // one alone rather than adding another.
    expect(ReconciliationAlert::query()->where('kind', 'bucket_mismatch')->count())->toBe(1);
});
