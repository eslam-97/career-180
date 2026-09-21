<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Allocation\AllocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * §5.1: dispatched with afterCommit() once a payment is confirmed.
 *
 * It carries the payment id and nothing else. The instructor set, the frozen
 * rate and the frozen cut are all read back from the row, so a job lost to a
 * crashed worker can be re-dispatched from the database alone.
 *
 * Deliberately NOT ShouldBeUnique. §9.1 calls the Redis lock an optimisation and
 * the constraint the guarantee; adding one here would let the "run the job
 * twice" test pass because of a lock that can expire mid-operation rather than
 * because of UNIQUE(payment_id, instructor_id).
 */
final class AllocatePaymentRevenue implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $paymentId) {}

    public function handle(AllocationService $allocations): void
    {
        $allocations->allocate($this->paymentId);
    }
}
