<?php

// ARCHITECTURE.md §5.1, §5.3, §9.2, §9.3 — money in is confirmed exactly once.
//
// Two keys guard two different windows. The client key covers "we sent a charge
// request, got a timeout, and cannot tell whether a payment exists"; the
// provider reference covers a replayed webhook or a reprocessed settlement file.
// Each is asserted against the window it actually guards.

use App\Domain\Money\Bps;
use App\Domain\Payment\ConfirmPaymentService;
use App\Domain\Payment\InitiatePaymentService;
use App\Jobs\AllocatePaymentRevenue;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/** §3.3: all timestamps are stored and compared in UTC. */
function paymentTestInstant(string $expression): DateTimeImmutable
{
    return new DateTimeImmutable($expression, new DateTimeZone('UTC'));
}

function initiateTestPayment(Subscription $subscription, array $overrides = []): SubscriptionPayment
{
    return app(InitiatePaymentService::class)->initiate(
        $subscription,
        $overrides['amount_minor'] ?? 35_000,
        $overrides['platform_rate_bps'] ?? 2_000,
        $overrides['instructor_ids'] ?? [12, 3, 7],
        $overrides['provider'] ?? 'scripted',
        paymentTestInstant('2026-01-01 00:00:00'),
        paymentTestInstant('2026-04-01 00:00:00'),
        $overrides['client_idempotency_key'] ?? null,
    );
}

beforeEach(function () {
    $this->subscription = Subscription::factory()->create();
});

it('freezes the rate, the cut and the instructor set at initiate', function () {
    $payment = initiateTestPayment($this->subscription, [
        'amount_minor' => 10_001,
        'platform_rate_bps' => 2_000,
    ]);

    // §5.1: the key exists before any provider call; the reference does not.
    expect($payment->client_idempotency_key)->not->toBeNull()
        ->and(strlen($payment->client_idempotency_key))->toBe(36)
        ->and($payment->provider_reference)->toBeNull()
        ->and($payment->status)->toBe('pending')
        ->and($payment->paid_at)->toBeNull();

    // §5.3 / §5.4: the cut is derived from the floored pool, so 10,001 at
    // 2000 bps is 2,001 and not 2,000. Getting this backwards would hand the
    // sub-piastre residue to instructors and contradict §8.
    expect($payment->platform_rate_bps)->toBe(2_000)
        ->and($payment->platform_cut_minor)->toBe(2_001)
        ->and($payment->platform_cut_minor)->toBe(Bps::cut(10_001, 2_000));

    // §5.2: "distinct instructors", stored canonically — deduped and ascending,
    // whatever order the caller supplied.
    expect($payment->fresh()->instructor_ids)->toBe([3, 7, 12]);
});

it('deduplicates and sorts the instructor set', function () {
    $payment = initiateTestPayment($this->subscription, [
        'instructor_ids' => [12, 3, 7, 3, 12],
    ]);

    // §5.2: the same instructor teaching two of the subscription's courses is
    // one participant, not two.
    expect($payment->fresh()->instructor_ids)->toBe([3, 7, 12]);
});

it('refuses a payment that names no instructors', function () {
    // The whole pool would be left unallocated, breaking invariant 1.
    expect(fn () => initiateTestPayment($this->subscription, ['instructor_ids' => []]))
        ->toThrow(InvalidArgumentException::class);
});

it('creates no second payment when the provider webhook is replayed', function () {
    Bus::fake();

    $payment = initiateTestPayment($this->subscription);

    $first = app(ConfirmPaymentService::class)->confirm(
        $payment->client_idempotency_key,
        'ref_webhook_1',
        paymentTestInstant('2026-01-01 10:00:00'),
    );

    expect(SubscriptionPayment::query()->count())->toBe(1);

    // The same webhook delivered again, with a later timestamp so a second
    // write would be visible rather than idempotent by coincidence.
    $replay = app(ConfirmPaymentService::class)->confirm(
        $payment->client_idempotency_key,
        'ref_webhook_1',
        paymentTestInstant('2026-01-02 17:30:00'),
    );

    // §5.1: confirmed exactly once. No second row, and no second confirmation
    // of the first row either — paid_at still records the original delivery.
    expect(SubscriptionPayment::query()->count())->toBe(1)
        ->and($replay->id)->toBe($first->id)
        ->and($replay->provider_reference)->toBe('ref_webhook_1')
        ->and($replay->paid_at->toDateTimeString())->toBe('2026-01-01 10:00:00')
        ->and($replay->status)->toBe('paid');

    // §9.3: the replay matched zero rows and learned it lost from the
    // affected-row count, so it dispatched nothing. One confirmation, one job.
    Bus::assertDispatchedTimes(AllocatePaymentRevenue::class, 1);
});

