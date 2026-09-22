<?php

// Invariants 17, 25, 28, 29, 33 — ARCHITECTURE.md §10.1, §10.4

use App\Domain\Payout\BatchService;
use App\Domain\Payout\ClaimService;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutBatch;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecognitionFixtures;

/**
 * One ledger entry, plus the §10.5 cache move the transaction that posted it
 * would have made: `recognized += delta`, `available += delta`, on every
 * recognition type. Inserting the row without moving the cache would leave
 * invariant 3 broken before the claim under test even ran.
 *
 * Prefixed, because Pest file-scope functions are global across the suite —
 * the reason tests/Support/RecognitionFixtures is a class and not these.
 */
function inv04Recognize(
    int $instructorId,
    string $type,
    int $amountMinor,
    string $sourceRef,
    string $effectiveAt,
): LedgerEntry {
    RecognitionFixtures::balanceRow($instructorId);

    $entry = LedgerEntry::query()->create([
        'instructor_id' => $instructorId,
        'type' => $type,
        'amount_minor' => $amountMinor,
        'period_start' => substr($effectiveAt, 0, 7).'-01',
        'recognized_through_at' => $effectiveAt,
        // §3.3 / §10.1: the business-effective instant, and the payout
        // eligibility predicate.
        'effective_at' => $effectiveAt,
        'source_ref' => $sourceRef,
        // §10.1: unclaimed. The claim is what stamps this.
        'payout_id' => null,
    ]);

    DB::table('instructor_balances')
        ->where('instructor_id', $instructorId)
        ->incrementEach([
            'recognized_minor' => $amountMinor,
            'available_minor' => $amountMinor,
        ]);

    return $entry;
}

/**
 * A ledger row that is NOT a recognition type, so §10.5's cache move does not
 * apply to it and neither does the §10.1 allowlist. The column is deliberately
 * a VARCHAR so this is storable (tests/Feature/SchemaConstraintsTest).
 */
function inv04NonPayableEntry(int $instructorId, int $amountMinor, string $effectiveAt): LedgerEntry
{
    RecognitionFixtures::balanceRow($instructorId);

    return LedgerEntry::query()->create([
        'instructor_id' => $instructorId,
        'type' => 'tax_withholding',
        'amount_minor' => $amountMinor,
        'period_start' => substr($effectiveAt, 0, 7).'-01',
        'recognized_through_at' => $effectiveAt,
        'effective_at' => $effectiveAt,
        'source_ref' => 'tax:'.substr($effectiveAt, 0, 7),
        'payout_id' => null,
    ]);
}

/** @return array<int, int> the ids this payout claimed, ascending. */
function inv04ClaimedIds(?int $payoutId): array
{
    if ($payoutId === null) {
        return [];
    }

    return LedgerEntry::query()
        ->where('payout_id', $payoutId)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

function inv04UnclaimedIds(int $instructorId): array
{
    return LedgerEntry::query()
        ->where('instructor_id', $instructorId)
        ->whereNull('payout_id')
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

function inv04Batch(string $month): PayoutBatch
{
    $start = RecognitionFixtures::utc($month.'-01 00:00:00');

    return (new BatchService)->openFor($start, $start->modify('last day of this month'));
}

it('inv-17: a visible payout always has a positive amount', function () {
    // Query every payout row from a SECOND connection while a claim transaction
    // is open on the first. Assert no row with a NULL or zero amount is visible.
    // Then assert the committed row has amount > 0.
    // The NULL exists only inside the uncommitted transaction (§10.4).
    //
    // tests/Pest.php binds Invariants to RefreshDatabase, which wraps this test
    // in a transaction on the default connection — a second connection would
    // see an empty database and every assertion here would pass vacuously. The
    // two-connection half therefore lives in tests/Concurrency/PayoutClaimTest,
    // where DatabaseTruncation makes it real. What is asserted here is the
    // committed-state half, over the whole table: whatever the claim did, no
    // payout row exists that another transaction could read as NULL or ≤ 0.
    $positive = 46;
    $negative = 47;

    $this->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));

    inv04Recognize($positive, 'release', 300, 'period:2026-03', '2026-03-31 00:00:00');

    // §10.4: "a claimed set can contain both releases and negative corrections,
    // so the sum is not necessarily positive".
    inv04Recognize($negative, 'release', 100, 'period:2026-03', '2026-03-31 00:00:00');
    inv04Recognize($negative, 'release_correction', -150, 'refund:9812', '2026-03-31 00:00:00');

    $batch = inv04Batch('2026-03');
    $claim = new ClaimService;

    $positiveId = $claim->claim($positive, $batch);
    $negativeId = $claim->claim($negative, $batch);

    // §10.4: the non-positive claim rolled the whole transaction back, so it
    // has no payout to report.
    expect($negativeId)->toBeNull()
        ->and($positiveId)->not->toBeNull();

    // The invariant itself, asserted over every row in the table rather than
    // over the one this test happens to know about.
    expect(Payout::query()->whereNull('amount_minor')->count())->toBe(0)
        ->and(DB::table('payouts')->where('amount_minor', '<=', 0)->count())->toBe(0)
        ->and(Payout::query()->count())->toBe(1);

    $payout = Payout::query()->findOrFail($positiveId);

    expect($payout->amount_minor)->toBe(300)
        ->and($payout->amount_minor)->toBeGreaterThan(0)
        // The committed amount is exactly the set it claimed.
        ->and((int) LedgerEntry::query()->where('payout_id', $payout->id)->sum('amount_minor'))->toBe(300);
});

