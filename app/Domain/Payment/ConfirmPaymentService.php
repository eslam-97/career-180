<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Jobs\AllocatePaymentRevenue;
use App\Models\SubscriptionPayment;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * §5.1: the inbound side of the pipeline, second half — money in is confirmed
 * exactly once.
 *
 * The provider's reference arrives after the call and guards the other window:
 * a replayed webhook, or a settlement file reprocessed. Two different keys
 * because they cover two different failure windows, neither of which the other
 * can see.
 */
final class ConfirmPaymentService
{
    public function confirm(
        string $clientIdempotencyKey,
        string $providerReference,
        DateTimeImmutable $paidAt,
    ): SubscriptionPayment {
        /** @var array{0: int, 1: SubscriptionPayment} $result */
        $result = DB::transaction(function () use ($clientIdempotencyKey, $providerReference, $paidAt): array {
            // §9.3: ownership is decided by the affected-row count of a
            // conditional UPDATE. A replayed webhook matches zero rows because
            // the reference is already set, and learns it lost from the count —
            // no read-then-write, no second confirmation.
            $confirmed = SubscriptionPayment::query()
                ->where('client_idempotency_key', $clientIdempotencyKey)
                ->whereNull('provider_reference')
                ->update([
                    'provider_reference' => $providerReference,
                    'status' => 'paid',
                    'paid_at' => $paidAt,
                ]);

            $payment = SubscriptionPayment::query()
                ->where('client_idempotency_key', $clientIdempotencyKey)
                ->firstOrFail();

            return [$confirmed, $payment];
        });

        [$confirmed, $payment] = $result;

        // §9.2: DB::transaction() retries its closure on deadlock, so a dispatch
        // inside it could fire twice. It lives out here, and §5.1 requires
        // afterCommit() so a worker cannot pick the job up before the row it
        // depends on is visible.
        if ($confirmed === 1) {
            AllocatePaymentRevenue::dispatch($payment->id)->afterCommit();
        }

        return $payment;
    }
}
