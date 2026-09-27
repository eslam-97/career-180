<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Domain\Payout\AttemptService;
use App\Domain\Payout\ClaimService;
use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Scenario;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\PayoutBatch;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * §11.4: "Two servers concurrently → balance lock serialises; claim affects
 * zero rows → one payout", and "Two workers claiming attempt #2 → payout lock;
 * loser aborts without incrementing → one attempt in flight".
 *
 * §16.2: "concurrency needs real concurrency". Running the same job twice in a
 * row passes trivially and proves nothing, so this demo drives TWO REAL
 * DATABASE CONNECTIONS in a controlled order — the same technique the
 * concurrency suite uses. `mysql` holds; `mysql_b` runs with
 * `innodb_lock_wait_timeout = 1`, so a genuine block surfaces as a fast,
 * deterministic 1205 instead of a hang.
 *
 * The distinction §9.1 draws is the point of the whole demo: **constraints are
 * correctness, locks are throughput**. The lock is what makes the loser a clean
 * no-op; the unique key is what would stop it even if the lock were gone. Both
 * are shown. Invariants 18, 19 and 25.
 */
final class DemoConcurrent extends DemoCommand
{
    protected $signature = 'demo:concurrent';

    protected $description = '§11.4: two servers claim the same instructor at the same moment — one blocks on the balance lock, and one payout exists';

    /** MySQL's lock wait timeout. A genuine block, not an error. */
    private const ER_LOCK_WAIT_TIMEOUT = 1205;

    protected function matrixRow(): string
    {
        return 'Two servers concurrently → balance lock serialises; claim affects zero rows → one payout';
    }

    protected function scenario(): void
    {
        $instructorId = $this->world->freshInstructorId();
        $month = $this->world->freshMonth();

        $this->setUp($instructorId);
        $batch = $this->world->batch($month);

        $this->theClaimBlocks($instructorId, $batch);
        $payout = $this->theClaimSerialises($instructorId, $batch);

        $this->theSlotBlocks($payout);
        $this->theSlotGateRefuses($instructorId, $payout);

        $this->moneyOutcome('one payout, one attempt in flight, one transfer');
    }

    private function setUp(int $instructorId): void
    {
        $this->step('Setup: one 90-day subscription at 900.00, released for its first month');

        $this->world->paidSubscription(
            [$instructorId],
            amountMinor: 90_000,
            platformRateBps: 2_000,
            termStart: DemoWorld::utc('2023-01-01 00:00:00'),
            termDays: 90,
        );

        $this->world->release($instructorId, '2023-01');

        $this->buckets($instructorId, 'Recognized and available');
    }

    /** §9.4: the per-instructor serialisation point, proven to exist. */
    private function theClaimBlocks(int $instructorId, PayoutBatch $batch): void
    {
        $this->step('Server A takes the balance lock. Server B runs the real claim, and blocks.');

        $a = $this->connA();
        $this->connB();

        $a->beginTransaction();

        // The FIRST statement of ClaimService's transaction, and nothing else —
        // so what B collides with below is genuinely §9.4's lock and not some
        // side effect of a half-finished claim.
        $a->table('instructor_balances')->where('instructor_id', $instructorId)->lockForUpdate()->first();

        $blocked = null;

        try {
            // A complete, real claim on a second connection. Not a bare SELECT:
            // the claim is what must block, so the claim is what is run.
            (new ClaimService('mysql_b'))->claimResult($instructorId, $batch);
        } catch (QueryException $e) {
            $blocked = (int) ($e->errorInfo[1] ?? 0);
        }

        // Nothing was written by A, which is what makes the block meaningful:
        // B waited on the lock itself, not on a row A had already changed.
        $a->rollBack();

        $this->checkEquals('§9.4  server B blocked on the balance row (lock wait timeout)', self::ER_LOCK_WAIT_TIMEOUT, (int) $blocked);
        $this->checkEquals('and B wrote nothing while it was blocked', 0, Payout::query()->where('instructor_id', $instructorId)->count());
        $this->note('A held the lock without writing a thing — so the block is the lock, not a conflict over data');
        $this->note('this is why a lock test needs a non-writing holder; otherwise it passes for the wrong reason');
    }