it('inv-25: running the payout command twice creates one payout per instructor', function () {
    // php artisan payouts:run twice for the same batch period.
    // Assert exactly one payout row per instructor.
    // Assert the second run claimed zero ledger entries.
    $first = 42;
    $second = 48;

    $this->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));

    inv04Recognize($first, 'release', 500, 'period:2026-03', '2026-03-20 00:00:00');
    inv04Recognize($second, 'release', 700, 'period:2026-03', '2026-03-25 00:00:00');

    $this->artisan('payouts:run', ['--month' => '2026-03'])->assertSuccessful();

    $afterFirstRun = LedgerEntry::query()->orderBy('id')->pluck('payout_id', 'id')->all();

    expect(Payout::query()->count())->toBe(2)
        ->and(Payout::query()->where('instructor_id', $first)->value('amount_minor'))->toBe(500)
        ->and(Payout::query()->where('instructor_id', $second)->value('amount_minor'))->toBe(700);

    $this->artisan('payouts:run', ['--month' => '2026-03'])->assertSuccessful();

    // §10.1: get-or-create on the period. A second batch would carry a second,
    // later max_entry_id and stop being the snapshot the first run took.
    expect(PayoutBatch::query()->count())->toBe(1)
        // §11.4: "command run twice → one payout".
        ->and(Payout::query()->count())->toBe(2)
        ->and(Payout::query()->where('instructor_id', $first)->count())->toBe(1)
        ->and(Payout::query()->where('instructor_id', $second)->count())->toBe(1)
        // The second run claimed nothing: every entry still points where the
        // first run put it.
        ->and(LedgerEntry::query()->orderBy('id')->pluck('payout_id', 'id')->all())->toBe($afterFirstRun)
        ->and(LedgerEntry::query()->whereNull('payout_id')->count())->toBe(0);

    // §10.5: claiming twice would have moved the cache twice.
    expect(RecognitionFixtures::balance($first)->available_minor)->toBe(0)
        ->and(RecognitionFixtures::balance($first)->reserved_minor)->toBe(500);
});

it('inv-28: replaying a batch claims an identical entry set', function () {
    // Create batch N (cutoff 10:00, max_entry_id 5000). Run it.
    // Insert a backdated entry: effective_at 09:00, but a NEW id (5001).
    // Replay batch N.
    // Assert the claimed entry set is byte-identical to the first run —
    // entry 5001 is excluded by `id <= max_entry_id` despite passing the date test.
    // Then assert batch N+1 DOES claim entry 5001.
    $replayed = 43;
    // §10.7: the instructor whose claim job was lost at 10:00 and is recovered
    // by the rerun at 10:05. Their first claim in batch N therefore happens
    // with the backdated entry already on disk, which is what makes
    // `id <= max_entry_id` — not the early return on an existing payout — the
    // only thing that can exclude it.
    $lateClaim = 44;

    $this->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));

    $a = inv04Recognize($replayed, 'release', 100, 'period:2026-03', '2026-03-20 00:00:00');
    $b = inv04Recognize($replayed, 'release_correction', 50, 'refund:100', '2026-03-25 00:00:00');
    $e = inv04Recognize($lateClaim, 'release', 60, 'period:2026-03', '2026-03-25 00:00:00');

    $batchN = inv04Batch('2026-03');

    expect($batchN->max_entry_id)->toBe($e->id)
        ->and($batchN->cutoff_at->format('Y-m-d H:i:s'))->toBe('2026-04-01 10:00:00');

    $claim = new ClaimService;

    $firstRun = $claim->claim($replayed, $batchN);
    $firstRunIds = inv04ClaimedIds($firstRun);

    expect($firstRunIds)->toBe([$a->id, $b->id]);

    // §10.1's worked example: "10:05 late refund arrives, id = 5001,
    // effective_at = 09:00 (backdated, §3.3)". Both entries pass the cutoff
    // test and fail the id test.
    $this->travelTo(RecognitionFixtures::utc('2026-04-01 10:05:00'));

    $c = inv04Recognize($replayed, 'refund_adjustment', 70, 'refund:5001', '2026-04-01 09:00:00');
    $f = inv04Recognize($lateClaim, 'refund_adjustment', 30, 'refund:5002', '2026-04-01 09:00:00');

    expect($c->id)->toBeGreaterThan($batchN->max_entry_id)
        ->and($c->effective_at->format('Y-m-d H:i:s'))
        ->toBeLessThan($batchN->cutoff_at->format('Y-m-d H:i:s'));

    // Replay batch N.
    $replay = $claim->claim($replayed, inv04Batch('2026-03'));

    expect($replay)->toBe($firstRun)
        ->and(inv04ClaimedIds($replay))->toBe($firstRunIds)
        ->and(inv04UnclaimedIds($replayed))->toBe([$c->id]);

    // The late claim: its first run in batch N, with the backdated entry
    // already present. Only `id <= max_entry_id` can keep it out.
    $late = $claim->claim($lateClaim, inv04Batch('2026-03'));

    expect(inv04ClaimedIds($late))->toBe([$e->id])
        ->and(inv04UnclaimedIds($lateClaim))->toBe([$f->id]);

    // §10.1: "the entry is not lost — it satisfies both predicates for batch
    // N+1."
    $batchNext = inv04Batch('2026-04');

    expect(inv04ClaimedIds($claim->claim($replayed, $batchNext)))->toBe([$c->id])
        ->and(inv04ClaimedIds($claim->claim($lateClaim, $batchNext)))->toBe([$f->id])
        ->and(LedgerEntry::query()->whereNull('payout_id')->count())->toBe(0);
});

