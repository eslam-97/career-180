<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Recognition\ReleaseService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * §6.2: the other caller in the two-caller table — the event correction, where
 * t = W and the watermark is held. ReleaseInstructorRecognition is its twin for
 * the scheduled run.
 *
 * §6.1: "a refund event dispatches a targeted recompute for the affected
 * instructors rather than waiting for the next monthly release run. Otherwise a
 * payout could go out in a window where the refund exists but the corresponding
 * correction is not yet in the ledger."
 *
 * One job per instructor, so the critical section stays per-instructor and short
 * (§9.4, §17). It carries the instructor id, the event key and the event's
 * business date and nothing else — the watermark, the allocations and the newly
 * shortened access_ends_at are all read back inside the locked transaction, so a
 * job lost to a crashed worker can be re-dispatched from the database alone.
 *
 * Deliberately NOT ShouldBeUnique, for the same reason as its twin: §9.1 calls
 * the Redis lock an optimisation and the constraint the guarantee, and
 * UNIQUE(instructor_id, type, source_ref) is what makes a refund processed twice
 * post one correction.
 */
final class CorrectInstructorRecognition implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * §6.2: $sourceRef keys on the EVENT ('refund:9812'), never on the period.
     *
     * §3.3: $effectiveAt travels as a UTC string rather than a DateTime, so what
     * is on the queue is exactly what comes back off it.
     */
    public function __construct(
        public readonly int $instructorId,
        public readonly string $sourceRef,
        public readonly string $effectiveAt,
    ) {}

    public function handle(ReleaseService $release): void
    {
        $release->correct(
            $this->instructorId,
            $this->sourceRef,
            new DateTimeImmutable($this->effectiveAt, new DateTimeZone('UTC')),
        );
    }
}
