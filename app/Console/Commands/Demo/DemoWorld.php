<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Domain\Allocation\AllocationService;
use App\Domain\Payment\ConfirmPaymentService;
use App\Domain\Payment\InitiatePaymentService;
use App\Domain\Payout\BatchService;
use App\Domain\Payout\ClaimResult;
use App\Domain\Payout\ClaimService;
use App\Domain\Recognition\ReleaseService;
use App\Domain\Refund\RecordRefundService;
use App\Models\PayoutBatch;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * The state every §11.4 demo needs, built the way production builds it.
 *
 * Nothing here inserts a ledger entry, a payout or a balance move by hand.
 * Every piece of state comes out of the same services the money path uses —
 * InitiatePaymentService, ConfirmPaymentService, AllocationService,
 * ReleaseService, ClaimService, RecordRefundService — because a demo that
 * forges the setup and then narrates the recovery is a demo of the narration.
 *
 * Demo scaffolding, not domain: it orchestrates and it prints nothing. No
 * decision about money is taken in this file.
 */
final class DemoWorld
{
    /**
     * Demo instructors live above this line so they are visually separable from
     * DemoSeeder's 1..20 on screen, and so a demo re-run never collides with a
     * previous run's UNIQUE(instructor_id, type, source_ref).
     */
    private const INSTRUCTOR_FLOOR = 900_000;

    /**
     * §10.1: a batch freezes max_entry_id at creation and never re-freezes it,
     * so a demo re-run must open its own period — reusing one would find its
     * fresh entries above the frozen high-water mark and claim nothing.
     */
    private const PERIOD_FLOOR = '2023-01';

    /** §3.3: all timestamps are stored and compared in UTC. */
    public static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /** An instructor id no row in this database has used yet. */
    public function freshInstructorId(): int
    {
        $highest = max(
            (int) DB::table('instructor_balances')->max('instructor_id'),
            (int) DB::table('ledger_entries')->max('instructor_id'),
            (int) DB::table('payouts')->max('instructor_id'),
            self::INSTRUCTOR_FLOOR,
        );

        return $highest + 1;
    }

    /** The first posting period from the demo floor that has no batch yet. */
    public function freshMonth(): string
    {
        $month = self::utc(self::PERIOD_FLOOR.'-01 00:00:00');

        while (DB::table('payout_batches')->where('period_start', $month->format('Y-m-d'))->exists()) {
            $month = $month->modify('+1 month');
        }

        return $month->format('Y-m');
    }

    /**
     * One subscription, paid for, confirmed and allocated — the whole inbound
     * half of §2's pipeline, through the real services.
     *
     * @param  array<int, int>  $instructorIds
     */
    public function paidSubscription(
        array $instructorIds,
        int $amountMinor,
        int $platformRateBps,
        DateTimeImmutable $termStart,
        int $termDays,
        string $planCode = 'quarterly',
    ): SubscriptionPayment {
        // §4: terms are [starts_at, ends_at) — end exclusive, and term length is
        // derived from the dates rather than from a plan constant.
        $termEnd = $termStart->modify("+{$termDays} days");

        $subscription = Subscription::query()->create([
            'student_id' => random_int(1, 10_000),
            'plan_code' => $planCode,
            'amount_minor' => $amountMinor,
            // §3.2: a single settlement currency, stored where money enters.
            'currency' => 'EGP',
            'starts_at' => $termStart,
            'ends_at' => $termEnd,
            'cancelled_at' => null,
            'access_ends_at' => null,
            'status' => 'active',
        ]);

        // §5.1: the payment row is written before the charge goes out, carrying
        // a key we generated ourselves.
        $payment = app(InitiatePaymentService::class)->initiate(
            $subscription,
            $amountMinor,
            $platformRateBps,
            $instructorIds,
            'demo',
            $termStart,
            $termEnd,
        );

        // §5.1: money in is confirmed exactly once. This dispatches
        // AllocatePaymentRevenue with afterCommit(); the demos run on a null
        // queue so every step stays visible, so the allocation is run here
        // rather than left on a queue nobody drains.
        app(ConfirmPaymentService::class)->confirm(
            $payment->client_idempotency_key,
            'demo_ref_'.$payment->id,
            $termStart,
        );

        app(AllocationService::class)->allocate($payment->id);

        return $payment->refresh();
    }

    /**
     * §6.2: the scheduled release for one posting period, for one instructor.
     * `release:run` is this, fanned out over every balance row.
     */
    public function release(int $instructorId, string $month): void
    {
        app(ReleaseService::class)->releaseScheduled($instructorId, $this->horizon($month));
    }

    /** §6.2: the target horizon `release:run --month=` computes for a period. */
    public function horizon(string $month): DateTimeImmutable
    {
        return self::utc($month.'-01 00:00:00')->modify('last day of this month');
    }

    /** §10.1: get-or-create, keyed on the period. Opening it freezes the snapshot. */
    public function batch(string $month): PayoutBatch
    {
        $start = self::utc($month.'-01 00:00:00');

        return app(BatchService::class)->openFor($start, $start->modify('last day of this month'));
    }

    /** §10.3 / §10.4: the claim transaction, reported in full. */
    public function claim(int $instructorId, PayoutBatch $batch): ClaimResult
    {
        return app(ClaimService::class)->claimResult($instructorId, $batch);
    }

    /** §7: a refund event, its access policy and the targeted recompute it dispatches. */
    public function refund(
        SubscriptionPayment $payment,
        int $amountMinor,
        string $kind,
        DateTimeImmutable $effectiveAt,
    ): Refund {
        return app(RecordRefundService::class)->record(
            $payment->id,
            $amountMinor,
            $kind,
            'demo scenario',
            $effectiveAt,
            'demo',
            'demo_refund_'.$payment->id.'_'.$kind.'_'.$effectiveAt->format('Ymd'),
            $effectiveAt,
        );
    }
}
