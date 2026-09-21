<?php

// Invariant 1 at the persistence layer — ARCHITECTURE.md §5.1, §5.2, §5.3, §5.5
//
// Inv01_MoneyTest already proves `sum(allocations) + cut == gross` over
// in-memory arrays. This file proves the same thing over rows read back out of
// MySQL, against a platform_cut_minor that was frozen at initiate and a pool the
// service never recomputed. The arithmetic being right is not the same claim as
// the right arithmetic reaching the table.

use App\Domain\Allocation\AllocationService;
use App\Domain\Payment\ConfirmPaymentService;
use App\Domain\Payment\InitiatePaymentService;
use App\Jobs\AllocatePaymentRevenue;
use App\Models\InstructorBalance;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;

/** §3.3: all timestamps are stored and compared in UTC. */
function allocationTestInstant(string $expression): DateTimeImmutable
{
    return new DateTimeImmutable($expression, new DateTimeZone('UTC'));
}

/**
 * A payment row written directly, so a test can pin a frozen cut that the
 * current rate would not produce. Raw insert on purpose — what is under test is
 * what the service does with the row, not how the row got there.
 */
function allocatableTestPayment(array $overrides = []): SubscriptionPayment
{
    $subscription = Subscription::factory()->create();

    return SubscriptionPayment::factory()->confirmed()->create(array_merge([
        'subscription_id' => $subscription->id,
        'term_start' => allocationTestInstant('2026-01-01 00:00:00'),
        'term_end' => allocationTestInstant('2026-04-01 00:00:00'),
    ], $overrides));
}

function runAllocation(int $paymentId): void
{
    app(AllocationService::class)->allocate($paymentId);
}

it('writes the §5.5 worked example exactly as the doc specifies', function () {
    // gross 35,000 at 2000 bps → cut 7,000, pool 28,000 ÷ 3 = 9333.33…
    // All three remainders tie, so the leftover piastre goes to the lowest id.
    $payment = allocatableTestPayment([
        'amount_minor' => 35_000,
        'platform_rate_bps' => 2_000,
        'platform_cut_minor' => 7_000,
        'instructor_ids' => [3, 7, 12],
    ]);

    runAllocation($payment->id);

    $amounts = RevenueAllocation::query()
        ->where('payment_id', $payment->id)
        ->orderBy('instructor_id')
        ->pluck('amount_minor', 'instructor_id')
        ->all();

    expect($amounts)->toBe([3 => 9_334, 7 => 9_333, 12 => 9_333])
        ->and(array_sum($amounts))->toBe(28_000);

    // §3.1: the rule stored as an exact rational — 1/3, never 0.3333333333.
    $weights = RevenueAllocation::query()->where('payment_id', $payment->id)->get();

    foreach ($weights as $allocation) {
        expect($allocation->weight_numerator)->toBe(1)
            ->and($allocation->weight_denominator)->toBe(3);
    }
});

it('creates no second set of allocations when the job runs twice', function () {
    $payment = allocatableTestPayment(['instructor_ids' => [3, 7, 12]]);

    (new AllocatePaymentRevenue($payment->id))->handle(app(AllocationService::class));

    $before = RevenueAllocation::query()
        ->where('payment_id', $payment->id)
        ->orderBy('instructor_id')
        ->get(['id', 'instructor_id', 'amount_minor', 'created_at'])
        ->toArray();

    expect($before)->toHaveCount(3);

    // §5.1: "an allocation job retried against an already-confirmed payment
    // would allocate twice. That is guarded separately" — by the unique key.
    // Note the job carries no ShouldBeUnique, so nothing but the constraint can
    // be what makes this pass.
    (new AllocatePaymentRevenue($payment->id))->handle(app(AllocationService::class));

    $after = RevenueAllocation::query()
        ->where('payment_id', $payment->id)
        ->orderBy('instructor_id')
        ->get(['id', 'instructor_id', 'amount_minor', 'created_at'])
        ->toArray();

    // Same row ids and same created_at, so the second run did not delete and
    // reinsert the set either — the rows are genuinely untouched (§5.3).
    expect($after)->toHaveCount(3)
        ->and($after)->toBe($before);
});

it('writes no partial set when some allocations already exist', function () {
    $payment = allocatableTestPayment([
        'amount_minor' => 35_000,
        'platform_rate_bps' => 2_000,
        'platform_cut_minor' => 7_000,
        'instructor_ids' => [3, 7, 12],
    ]);

    // One allocation already on disk — a crashed run, or a hand-repair.
    DB::table('revenue_allocations')->insert([
        'payment_id' => $payment->id,
        'instructor_id' => 7,
        'amount_minor' => 9_333,
        'weight_numerator' => 1,
        'weight_denominator' => 3,
        'created_at' => now()->utc(),
    ]);

    // §5.1: all allocations for one payment are written in a single
    // transaction, so the duplicate fails the whole statement and the partial
    // set is left exactly as it was. Nothing half-written, nothing doubled.
    //
    // And the job refuses to report success on it. Swallowing the duplicate
    // silently here is what would turn a state §5.1 says cannot arise into a
    // permanently under-allocated payment: every retry would fail the same way
    // and report done, with invariant 1 broken and nothing to notice.
    expect(fn () => runAllocation($payment->id))->toThrow(DomainException::class);

    $rows = RevenueAllocation::query()->where('payment_id', $payment->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->instructor_id)->toBe(7);
});

