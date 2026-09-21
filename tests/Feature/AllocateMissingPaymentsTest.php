<?php

// ARCHITECTURE.md §5.3, §9.1, §10.7 — a lost allocation job is re-dispatched
// from the database alone.
//
// §5.3 freezes the instructor set onto the payment specifically so this is
// possible. These tests are what make that a property of the system rather than
// a property of the prose.

use App\Domain\Payment\ConfirmPaymentService;
use App\Domain\Payment\InitiatePaymentService;
use App\Jobs\AllocatePaymentRevenue;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;

/** §3.3: all timestamps are stored and compared in UTC. */
function sweepTestInstant(string $expression): DateTimeImmutable
{
    return new DateTimeImmutable($expression, new DateTimeZone('UTC'));
}

/**
 * Confirm a payment with the bus faked, so the allocation job is dispatched and
 * then dropped on the floor — exactly the state §5.3's re-dispatch exists for.
 * The real dispatcher is restored before returning, so the sweep under test runs
 * for real.
 */
function strandPayment(array $overrides = []): SubscriptionPayment
{
    $subscription = Subscription::factory()->create();

    $original = app(Dispatcher::class);
    Bus::fake();

    $payment = app(InitiatePaymentService::class)->initiate(
        $subscription,
        $overrides['amount_minor'] ?? 35_000,
        $overrides['platform_rate_bps'] ?? 2_000,
        $overrides['instructor_ids'] ?? [12, 3, 7],
        'scripted',
        sweepTestInstant('2026-01-01 00:00:00'),
        sweepTestInstant('2026-04-01 00:00:00'),
    );

    app(ConfirmPaymentService::class)->confirm(
        $payment->client_idempotency_key,
        $overrides['provider_reference'] ?? 'ref_'.$payment->id,
        sweepTestInstant('2026-01-01 10:00:00'),
    );

    // The job really was dispatched — the queue lost it, we did not skip it.
    Bus::assertDispatched(AllocatePaymentRevenue::class);

    Bus::swap($original);

    return $payment->fresh();
}

/** Row identity, so "nothing changed" means the rows are untouched (§5.3). */
function allocationSnapshot(int $paymentId): array
{
    return RevenueAllocation::query()
        ->where('payment_id', $paymentId)
        ->orderBy('instructor_id')
        ->get(['id', 'instructor_id', 'amount_minor', 'created_at'])
        ->toArray();
}

it('allocates a payment whose job was lost, and changes nothing when run again', function () {
    $payment = strandPayment();

    // Money in, nothing split — the state the sweeper exists to find.
    expect(RevenueAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0);

    $this->artisan('payments:allocate-missing')->assertExitCode(0);

    $pool = $payment->amount_minor - $payment->platform_cut_minor;
    $sum = (int) RevenueAllocation::query()->where('payment_id', $payment->id)->sum('amount_minor');

    // §5.5 / invariant 1: the whole pool, exactly, and no tolerance.
    expect(RevenueAllocation::query()->where('payment_id', $payment->id)->count())->toBe(3)
        ->and($sum)->toBe($pool)
        ->and($sum)->toBe(28_000)
        ->and($sum + $payment->platform_cut_minor)->toBe($payment->amount_minor);

    $first = allocationSnapshot($payment->id);

    // §9.1: safe to run any number of times. The second sweep still finds
    // nothing to do, and even if it dispatched again the unique key would make
    // the job a no-op.
    $this->artisan('payments:allocate-missing')->assertExitCode(0);
    $this->artisan('payments:allocate-missing')->assertExitCode(0);

    // Same row ids and same created_at: not re-written, not re-inserted.
    expect(allocationSnapshot($payment->id))->toBe($first)
        ->and((int) RevenueAllocation::query()->where('payment_id', $payment->id)->sum('amount_minor'))->toBe($pool);
});

it('re-dispatches nothing once every confirmed payment is allocated', function () {
    Bus::fake();

    $payment = SubscriptionPayment::factory()->confirmed()->create(['instructor_ids' => [3, 7, 12]]);

    RevenueAllocation::query()->insert([
        ['payment_id' => $payment->id, 'instructor_id' => 3, 'amount_minor' => 9_334, 'weight_numerator' => 1, 'weight_denominator' => 3, 'created_at' => now()->utc()],
        ['payment_id' => $payment->id, 'instructor_id' => 7, 'amount_minor' => 9_333, 'weight_numerator' => 1, 'weight_denominator' => 3, 'created_at' => now()->utc()],
        ['payment_id' => $payment->id, 'instructor_id' => 12, 'amount_minor' => 9_333, 'weight_numerator' => 1, 'weight_denominator' => 3, 'created_at' => now()->utc()],
    ]);

    $this->artisan('payments:allocate-missing')->assertExitCode(0);

    Bus::assertNotDispatched(AllocatePaymentRevenue::class);
});

it('leaves unconfirmed payments alone', function () {
    Bus::fake();

    // provider_reference null: the charge may never have landed, so there is no
    // money to split. Dispatching here would queue a job AllocationService
    // refuses on every attempt, forever.
    SubscriptionPayment::factory()->create(['instructor_ids' => [3, 7, 12]]);

    $this->artisan('payments:allocate-missing')->assertExitCode(0);

    Bus::assertNotDispatched(AllocatePaymentRevenue::class);
    expect(RevenueAllocation::query()->count())->toBe(0);
});

it('sweeps several stranded payments in one run', function () {
    $first = strandPayment(['provider_reference' => 'ref_a']);
    $second = strandPayment(['amount_minor' => 10_001, 'instructor_ids' => [5], 'provider_reference' => 'ref_b']);

    $this->artisan('payments:allocate-missing')->assertExitCode(0);

    // §5.4: 10,001 at 2000 bps floors the pool to 8,000 and leaves a 2,001 cut,
    // so a one-instructor payment takes the whole 8,000.
    expect((int) RevenueAllocation::query()->where('payment_id', $first->id)->sum('amount_minor'))->toBe(28_000)
        ->and((int) RevenueAllocation::query()->where('payment_id', $second->id)->sum('amount_minor'))->toBe(8_000)
        ->and(RevenueAllocation::query()->where('payment_id', $second->id)->count())->toBe(1);
});

it('succeeds when there is nothing at all to sweep', function () {
    $this->artisan('payments:allocate-missing')->assertExitCode(0);

    expect(RevenueAllocation::query()->count())->toBe(0);
});
