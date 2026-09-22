<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payout\ClaimService;
use App\Models\PayoutBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * §10.3 / §10.4: one payout claim for one instructor, in one batch.
 *
 * The job is fanned out per instructor and holds one serialisation point at a
 * time, so the critical section is per-instructor and short (§9.4, §17).
 *
 * It carries two ids and nothing else. cutoff_at and max_entry_id are read back
 * from the batch row inside handle(), never carried on the payload — they are
 * the frozen snapshot (§10.1), and a job replayed from a stale payload could
 * otherwise claim against a cutoff the batch never had.
 *
 * Deliberately NOT ShouldBeUnique: §9.1 calls the Redis lock an optimisation and
 * the constraint the guarantee. Here the guarantees are the balance lock and
 * UNIQUE(batch_id, instructor_id), neither of which can expire mid-operation.
 */
final class ClaimInstructorPayout implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $instructorId,
        public readonly int $batchId,
    ) {}

    public function handle(ClaimService $claim): void
    {
        $batch = PayoutBatch::query()->find($this->batchId);

        // A batch row is never deleted (§14, "no cascading deletes, ever"), so
        // this is only reachable for an id that never existed. Nothing to claim
        // against, and no snapshot to invent.
        if ($batch === null) {
            return;
        }

        $claim->claim($this->instructorId, $batch);
    }
}
