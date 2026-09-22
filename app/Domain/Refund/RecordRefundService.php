<?php

declare(strict_types=1);

namespace App\Domain\Refund;

use App\Jobs\CorrectInstructorRecognition;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * §6.1 / §7: record a refund event, apply its access policy, and dispatch the
 * targeted recompute.
 *
 * §7's two mechanisms both fall out of one write. Unearned money stops future
 * recognition, because a shortened access_ends_at shortens effective_days and
 * the money simply never becomes payable — no compensating entry at all.
 * Already-recognized money produces a negative ledger row, because the same
 * shortened cap makes expected(W) smaller than posted and §6.2's delta goes
 * negative on its own. Neither case is coded here; both are consequences of
 * moving one column.
 *
 * "A refund event dispatches a targeted recompute for the affected instructors
 * rather than waiting for the next monthly release run. Otherwise a payout could
 * go out in a window where the refund exists but the corresponding correction is
 * not yet in the ledger" (§6.1).
 */
final class RecordRefundService
{
    // §9.1: ER_DUP_ENTRY, from UNIQUE(provider, provider_reference) — a refund
    // webhook delivered twice, finding its own previous work.
    private const ERR_DUPLICATE = 1062;

    public function record(
        int $paymentId,
        int $amountMinor,
        string $kind,
        string $reason,
        DateTimeImmutable $effectiveAt,
        string $provider,
        string $providerReference,
        DateTimeImmutable $processedAt,
    ): Refund {
        try {
            // The try wraps the transaction, never its body: DB::transaction()
            // retries its closure on deadlock, and the closure stays pure —
            // reads and writes only (§9.2).
            /** @var array{0: Refund, 1: array<int, int>} $result */
            $result = DB::transaction(function () use (
                $paymentId,
                $amountMinor,
                $kind,
                $reason,
                $effectiveAt,
                $provider,
                $providerReference,
                $processedAt,
            ): array {
                $payment = SubscriptionPayment::query()->findOrFail($paymentId);

                // NOT a §9.4 lock. §9.4 names instructor_balances as the
                // serialisation point for transactions that read or write an
                // instructor's ledger entries, payouts or attempts, and this
                // transaction touches none of the three — it writes a refunds
                // row and one subscriptions column, and the ledger work happens
                // later, in the dispatched job, under that job's own lock.
                //
                // This lock serialises something else: two refunds landing on
                // one subscription at once would both read access_ends_at, both
                // compute the minimum below against the same stale value, and
                // the later writer would win — which is how a monotonic rule
                // stops being monotonic. Single row, and no transaction anywhere
                // takes it while holding a balance row, so it cannot close a
                // deadlock cycle with §9.4's order.
                $subscription = Subscription::query()
                    ->whereKey($payment->subscription_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // §9.1: UNIQUE(provider, provider_reference) is the guard, and a
                // plain insert is what lets it fire. NOT insertOrIgnore: MySQL's
                // INSERT IGNORE downgrades strict-mode errors to warnings, and
                // this row carries money (amount_minor) and an ENUM (kind), so
                // an out-of-range amount would be silently written as 0 and a
                // bad kind as ''. AllocationService can use insertOrIgnore only
                // because every value in its rows is a constant zero, null or
                // timestamp.
                $refund = Refund::query()->create([
                    'payment_id' => $payment->id,
                    // §3: magnitude only. The direction is implied by kind.
                    'amount_minor' => $amountMinor,
                    'kind' => $kind,
                    'reason' => $reason,
                    // §3.3: the refund's business date, not the date it was
                    // processed. It is also what §10.1 filters payouts by, so it
                    // is stored exactly as given — never clamped, however far
                    // outside the term it falls.
                    'effective_at' => $effectiveAt,
                    'provider' => $provider,
                    'provider_reference' => $providerReference,
                    'processed_at' => $processedAt,
                ]);

                // §6.1: the mapping from kind to access lives in the policy, so
                // the ledger never assumes a refund caps anything.
                $policyResult = RefundPolicy::accessEndsAt(
                    $kind,
                    $effectiveAt,
                    $subscription->starts_at,
                    $subscription->ends_at,
                );

                $instructorIds = $this->applyAccessCap($subscription, $policyResult)
                    // §5.2 / §5.3: the frozen instructor set, read from the
                    // payment row rather than from revenue_allocations, so a
                    // refund on a payment whose allocation job has not run yet
                    // still names the right instructors. Already sorted and
                    // distinct, which is also the ascending order §9.4 wants for
                    // the balance locks these jobs will take.
                    ? $payment->instructor_ids
                    : [];

                return [$refund, $instructorIds];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== self::ERR_DUPLICATE) {
                throw $e;
            }

            // §9.1: "guards a refund webhook replay". The whole transaction
            // rolled back, so the second delivery changed nothing — no second
            // refunds row, no second access change, and nothing dispatched
            // below, because we return from here.
            return $this->existingRefund($provider, $providerReference, $paymentId, $kind, $amountMinor, $effectiveAt);
        }

        [$refund, $instructorIds] = $result;

        // §9.2: DB::transaction() retries its closure on deadlock, so a dispatch
        // inside it could fire twice. It lives out here, and afterCommit() so a
        // worker cannot pick the job up before the shortened access_ends_at it
        // depends on is visible.
        foreach ($instructorIds as $instructorId) {
            CorrectInstructorRecognition::dispatch(
                (int) $instructorId,
                // §6.2: keyed on the event, never on the period — a refund
                // landing between two runs of the same month would otherwise
                // compute a nonzero delta it could not insert.
                'refund:'.$refund->id,
                // §3.3: the refund's own business date, unclamped. A refund
                // dated 15 March, processed 3 April, against a watermark of
                // 31 March posts effective_at 2026-03-15.
                $refund->effective_at->format('Y-m-d H:i:s'),
            )->afterCommit();
        }

        return $refund;
    }

    /**
     * §6.1: move access_ends_at, and nothing else. Returns whether it moved.
     *
     * A false return means expected(t) cannot have changed — it is a function of
     * the instructor's allocations and this column, and the allocations are
     * frozen (§5.3) — so §6.2 would compute delta == 0 and post nothing. The
     * recompute is skipped rather than dispatched to do nothing, which is also
     * what makes a goodwill refund quiet.
     */
    private function applyAccessCap(Subscription $subscription, ?DateTimeInterface $policyResult): bool
    {
        // §6.1: "Goodwill partial refund, access continues — recognition cap:
        // none". Nothing to move.
        if ($policyResult === null) {
            return false;
        }

        $current = $subscription->access_ends_at;

        // A refund may only ever SHORTEN access. Without this, a prorata refund
        // arriving after a full refund on the same subscription would push
        // access_ends_at back out, expected(t) would rise, and §6.2 would
        // dutifully re-release money the full refund had just clawed back.
        $next = $current !== null && $current < $policyResult ? $current : $policyResult;

        if ($current !== null && $current->getTimestamp() === $next->getTimestamp()) {
            return false;
        }

        // §6.1: "access termination is not cancellation". cancelled_at is not in
        // this payload and is never written by a refund — a student who
        // cancelled auto-renew and a student whose access was terminated are
        // different facts, and conflating them would cap recognition for the one
        // whose term is still running.
        Subscription::query()
            ->whereKey($subscription->getKey())
            ->update(['access_ends_at' => $next->format('Y-m-d H:i:s')]);

        return true;
    }

    /**
     * §9.1: the replay found its own previous work. Swallowing that is only safe
     * if it really is the same event, so the stored row is checked against the
     * one presented — the assertAllocationSetIsComplete() precedent. A provider
     * reusing a reference for a different refund is a far worse failure than a
     * duplicate, and it would otherwise be silent.
     */
    private function existingRefund(
        string $provider,
        string $providerReference,
        int $paymentId,
        string $kind,
        int $amountMinor,
        DateTimeImmutable $effectiveAt,
    ): Refund {
        $refund = Refund::query()
            ->where('provider', $provider)
            ->where('provider_reference', $providerReference)
            ->firstOrFail();

        $stored = [
            'payment_id' => (int) $refund->payment_id,
            'kind' => $refund->kind,
            'amount_minor' => $refund->amount_minor,
            'effective_at' => $refund->effective_at->format('Y-m-d H:i:s'),
        ];

        $presented = [
            'payment_id' => $paymentId,
            'kind' => $kind,
            'amount_minor' => $amountMinor,
            'effective_at' => $effectiveAt->format('Y-m-d H:i:s'),
        ];

        if ($stored !== $presented) {
            throw new DomainException(
                "Refund {$provider}:{$providerReference} is already recorded as refund {$refund->id} with "
                .'different details; refusing to treat a different refund as a replay of this one.'
            );
        }

        return $refund;
    }
}