it('refuses to allocate a payment the provider has not confirmed', function () {
    $subscription = Subscription::factory()->create();

    // provider_reference null — the charge may never have landed.
    $payment = SubscriptionPayment::factory()->create([
        'subscription_id' => $subscription->id,
        'instructor_ids' => [3, 7, 12],
    ]);

    expect(fn () => runAllocation($payment->id))->toThrow(DomainException::class);

    // Allocating here would recognise money that never arrived.
    expect(RevenueAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0);
});

it('splits the frozen cut, not a freshly computed one', function () {
    // A payment whose frozen figures deliberately disagree with today's rate:
    // 35,000 with a cut of 10,000 is 2857.14… bps, not the 2,000 stored beside
    // it. If the service recomputed the pool from platform_rate_bps it would
    // split 28,000; §5.3 says it must split the frozen 25,000.
    $payment = allocatableTestPayment([
        'amount_minor' => 35_000,
        'platform_rate_bps' => 2_000,
        'platform_cut_minor' => 10_000,
        'instructor_ids' => [3, 7, 12],
    ]);

    runAllocation($payment->id);

    $sum = (int) RevenueAllocation::query()->where('payment_id', $payment->id)->sum('amount_minor');

    // §5.3: freezing is what lets reconciliation tell drift from a config
    // change. 25,000 ÷ 3 = 8333.33… → 8,334 / 8,333 / 8,333.
    expect($sum)->toBe(25_000)
        ->and($sum)->not->toBe(28_000)
        ->and(RevenueAllocation::query()->where('payment_id', $payment->id)->orderBy('instructor_id')->pluck('amount_minor')->all())
        ->toBe([8_334, 8_333, 8_333]);
});

it('creates a zeroed balance row for every allocated instructor, once', function () {
    $payment = allocatableTestPayment(['instructor_ids' => [3, 7, 12]]);

    runAllocation($payment->id);

    // §9.4: the release job acquires this row FOR UPDATE, and a row that does
    // not exist cannot be locked. Allocation itself takes no lock.
    $balances = InstructorBalance::query()->orderBy('instructor_id')->get();

    expect($balances)->toHaveCount(3);

    foreach ($balances as $balance) {
        expect($balance->recognized_minor)->toBe(0)
            ->and($balance->available_minor)->toBe(0)
            ->and($balance->reserved_minor)->toBe(0)
            ->and($balance->paid_minor)->toBe(0)
            ->and($balance->recognized_through_at)->toBeNull();
    }

    // A second payment sharing instructors must not duplicate or reset them.
    $second = allocatableTestPayment(['instructor_ids' => [7, 12, 99]]);
    runAllocation($second->id);

    expect(InstructorBalance::query()->count())->toBe(4);
});

it('inv-01: allocations plus the frozen platform cut equal the payment, read back from the database', function () {
    // The arithmetic half of this invariant is pinned in Inv01_MoneyTest. What
    // is under test here is the round trip: unsigned columns, the JSON
    // instructor set, the frozen cut, and the sum as MySQL reports it.
    mt_srand(2_000_001);

    $cases = [];

    // Grosses and rates chosen so neither division comes out even.
    foreach ([1, 7, 10_001, 10_003, 35_001] as $gross) {
        foreach ([1, 333, 2_000, 9_999] as $bps) {
            foreach ([1, 3, 7] as $count) {
                $cases[] = [$gross, $bps, $count];
            }
        }
    }

    for ($i = count($cases); $i < 200; $i++) {
        $cases[] = [mt_rand(1, 100_000_000), mt_rand(0, 10_000), mt_rand(1, 12)];
    }

    $subscription = Subscription::factory()->create();

    foreach ($cases as $index => [$gross, $bps, $count]) {
        // Ids neither 1..n nor sorted, so the §5.5 tie-break is exercised
        // against arbitrary keys on the way through the JSON column.
        $ids = [];
        while (count($ids) < $count) {
            $ids[mt_rand(1, 500)] = true;
        }
        $ids = array_keys($ids);
        shuffle($ids);

        $payment = app(InitiatePaymentService::class)->initiate(
            $subscription,
            $gross,
            $bps,
            $ids,
            'scripted',
            allocationTestInstant('2026-01-01 00:00:00'),
            allocationTestInstant('2026-04-01 00:00:00'),
        );

        app(ConfirmPaymentService::class)->confirm(
            $payment->client_idempotency_key,
            "ref_inv01_{$index}",
            allocationTestInstant('2026-01-01 10:00:00'),
        );

        // Everything below is read back out of the database, not carried over
        // from the objects above.
        $stored = DB::table('subscription_payments')->where('id', $payment->id)->first();
        $sum = (int) DB::table('revenue_allocations')->where('payment_id', $payment->id)->sum('amount_minor');
        $rows = DB::table('revenue_allocations')->where('payment_id', $payment->id)->count();

        $context = "gross={$gross} bps={$bps} instructors={$count}";

        // §5.5: asserted, not assumed — exactly, no tolerance.
        expect($sum + (int) $stored->platform_cut_minor)->toBe((int) $stored->amount_minor, $context)
            // §8 precondition 1: the allocator distributes the whole pool.
            ->and($sum)->toBe((int) $stored->amount_minor - (int) $stored->platform_cut_minor, $context)
            // One immutable row per distinct instructor (§5.1).
            ->and($rows)->toBe($count, $context);
    }
});
