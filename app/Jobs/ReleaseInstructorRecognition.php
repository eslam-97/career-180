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
 * §6.2: one scheduled release for one instructor.
 *
 * The job is chunked by instructor and holds one serialisation point at a time,
 * so the critical section is per-instructor and short (§9.4, §17).
 *
 * It carries the instructor id and the target horizon and nothing else —
 * allocations, terms, access_ends_at and the watermark are all read back inside
 * the locked transaction, so a job lost to a crashed worker can be re-dispatched
 * from the database alone.
 *
 * Deliberately NOT ShouldBeUnique. §9.1 calls the Redis lock an optimisation and
 * the constraint the guarantee; a lock here would let invariant 26 pass because
 * of something that can expire mid-operation rather than because of the
 * watermark guard and UNIQUE(instructor_id, type, source_ref).
 */
final class ReleaseInstructorRecognition implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * §3.3: the horizon travels as a UTC string rather than a DateTime, so what
     * is on the queue is exactly what comes back off it.
     */
    public function __construct(
        public readonly int $instructorId,
        public readonly string $through,
    ) {}

    public function handle(ReleaseService $release): void
    {
        $release->releaseScheduled(
            $this->instructorId,
            new DateTimeImmutable($this->through, new DateTimeZone('UTC')),
        );
    }
}
