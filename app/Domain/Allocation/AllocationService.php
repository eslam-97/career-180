<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Models\InstructorBalance;
use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * §5.1 / §5.2 / §5.3: split a confirmed payment across its instructors and
 * write one immutable revenue_allocations row each.
 *
 * "All allocations for one payment are written in a single transaction so a
 * partial set cannot exist" (§5.1). Payment uniqueness does not make this
 * idempotent — UNIQUE(payment_id, instructor_id) does, and that constraint is
 * the guarantee, not the Redis lock a job would otherwise lean on (§9.1).
 */
final class AllocationService
{
    // §9.1: ER_DUP_ENTRY. A retry finding its own previous work, which is
    // exactly what UNIQUE(payment_id, instructor_id) exists to produce.
    private const ERR_DUPLICATE = 1062;

    public function __construct(private readonly RevenueAllocator $allocator) {}

    public function allocate(int $paymentId): void
    {
        try {
            // The try wraps the transaction, never its body: DB::transaction()
            // retries its closure on deadlock, and the closure stays pure —
            // reads and writes only (§9.2).
            DB::transaction(function () use ($paymentId): void {
                $payment = SubscriptionPayment::query()->findOrFail($paymentId);

                // §5.1: allocation follows confirmation. Splitting an
                // unconfirmed payment would recognise money that never arrived.
                if (! $payment->isConfirmed()) {
                    throw new DomainException(
                        "Payment {$paymentId} is not confirmed; there is no money to allocate."
                    );
                }

                // §5.3: the cut is frozen on the row and never recomputed. The
                // pool is what is left of the gross — read back, not re-derived
                // through Bps, so a later rate change cannot move this payment.
                $pool = $payment->amount_minor - $payment->platform_cut_minor;

                // §5.2: the instructor set comes from the payment, not from the
                // job payload, so a lost job is re-dispatchable from the row.
                $allocations = $this->allocator->allocate($pool, $payment->instructor_ids);

                $this->ensureBalanceRows(array_keys($allocations));

                $rows = [];

                foreach ($allocations as $instructorId => $allocation) {
                    $rows[] = [
                        'payment_id' => $payment->id,
                        'instructor_id' => $instructorId,
                        // §5.3: the canonical entitlement, frozen here.
                        'amount_minor' => $allocation['amount_minor'],
                        // §3.1: the rule as an exact rational, for audit.
                        'weight_numerator' => $allocation['weight_numerator'],
                        'weight_denominator' => $allocation['weight_denominator'],
                        'created_at' => now()->utc(),
                    ];
                }

                // §5.1: one statement, so a duplicate fails the whole insert and
                // a partial set cannot exist. Not insertOrIgnore — a silently
                // skipped allocation row is money that quietly went nowhere.
                RevenueAllocation::query()->insert($rows);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== self::ERR_DUPLICATE) {
                throw $e;
            }

            // §5.1: "an allocation job retried against an already-confirmed
            // payment would allocate twice. That is guarded separately" — by
            // the unique key that just rejected this. The retry is a no-op.
            //
            // What makes it safe to swallow is §5.1's other half: the set is
            // written in one transaction, so a partial set cannot exist. That
            // is asserted rather than assumed, because the failure mode if it
            // ever were false is silent — a payment left under-allocated,
            // invariant 1 broken, and nothing downstream to notice.
            $this->assertAllocationSetIsComplete($paymentId);
        }
    }

    /**
     * §5.1: a duplicate means a previous run already wrote this payment's set.
     * Confirm that what is on disk is the whole set, at the frozen amounts,
     * before reporting success.
     */
    private function assertAllocationSetIsComplete(int $paymentId): void
    {
        $payment = SubscriptionPayment::query()->findOrFail($paymentId);

        // §5.3: the same frozen pool the write path used — never a live rate.
        $expected = [];

        foreach ($this->allocator->allocate(
            $payment->amount_minor - $payment->platform_cut_minor,
            $payment->instructor_ids,
        ) as $instructorId => $allocation) {
            $expected[(int) $instructorId] = $allocation['amount_minor'];
        }

        $stored = [];

        foreach (RevenueAllocation::query()->where('payment_id', $paymentId)->get() as $allocation) {
            $stored[(int) $allocation->instructor_id] = $allocation->amount_minor;
        }

        ksort($expected);
        ksort($stored);

        if ($stored !== $expected) {
            throw new DomainException(
                "Payment {$paymentId} already has an allocation set that is incomplete or does not match the "
                .'frozen split; refusing to report success on an under-allocated payment.'
            );
        }
    }

    /**
     * §9.4, "Allocation is outside this rule": allocation takes no FOR UPDATE.
     * It writes only revenue_allocations and every value it reads is frozen, so
     * there is no unlocked read that a write depends on and nothing to
     * serialise — UNIQUE(payment_id, instructor_id) is the whole guard.
     *
     * What it must do is make sure each instructor has a balance row, because
     * the release job's FOR UPDATE needs one to exist: a lock on a missing row
     * locks nothing.
     *
     * @param  array<int, int>  $instructorIds
     */
    private function ensureBalanceRows(array $instructorIds): void
    {
        // Ascending id, so two allocation jobs sharing instructors acquire the
        // same rows in the same order and cannot deadlock against each other.
        sort($instructorIds);

        $rows = [];

        foreach ($instructorIds as $instructorId) {
            $rows[] = [
                'instructor_id' => $instructorId,
                'recognized_minor' => 0,
                'available_minor' => 0,
                'reserved_minor' => 0,
                'paid_minor' => 0,
                'recognized_through_at' => null,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ];
        }

        // insertOrIgnore is normally a smell in this repo: MySQL's INSERT IGNORE
        // downgrades strict-mode errors to warnings, so an out-of-range amount
        // would be silently clamped to 0 instead of throwing. It is safe here
        // only because every value above is a constant zero, null or timestamp —
        // there is no money in these rows for strict mode to mangle, and the one
        // error being ignored is the duplicate primary key we are racing on.
        InstructorBalance::query()->insertOrIgnore($rows);
    }
}
