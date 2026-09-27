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
 * §10.3: "COMMIT — then, and only then — acquire slot + create attempt (§10.2),
 * call provider". This job is where that happens: the claim transaction commits,
 * and then — outside it, with afterCommit() (§9.2) — the attempt job is queued.
 * That is the normal path, and the only one that pays an instructor promptly.
 *
 * It is dispatched for a payout THIS invocation created, and for nothing else.
 * A replay finds the batch's existing payout (§11.4) and returns its id, which
 * says nothing about whether an attempt is already in flight for it; queueing
 * on that would put a second attempt job behind every rerun of `payouts:run`.
 * The §10.2 gate would refuse it, but the gate is the backstop, not the
 * mechanism. ClaimResult carries the answer out of the transaction instead.
 *
 * `payouts:sweep-stranded` (§10.7) stays, and stays a RECOVERY mechanism: the
 * committed payout whose attempt dispatch was lost, the worker that died before
 * the §10.2 transaction, the retry dispatch that vanished. It is not what
 * ordinarily creates the attempt, and a design where it is, is a design where
 * every payout waits a sweeper tick and the recovery path is never exercised
 * as one.
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

        // §10.3: the id only when this call created the payout. Null covers the
        // replay (§11.4) and both no-payout cases — nothing eligible, and a
        // claimed sum of zero or less rolled back (§10.4) — so one branch is
        // enough and none of the three can start an attempt.
        $payoutId = $claim->claimResult($this->instructorId, $batch)->createdPayoutId();

        if ($payoutId === null) {
            return;
        }

        // §10.3: "COMMIT — then, and only then — acquire slot + create attempt
        // (§10.2), call provider". §9.2: afterCommit(), and outside any
        // transaction of ours — a dispatch inside the claim closure would fire
        // twice on a deadlock retry.
        SendPayoutAttempt::dispatch($payoutId)->afterCommit();
    }
}