    /** §9.3: ownership decided by affected-row count. The loser learns it lost from the count. */
    private function theClaimSerialises(int $instructorId, PayoutBatch $batch): Payout
    {
        $this->step('A commits. B runs again — and finds the work already done.');

        $resultA = (new ClaimService)->claimResult($instructorId, $batch);
        $resultB = (new ClaimService('mysql_b'))->claimResult($instructorId, $batch);

        $this->table(
            ['server', 'payout id', 'reported as'],
            [
                ['A (mysql)', (string) $resultA->payoutId, $resultA->created ? 'created' : 'existing'],
                ['B (mysql_b)', (string) $resultB->payoutId, $resultB->created ? 'created' : 'existing'],
            ],
            'box',
        );

        $this->payouts($instructorId);

        $this->check('server A created the payout', $resultA->created);
        $this->check('server B found the same payout, and reported it as existing', ! $resultB->created && $resultB->payoutId === $resultA->payoutId);
        $this->checkEquals('invariant 25  exactly one payout row', 1, Payout::query()->where('instructor_id', $instructorId)->count());
        $this->say('§10.3: only the branch that INSERTED the row may report `created`, and the flag');
        $this->say('leaves the transaction as its return value. So only A dispatches an attempt —');
        $this->say('B cannot put a second transfer behind a payout that may already be in flight.');

        return Payout::query()->findOrFail($resultA->payoutId);
    }

    /** Invariant 19: an interrupted slot acquisition leaves no attempt and no increment. */
    private function theSlotBlocks(Payout $payout): void
    {
        $this->step('Two workers now want attempt #1. A holds the locks; B blocks.');

        $a = $this->connA();
        $this->connB();

        $a->beginTransaction();
        $a->table('instructor_balances')->where('instructor_id', $payout->instructor_id)->lockForUpdate()->first();

        $blocked = null;

        try {
            (new AttemptService('mysql_b'))->acquire($payout->id);
        } catch (QueryException $e) {
            $blocked = (int) ($e->errorInfo[1] ?? 0);
        }

        $a->rollBack();

        $this->checkEquals('§9.4  worker B blocked before it could touch the counter', self::ER_LOCK_WAIT_TIMEOUT, (int) $blocked);
        $this->checkEquals('invariant 19  attempt_count was NOT incremented', 0, (int) Payout::query()->find($payout->id)->attempt_count);
        $this->checkEquals('and no attempt row was left behind', 0, PayoutAttempt::query()->where('payout_id', $payout->id)->count());
        $this->note('§11.1: the increment and the insert are one transaction, so an interrupted worker leaves neither');
    }

    /** §10.2: the gate does the work; UNIQUE(active_payout_id) stays the backstop. */
    private function theSlotGateRefuses(int $instructorId, Payout $payout): void
    {
        $this->step('A wins the slot. B tries again — and the gate refuses it.');

        $provider = $this->scripted(Scenario::Success);

        $slotA = app(AttemptService::class)->acquire($payout->id);
        $slotB = (new AttemptService('mysql_b'))->acquire($payout->id);

        $this->attempts($payout->id);

        $this->check('worker A acquired the slot', $slotA !== null);
        $this->check('worker B was refused — an attempt for this payout is already live', $slotB === null);

        if ($slotA === null) {
            return;
        }

        $this->checkEquals('invariant 18  at most one attempt in a non-terminal state', 1, PayoutAttempt::query()
            ->where('payout_id', $payout->id)
            ->whereIn('status', ['sending', 'unknown'])
            ->count());
        $this->checkEquals('invariant 19  attempt_count is 1, not 2 — the loser exited without incrementing', 1, (int) Payout::query()->find($payout->id)->attempt_count);

        $this->say('A condition on the payout\'s own status could not give this: `in_progress` satisfies');
        $this->say('such a predicate for both workers, so the loser would increment first and only');
        $this->say('then discover it could not insert. The gate is the mechanism; the constraint is');
        $this->say('the backstop.');

        $this->step('A finishes the transfer it won the right to make');

        // §10.3: outside the slot transaction, always. This is what
        // SendPayoutAttempt does; it is spelled out here because the slot above
        // was acquired by hand to keep both workers on screen at once.
        $outcome = $provider->send($slotA->amountMinor, $slotA->idempotencyKey);
        app(SettlementService::class)->recordSuccess($slotA->id, $outcome);

        $this->payouts($instructorId);
        $this->buckets($instructorId, 'Settled: reserved → paid');

        $this->check("the payout is 'settled'", Payout::query()->find($payout->id)->status === 'settled');
        $this->checkEquals('exactly one transfer, on the provider\'s side', 1, $provider->transferCount($slotA->idempotencyKey));
    }

    /** Connection A — the one that holds. */
    private function connA(): Connection
    {
        return DB::connection('mysql');
    }

    /** Connection B — the one that must block, and fail fast when it does. */
    private function connB(): Connection
    {
        $b = DB::connection('mysql_b');

        // §16.2: one second, so a genuine block is a fast deterministic
        // exception rather than fifty seconds of dead air on the recording.
        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $b;
    }
}