it('inv-29: a ledger type outside the allowlist is never claimed', function () {
    // Insert a ledger entry with a type not in the payable allowlist
    // (e.g. 'tax_withholding') that otherwise satisfies every predicate.
    // Run a payout batch.
    // Assert the entry is still unclaimed.
    $instructor = 45;

    $this->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));

    $release = inv04Recognize($instructor, 'release', 300, 'period:2026-03', '2026-03-31 00:00:00');

    // Same instructor, unclaimed, id below the mark, effective_at before the
    // cutoff — every predicate but the allowlist.
    $tax = inv04NonPayableEntry($instructor, -250, '2026-03-31 00:00:00');

    $batch = inv04Batch('2026-03');

    expect($tax->id)->toBeLessThanOrEqual($batch->max_entry_id);

    $payoutId = (new ClaimService)->claim($instructor, $batch);

    expect(inv04ClaimedIds($payoutId))->toBe([$release->id])
        ->and($tax->fresh()->payout_id)->toBeNull()
        // Had the allowlist leaked, the payout would have been 50, not 300.
        ->and(Payout::query()->findOrFail($payoutId)->amount_minor)->toBe(300);
});

it('inv-33: a negative balance is cleared by future recognition automatically', function () {
    // March: release +100, correction -150. Run the batch.
    //   -> assert NO payout row exists, and both entries are still unclaimed.
    // April: release +200. Run the batch.
    //   -> assert one payout of 150, claiming all three entries.
    // No manual step anywhere.
    $instructor = 41;

    $this->travelTo(RecognitionFixtures::utc('2026-04-01 10:00:00'));

    $march = inv04Recognize($instructor, 'release', 100, 'period:2026-03', '2026-03-31 00:00:00');
    $correction = inv04Recognize($instructor, 'release_correction', -150, 'refund:9812', '2026-03-31 00:00:00');

    // §14: debt is storable here rather than clamped at zero.
    expect(RecognitionFixtures::balance($instructor)->available_minor)->toBe(-50);

    $this->artisan('payouts:run', ['--month' => '2026-03'])->assertSuccessful();

    // §10.4: "sum -50, ROLLBACK — no payout, no transfer".
    expect(Payout::query()->count())->toBe(0)
        ->and(inv04UnclaimedIds($instructor))->toBe([$march->id, $correction->id])
        ->and(RecognitionFixtures::balance($instructor)->available_minor)->toBe(-50)
        ->and(RecognitionFixtures::balance($instructor)->reserved_minor)->toBe(0);

    // The transaction is the guarantee, not the command's available_minor > 0
    // filter (§10.4, "optimisation, not correctness"): driving the claim
    // directly must roll back too, rather than leaving a zero or negative row.
    expect((new ClaimService)->claim($instructor, inv04Batch('2026-03')))->toBeNull()
        ->and(Payout::query()->count())->toBe(0)
        ->and(inv04UnclaimedIds($instructor))->toBe([$march->id, $correction->id]);

    $this->travelTo(RecognitionFixtures::utc('2026-05-01 10:00:00'));

    $april = inv04Recognize($instructor, 'release', 200, 'period:2026-04', '2026-04-30 00:00:00');

    expect(RecognitionFixtures::balance($instructor)->available_minor)->toBe(150);

    $this->artisan('payouts:run', ['--month' => '2026-04'])->assertSuccessful();

    // §10.4: "the netting is automatic — the claim query takes every unclaimed
    // payable entry, so the March debt is swept up by the April batch with no
    // carry-forward mechanism".
    $payout = Payout::query()->sole();

    expect(Payout::query()->count())->toBe(1)
        ->and($payout->amount_minor)->toBe(150)
        ->and(inv04ClaimedIds($payout->id))->toBe([$march->id, $correction->id, $april->id])
        ->and(inv04UnclaimedIds($instructor))->toBe([])
        // §10.5: available -= amount, reserved += amount, recognized untouched.
        ->and(RecognitionFixtures::balance($instructor)->available_minor)->toBe(0)
        ->and(RecognitionFixtures::balance($instructor)->reserved_minor)->toBe(150)
        ->and(RecognitionFixtures::balance($instructor)->recognized_minor)->toBe(150);
});
