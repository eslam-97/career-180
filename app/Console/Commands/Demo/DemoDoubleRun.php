<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Jobs\ClaimInstructorPayout;
use App\Jobs\ReleaseInstructorRecognition;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutBatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * §11.4: "Command run twice → second violates UNIQUE(batch_id, instructor_id) →
 * one payout", and the row under it: "Claim job lost → re-run `payouts:run`; it
 * reuses the period's batch and frozen snapshot → paid in the same batch".
 *
 * The two rows are the same mechanism seen from opposite ends. Re-running a
 * period is not an accident to be defended against, it is the RECOVERY PATH —
 * which is only true if running it twice is indistinguishable from running it
 * once. Invariants 25, 26 and 28.
 */
final class DemoDoubleRun extends DemoCommand
{
    protected $signature = 'demo:double-run';

    protected $description = '§11.4: running release:run and payouts:run twice for the same period produces one release row and one payout';

    /** §9.1: ER_DUP_ENTRY — the constraint behind the lock, seen directly. */
    private const ERR_DUPLICATE = 1062;

    protected function matrixRow(): string
    {
        return 'Command run twice → second violates UNIQUE(batch_id, instructor_id) → one payout';
    }

    protected function scenario(): void
    {
        $instructorId = $this->world->freshInstructorId();
        $month = $this->world->freshMonth();

        $this->setUp($instructorId);

        $this->releaseTwice($instructorId, $month);
        $before = $this->buckets($instructorId, 'After recognition, before the payout run');

        $batch = $this->payoutRunTwice($instructorId, $month);

        $this->buckets($instructorId, 'After both payout runs', $before);
        $this->payouts($instructorId);
        $this->entries($instructorId);

        $this->theConstraintUnderneath($batch, $instructorId);

        $this->moneyOutcome('one payout — the second run of each command wrote nothing');
    }

    /** One paid subscription, so there is something real to recognize. */
    private function setUp(int $instructorId): void
    {
        $this->step('Setup: one 90-day subscription at 900.00, paid and allocated');

        $payment = $this->world->paidSubscription(
            [$instructorId],
            amountMinor: 90_000,
            platformRateBps: 2_000,
            termStart: DemoWorld::utc('2023-01-01 00:00:00'),
            termDays: 90,
        );

        $this->say(sprintf(
            'payment %d · gross %s · platform cut %s (2000 bps, frozen) · instructor pool %s',
            $payment->id,
            DemoLedger::money($payment->amount_minor),
            DemoLedger::money($payment->platform_cut_minor),
            DemoLedger::money($payment->amount_minor - $payment->platform_cut_minor),
        ));
    }

    /** Invariant 26: running the release job twice for a posting period posts one release row. */
    private function releaseTwice(int $instructorId, string $month): void
    {
        $horizon = $this->world->horizon($month)->format('Y-m-d H:i:s');

        foreach ([1, 2] as $run) {
            $this->step("release:run --month={$month}  (run {$run} of 2)");

            $this->call('release:run', ['--month' => $month]);

            $this->note('the queue here is a null queue, so the fanned-out job is run below, by hand');
            $this->runJob(new ReleaseInstructorRecognition($instructorId, $horizon));

            $releases = LedgerEntry::query()
                ->where('instructor_id', $instructorId)
                ->whereIn('type', ['release', 'release_correction'])
                ->count();

            $this->checkEquals("invariant 26  one release row after run {$run}", 1, $releases);
        }

        $this->say('Run 2 posts nothing for two independent reasons: the watermark guard makes');
        $this->say('T <= W a no-op (§6.2, Hazard B), and UNIQUE(instructor_id, type, source_ref)');
        $this->say("refuses a second row keyed 'period:{$month}' even if the guard were removed.");
    }

    /** Invariants 25 and 28: one payout per instructor per batch, over an identical entry set. */
    private function payoutRunTwice(int $instructorId, string $month): PayoutBatch
    {
        $snapshots = [];
        $claimed = [];

        foreach ([1, 2] as $run) {
            $this->step("payouts:run --month={$month}  (run {$run} of 2)");

            $this->call('payouts:run', ['--month' => $month]);

            $this->note('again the fanned-out claim job is run here rather than left on the null queue');
            $this->runJob(new ClaimInstructorPayout($instructorId, $this->world->batch($month)->id));

            $batch = $this->world->batch($month);

            // §10.1: an existing batch is returned untouched. Re-freezing
            // cutoff_at or max_entry_id on a replay would make the batch claim a
            // different entry set than it did the first time.
            $snapshots[$run] = [
                'batch' => (int) $batch->id,
                'cutoff' => $batch->cutoff_at->format('Y-m-d H:i:s'),
                'max_entry' => $batch->max_entry_id,
            ];

            $claimed[$run] = LedgerEntry::query()
                ->where('instructor_id', $instructorId)
                ->whereNotNull('payout_id')
                ->orderBy('id')
                ->pluck('payout_id', 'id')
                ->all();

            $payouts = Payout::query()->where('instructor_id', $instructorId)->count();

            $this->checkEquals("invariant 25  one payout row after run {$run}", 1, $payouts);
        }

        $this->newLine();
        $this->table(
            ['run', 'batch id', 'cutoff_at (frozen)', 'max_entry_id (frozen)'],
            [
                ['1', (string) $snapshots[1]['batch'], $snapshots[1]['cutoff'], (string) $snapshots[1]['max_entry']],
                ['2', (string) $snapshots[2]['batch'], $snapshots[2]['cutoff'], (string) $snapshots[2]['max_entry']],
            ],
            'box',
        );

        $this->check('§10.1  the second run reused the period\'s batch, snapshot untouched', $snapshots[1] === $snapshots[2]);
        $this->check('invariant 28  replaying the batch claimed an identical entry set', $claimed[1] === $claimed[2]);
        $this->say('The claim on run 2 read the existing payout under the balance lock and returned it');
        $this->say('as `existing`, not `created` — so no second attempt was dispatched for it either.');

        return $this->world->batch($month);
    }

    /**
     * §9.1: "constraints are correctness; locks are throughput". The lock is
     * what made run 2 a clean no-op; this is what would have stopped it anyway.
     */
    private function theConstraintUnderneath(PayoutBatch $batch, int $instructorId): void
    {
        $this->step('§9.1  And underneath the lock, the constraint — asked directly');

        $errorCode = null;

        try {
            // A second payout row for the same (batch, instructor), inserted
            // past every service in the system. The database refuses it.
            DB::table('payouts')->insert([
                'batch_id' => $batch->id,
                'instructor_id' => $instructorId,
                'amount_minor' => 1,
                'status' => 'pending',
                'attempt_count' => 0,
                'settled_at' => null,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
        } catch (QueryException $e) {
            $errorCode = (int) ($e->errorInfo[1] ?? 0);
        }

        $this->checkEquals('UNIQUE(batch_id, instructor_id) refused the duplicate (ER_DUP_ENTRY)', self::ERR_DUPLICATE, (int) $errorCode);
        $this->checkEquals('still exactly one payout for this instructor', 1, Payout::query()->where('instructor_id', $instructorId)->count());
    }
}
