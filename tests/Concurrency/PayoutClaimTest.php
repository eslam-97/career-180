<?php

// Invariant 17 — ARCHITECTURE.md §10.3, §10.4
//
// The committed-state half lives in tests/Invariants/Inv04_PayoutClaimTest,
// which is bound to RefreshDatabase and so cannot see across connections. This
// is the half that needs two real ones: proving the NULL amount §10.4 requires
// as an intermediate is never visible to anybody else.

use App\Domain\Payout\BatchService;
use App\Domain\Payout\ClaimService;
use App\Models\LedgerEntry;
use App\Models\PayoutBatch;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\TwoConnections;
use Tests\Support\RecognitionFixtures;

uses(TwoConnections::class);

/**
 * A release entry plus the §10.5 cache move that posting it would have made.
 * Committed on connection A, because DatabaseTruncation means there is no
 * wrapping transaction here and connection B must be able to see it.
 */
function payoutClaimRelease(int $instructorId, int $amountMinor, string $sourceRef): LedgerEntry
{
    RecognitionFixtures::balanceRow($instructorId);

    $entry = LedgerEntry::query()->create([
        'instructor_id' => $instructorId,
        'type' => 'release',
        'amount_minor' => $amountMinor,
        'period_start' => '2026-03-01',
        'recognized_through_at' => '2026-03-31 00:00:00',
        'effective_at' => '2026-03-31 00:00:00',
        'source_ref' => $sourceRef,
        'payout_id' => null,
    ]);

    DB::table('instructor_balances')
        ->where('instructor_id', $instructorId)
        ->incrementEach(['recognized_minor' => $amountMinor, 'available_minor' => $amountMinor]);

    return $entry;
}

function payoutClaimBatch(): PayoutBatch
{
    return (new BatchService)->openFor(
        RecognitionFixtures::utc('2026-03-01 00:00:00'),
        RecognitionFixtures::utc('2026-03-31 00:00:00'),
    );
}

it('inv-17: the null amount is never visible outside the claim transaction', function () {
    // Query every payout row from a SECOND connection while a claim transaction
    // is open on the first. Assert no row with a NULL or zero amount is visible.
    // Then assert the committed row has amount > 0.
    $instructor = 61;

    payoutClaimRelease($instructor, 300, 'period:2026-03');
    payoutClaimRelease($instructor, 120, 'period:2026-02');

    $batch = payoutClaimBatch();

    // Prime B first: connB() is what sets innodb_lock_wait_timeout = 1 on that
    // session, and it must be a genuinely separate connection instance.
    $this->connB();

    $connA = $this->connA();

    // A's transaction wraps the claim's own, so the claim's DB::transaction
    // nests as a savepoint and nothing it wrote is committed here — which is
    // precisely how the claim gets held "before commit" (§10.3).
    $connA->beginTransaction();

    $payoutId = null;

    try {
        $payoutId = (new ClaimService('mysql'))->claim($instructor, $batch);

        // The claim really did run: it stamped both entries and wrote a payout
        // row with amount_minor still NULL. If it had not, everything below
        // would hold trivially.
        expect($payoutId)->not->toBeNull()
            ->and($connA->table('payouts')->whereNull('amount_minor')->count())->toBe(0)
            ->and((int) $connA->table('ledger_entries')->where('payout_id', $payoutId)->count())->toBe(2);

        // §10.4 / invariant 17: nothing of it is visible to anyone else. Not a
        // NULL amount, not a zero one, not the payout at all — and the entries
        // are still unclaimed as far as B is concerned.
        expect($this->connB()->table('payouts')->count())->toBe(0)
            ->and($this->connB()->table('ledger_entries')->whereNotNull('payout_id')->count())->toBe(0);
    } finally {
        $connA->commit();
    }

    // --- and only after the commit ---
    $visible = $this->connB()->table('payouts')->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->amount_minor)->not->toBeNull()
        ->and((int) $visible->first()->amount_minor)->toBe(420)
        ->and((int) $this->connB()->table('ledger_entries')->where('payout_id', $payoutId)->count())->toBe(2);
});

it('inv-17: a claim that dies mid-transaction leaves no payout row behind', function () {
    // The assertion the held-open test above cannot make. There, both a correct
    // single-transaction claim and a broken one that commits the payout insert
    // separately look identical from B, because the outer transaction hides
    // them equally.
    //
    // Here the claim is interrupted after the payout row is inserted and before
    // the amount is known: B holds one of the target ledger rows, so A blocks on
    // the §10.1 claim UPDATE and dies on the lock timeout. §10.4's "the whole
    // transaction is rolled back" is the only thing that stops a committed
    // amount_minor IS NULL row surviving that.
    //
    // Split ClaimService's payout insert into its own committed transaction and
    // this test goes red. If it does not, it is asserting nothing.
    $instructor = 62;

    $blocked = payoutClaimRelease($instructor, 300, 'period:2026-03');
    payoutClaimRelease($instructor, 120, 'period:2026-02');

    $batch = payoutClaimBatch();

    $connA = $this->connA();
    $connB = $this->connB();

    // A must surface the block as a fast, deterministic exception rather than
    // sitting on MySQL's 50-second default, the same way connB() does.
    $connA->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $connB->beginTransaction();

        // B owns one of the rows A is about to claim.
        $connB->table('ledger_entries')->where('id', $blocked->id)->lockForUpdate()->first();

        // A locks the balance, inserts the payout with amount_minor NULL, then
        // blocks on the claim UPDATE and times out.
        expect($this->blocks(fn () => (new ClaimService('mysql'))->claim($instructor, $batch)))->toBeTrue();
    } finally {
        $connB->rollBack();
        $connA->statement('SET SESSION innodb_lock_wait_timeout = 50');
    }

    // §10.4: no payout row at all — not a NULL-amount one, not a zero one.
    expect($this->connB()->table('payouts')->count())->toBe(0)
        ->and($this->connB()->table('ledger_entries')->whereNotNull('payout_id')->count())->toBe(0)
        // §10.5: "claim rolled back — none". The cache never moved either.
        ->and((int) $this->connB()->table('instructor_balances')
            ->where('instructor_id', $instructor)->value('available_minor'))->toBe(420)
        ->and((int) $this->connB()->table('instructor_balances')
            ->where('instructor_id', $instructor)->value('reserved_minor'))->toBe(0);

    // The entries were never lost: the next run claims them (§10.4's netting).
    $payoutId = (new ClaimService('mysql'))->claim($instructor, payoutClaimBatch());

    expect($payoutId)->not->toBeNull()
        ->and((int) $this->connB()->table('payouts')->where('id', $payoutId)->value('amount_minor'))->toBe(420);
});
