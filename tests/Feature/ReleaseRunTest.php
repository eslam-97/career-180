<?php

// ARCHITECTURE.md §6.2, §6.4, §9.2 — the scheduled release command and its job.

use App\Jobs\ReleaseInstructorRecognition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecognitionFixtures;

/**
 * §6.2's Hazard B example: 61-day term, two allocations of 102.
 *   expected(31 Mar) = 100, expected(30 Apr) = 200.
 */
function releaseRunInstructor(int $instructorId): void
{
    $termStart = RecognitionFixtures::utc('2026-03-01 00:00:00');
    $termEnd = RecognitionFixtures::utc('2026-05-01 00:00:00');

    RecognitionFixtures::allocation($instructorId, 102, $termStart, $termEnd);
    RecognitionFixtures::allocation($instructorId, 102, $termStart, $termEnd);
}

it('posts one release per instructor for the named posting period', function () {
    releaseRunInstructor(7);
    releaseRunInstructor(12);

    // QUEUE_CONNECTION is sync in the test environment, so the dispatched jobs
    // run inline and the whole command-to-ledger path is exercised.
    $this->artisan('release:run', ['--month' => '2026-03'])->assertExitCode(0);

    expect(RecognitionFixtures::posted(7))->toBe(100)
        ->and(RecognitionFixtures::posted(12))->toBe(100);

    foreach ([7, 12] as $instructorId) {
        $entry = RecognitionFixtures::entries($instructorId)->sole();

        // §6.2: the scheduled run keys on the posting period.
        expect($entry->source_ref)->toBe('period:2026-03')
            ->and($entry->type)->toBe('release')
            // §6.3: period_start is the posting period.
            ->and($entry->period_start->format('Y-m-d'))->toBe('2026-03-01')
            ->and($entry->recognized_through_at->format('Y-m-d'))->toBe('2026-03-31');

        expect(RecognitionFixtures::balance($instructorId)->recognized_through_at->format('Y-m-d'))
            ->toBe('2026-03-31');
    }
});

it('is idempotent when the same period is run twice', function () {
    releaseRunInstructor(7);

    $this->artisan('release:run', ['--month' => '2026-03'])->assertExitCode(0);
    $this->artisan('release:run', ['--month' => '2026-03'])->assertExitCode(0);

    // Invariant 26 again, this time through the command rather than the service.
    expect(RecognitionFixtures::entries(7))->toHaveCount(1)
        ->and(RecognitionFixtures::posted(7))->toBe(100);
});

it('heals a missed month on the next run without a backfill path', function () {
    releaseRunInstructor(7);

    // March never runs.
    $this->artisan('release:run', ['--month' => '2026-04'])->assertExitCode(0);

    // §6.2: cumulative delta — April's row carries March's recognition too.
    expect(RecognitionFixtures::posted(7))->toBe(200)
        ->and(RecognitionFixtures::entries(7)->sole()->source_ref)->toBe('period:2026-04');
});

it('defaults to the month that just ended', function () {
    releaseRunInstructor(7);

    // Scheduled on the 1st, so a run today closes last month.
    Carbon::setTestNow('2026-04-01 02:00:00');

    $this->artisan('release:run')->assertExitCode(0);

    expect(RecognitionFixtures::entries(7)->sole()->source_ref)->toBe('period:2026-03')
        ->and(RecognitionFixtures::posted(7))->toBe(100);
});

it('dispatches one job per instructor, after commit', function () {
    Queue::fake();

    releaseRunInstructor(7);
    releaseRunInstructor(12);

    $this->artisan('release:run', ['--month' => '2026-03'])->assertExitCode(0);

    // §6.2 / §17: chunked by instructor, one lock held at a time — which means
    // one job per instructor rather than one job for the batch.
    Queue::assertPushed(ReleaseInstructorRecognition::class, 2);

    foreach ([7, 12] as $instructorId) {
        Queue::assertPushed(
            ReleaseInstructorRecognition::class,
            fn (ReleaseInstructorRecognition $job): bool => $job->instructorId === $instructorId
                && $job->through === '2026-03-31 00:00:00',
        );
    }
});

it('rejects a month it cannot parse rather than guessing', function () {
    $this->artisan('release:run', ['--month' => 'March'])->assertExitCode(1);
    $this->artisan('release:run', ['--month' => '2026-13'])->assertExitCode(1);
});