it('dispatches the allocation job with the payment id once a payment is confirmed', function () {
    Bus::fake();

    $payment = initiateTestPayment($this->subscription);

    app(ConfirmPaymentService::class)->confirm(
        $payment->client_idempotency_key,
        'ref_webhook_2',
        paymentTestInstant('2026-01-01 10:00:00'),
    );

    // §5.2: the job carries the id only. The instructor set is read back from
    // the row, so a lost job is re-dispatchable from the database alone.
    Bus::assertDispatched(
        AllocatePaymentRevenue::class,
        fn (AllocatePaymentRevenue $job): bool => $job->paymentId === $payment->id,
    );
});

it('holds the allocation job until a wrapping caller transaction commits', function () {
    $payment = initiateTestPayment($this->subscription);

    DB::transaction(function () use ($payment) {
        app(ConfirmPaymentService::class)->confirm(
            $payment->client_idempotency_key,
            'ref_wrapped',
            paymentTestInstant('2026-01-01 10:00:00'),
        );

        // §9.2: "without it a worker can pick up a job before the row it
        // depends on is visible". Still inside the caller's transaction, so
        // afterCommit() has not fired and the job has not run. A plain dispatch
        // would have run it right here, against a confirmation no other
        // connection can see yet.
        expect(RevenueAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0);
    });

    // Committed, so the job ran and found a payment it was allowed to allocate.
    expect($payment->fresh()->provider_reference)->toBe('ref_wrapped')
        ->and(RevenueAllocation::query()->where('payment_id', $payment->id)->count())->toBe(3)
        ->and((int) RevenueAllocation::query()->where('payment_id', $payment->id)->sum('amount_minor'))->toBe(28_000);
});

it('rejects a charge request retried under the same client key', function () {
    $payment = initiateTestPayment($this->subscription);

    // §5.1: the window where we sent a charge request, got a timeout, and
    // cannot tell whether a payment exists. §9.1: the retry produces the same
    // key and the insert simply fails rather than creating a duplicate.
    try {
        initiateTestPayment($this->subscription, [
            'client_idempotency_key' => $payment->client_idempotency_key,
        ]);
    } catch (QueryException $e) {
        expect($e->errorInfo[1])->toBe(1062)
            ->and(SubscriptionPayment::query()->count())->toBe(1);

        return;
    }

    Assert::fail('Expected UNIQUE(client_idempotency_key) to reject the retried charge request.');
});

it('rejects a second payment claiming a reference already used by this provider', function () {
    $first = initiateTestPayment($this->subscription);
    $second = initiateTestPayment($this->subscription);

    app(ConfirmPaymentService::class)->confirm(
        $first->client_idempotency_key,
        'ref_shared',
        paymentTestInstant('2026-01-01 10:00:00'),
    );

    // §5.1: a settlement file reprocessed against the wrong row. The client key
    // cannot catch this one — the rows are genuinely different payments — so
    // UNIQUE(provider, provider_reference) is what stops it.
    try {
        app(ConfirmPaymentService::class)->confirm(
            $second->client_idempotency_key,
            'ref_shared',
            paymentTestInstant('2026-01-01 11:00:00'),
        );
    } catch (QueryException $e) {
        expect($e->errorInfo[1])->toBe(1062)
            ->and($second->fresh()->provider_reference)->toBeNull()
            ->and($second->fresh()->status)->toBe('pending');

        return;
    }

    Assert::fail('Expected UNIQUE(provider, provider_reference) to reject the duplicated reference.');
});

it('allows two providers to issue the same reference', function () {
    $first = initiateTestPayment($this->subscription, ['provider' => 'scripted']);
    $second = initiateTestPayment($this->subscription, ['provider' => 'other']);

    app(ConfirmPaymentService::class)->confirm($first->client_idempotency_key, 'ref_1', paymentTestInstant('2026-01-01 10:00:00'));
    app(ConfirmPaymentService::class)->confirm($second->client_idempotency_key, 'ref_1', paymentTestInstant('2026-01-01 11:00:00'));

    // §5.1: references are only guaranteed unique *within* a provider, which is
    // why the constraint is namespaced rather than global.
    expect(DB::table('subscription_payments')->where('provider_reference', 'ref_1')->count())->toBe(2);
});

it('tolerates many unconfirmed payments with no reference at all', function () {
    initiateTestPayment($this->subscription);
    initiateTestPayment($this->subscription);
    initiateTestPayment($this->subscription);

    // §5.1: many NULLs are tolerated in the (provider, provider_reference)
    // index, because uniqueness for unconfirmed rows is carried by the client
    // key instead.
    expect(SubscriptionPayment::query()->whereNull('provider_reference')->count())->toBe(3);
});
