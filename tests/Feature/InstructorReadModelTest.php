<?php

// ARCHITECTURE.md §13 — the read model. "The required read-only screen reads the
// cache, never the ledger", and `pending` is the one figure it computes.

use App\Domain\Payout\SettlementService;
use App\Domain\Provider\Outcome;
use App\Domain\Recognition\ReleaseService;
use App\Filament\Resources\InstructorResource;
use App\Filament\Resources\InstructorResource\Pages\ListInstructors;
use App\Filament\Resources\InstructorResource\Pages\ViewInstructor;
use App\Filament\Resources\InstructorResource\RelationManagers\PayoutsRelationManager;
use App\Filament\Support\MinorUnits;
use App\Filament\Support\PendingRecognition;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PayoutFixtures;
use Tests\Support\RecognitionFixtures;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * §6: a 10-day term for 10,000 piastres, so released() advances exactly 1,000 a
 * day and every expected value below is readable without arithmetic.
 */
function readModelAllocation(int $instructorId): void
{
    RecognitionFixtures::allocation(
        $instructorId,
        10_000,
        RecognitionFixtures::utc('2026-03-01 00:00:00'),
        RecognitionFixtures::utc('2026-03-11 00:00:00'),
    );
}

it('formats integer minor units without ever touching a float', function () {
    // §3: no floats, anywhere. number_format() takes a float parameter and
    // would lose precision on the last case here — a BIGINT amount above 2^53.
    expect(MinorUnits::format(0))->toBe('0.00')
        ->and(MinorUnits::format(7))->toBe('0.07')
        ->and(MinorUnits::format(4_667))->toBe('46.67')
        ->and(MinorUnits::format(100))->toBe('1.00')
        // §10.4: available may be negative, so this is a normal path.
        ->and(MinorUnits::format(-1))->toBe('-0.01')
        ->and(MinorUnits::format(-123_456_789))->toBe('-1,234,567.89')
        ->and(MinorUnits::format(100_000_000_000_000_000))->toBe('1,000,000,000,000,000.00');
});

it('reads the balance from instructor_balances and never from the ledger', function () {
    RecognitionFixtures::balanceRow(51);

    // The cache says one thing; the ledger is empty. §13 says the screen reads
    // the cache, so every figure below must be the cached one — a screen that
    // aggregated ledger_entries would show four zeroes here instead.
    InstructorBalance::query()->whereKey(51)->update([
        'recognized_minor' => 90_000,
        // §10.4: debt is a negative available balance, shown as such.
        'available_minor' => -25_000,
        'reserved_minor' => 40_000,
        'paid_minor' => 75_000,
        'recognized_through_at' => '2026-03-31 00:00:00',
    ]);

    Livewire::test(ViewInstructor::class, ['record' => 51])
        ->assertOk()
        ->assertSee('900.00')
        ->assertSee('-250.00')
        ->assertSee('400.00')
        ->assertSee('750.00')
        // §3.3: the watermark, in UTC as stored.
        ->assertSee('2026-03-31 00:00:00');
});

it('lists the cached buckets for every instructor', function () {
    RecognitionFixtures::balanceRow(61);
    RecognitionFixtures::balanceRow(62);

    InstructorBalance::query()->whereKey(61)->update(['available_minor' => 1_234]);
    InstructorBalance::query()->whereKey(62)->update(['available_minor' => -5_600]);

    Livewire::test(ListInstructors::class)
        ->assertCanSeeTableRecords(InstructorBalance::query()->orderBy('instructor_id')->get())
        ->assertTableColumnFormattedStateSet('available_minor', '12.34', 61)
        ->assertTableColumnFormattedStateSet('available_minor', '-56.00', 62);
});

it('computes pending as released(now) minus posted', function () {
    readModelAllocation(52);

    // §6.2: post recognition through day 3 and no further.
    (new ReleaseService)->releaseScheduled(52, RecognitionFixtures::utc('2026-03-04 00:00:00'));

    expect(RecognitionFixtures::posted(52))->toBe(3_000);

    // §6.5: now stand on day 7. expected(now) = 7,000, posted = 3,000, so
    // 4,000 has accrued economically without being posted to the ledger.
    $this->travelTo(RecognitionFixtures::utc('2026-03-08 00:00:00'));

    expect(PendingRecognition::forInstructor(52))->toBe(4_000);

    Livewire::test(ViewInstructor::class, ['record' => 52])
        ->assertOk()
        // Pending, alongside the posted figure it is derived against.
        ->assertSee('40.00')
        ->assertSee('30.00');
});

it('never counts a refund_adjustment as pending recognition', function () {
    readModelAllocation(53);

    (new ReleaseService)->releaseScheduled(53, RecognitionFixtures::utc('2026-03-04 00:00:00'));

    $this->travelTo(RecognitionFixtures::utc('2026-03-08 00:00:00'));

    expect(PendingRecognition::forInstructor(53))->toBe(4_000);

    // §7: an already-recognized amount clawed back — a type that released()
    // knows nothing about. §10.5: the transaction that posts it moves the cache
    // in the same breath, which is what is mirrored here.
    LedgerEntry::query()->create([
        'instructor_id' => 53,
        'type' => 'refund_adjustment',
        'amount_minor' => -500,
        'period_start' => '2026-03-01',
        'recognized_through_at' => '2026-03-04 00:00:00',
        'effective_at' => '2026-03-02 00:00:00',
        'source_ref' => 'refund:9812',
        'payout_id' => null,
    ]);

    DB::table('instructor_balances')->where('instructor_id', 53)->incrementEach([
        'recognized_minor' => -500,
        'available_minor' => -500,
    ]);

    // §6.2 / invariant 6: `posted` excludes refund_adjustment, so pending does
    // not move. Had it been derived from recognized_minor — which covers all
    // entry types (§1.1) — it would now read 4,500 and promise the instructor
    // money the refund has already taken back.
    expect(PendingRecognition::forInstructor(53))->toBe(4_000)
        ->and(RecognitionFixtures::recognized(53))->toBe(2_500);
});

it('shows a payout joined to its batch, with attempts and the last provider reference', function () {
    $payout = PayoutFixtures::claimedPayout(54, 5_000, '2026-03');

    $slot = PayoutFixtures::acquire($payout);
    (new SettlementService)->recordSuccess($slot->id, Outcome::success('txf_march_54'));

    Livewire::test(PayoutsRelationManager::class, [
        'ownerRecord' => InstructorBalance::query()->findOrFail(54),
        'pageClass' => ViewInstructor::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$payout])
        // §13: batch period, amount, status, settled_at, attempts with the last
        // provider reference.
        ->assertSee('2026-03-01')
        ->assertSee('2026-03-31')
        // The record is named by key, so every assertion below reads the row
        // the table itself loaded rather than a stale in-memory copy.
        ->assertTableColumnFormattedStateSet('amount_minor', '50.00', $payout->getKey())
        ->assertTableColumnStateSet('status', 'settled', $payout->getKey())
        // §10.2 / invariant 19: one acquired slot, one attempt.
        ->assertTableColumnStateSet('attempt_count', 1, $payout->getKey())
        ->assertTableColumnStateSet('latestAttempt.provider_reference', 'txf_march_54', $payout->getKey())
        ->assertSee('txf_march_54');
});

it('shows the last attempt reference, not an earlier one', function () {
    $payout = PayoutFixtures::claimedPayout(55, 5_000, '2026-04');

    // §11.4: a definitive failure resolves the attempt; the entries stay
    // claimed and a further attempt gets a new key and a new provider call.
    $first = PayoutFixtures::acquire($payout);
    (new SettlementService)->recordFailure($first->id, Outcome::failure());

    $second = PayoutFixtures::acquire($payout->refresh());
    (new SettlementService)->recordSuccess($second->id, Outcome::success('txf_second'));

    Livewire::test(PayoutsRelationManager::class, [
        'ownerRecord' => InstructorBalance::query()->findOrFail(55),
        'pageClass' => ViewInstructor::class,
    ])
        ->assertTableColumnStateSet('attempt_count', 2, $payout->getKey())
        // §14: attempts are ordered by attempt_no, so "last" is attempt 2.
        ->assertTableColumnStateSet('latestAttempt.provider_reference', 'txf_second', $payout->getKey());
});

it('offers no create, edit or delete path', function () {
    // §13: read-only, and §10.5 — the cache is maintained by the transactions
    // that move the ledger rows, by nothing else. A screen that could write to
    // it would be a reconciliation finding waiting to happen.
    RecognitionFixtures::balanceRow(56);

    $balance = InstructorBalance::query()->findOrFail(56);

    expect(InstructorResource::canCreate())->toBeFalse()
        ->and(InstructorResource::canEdit($balance))->toBeFalse()
        ->and(InstructorResource::canDelete($balance))->toBeFalse()
        ->and(InstructorResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(InstructorResource::getPages()))->toBe(['index', 'view']);
});

it('reads the batch and the last attempt once each, not once per payout', function () {
    // §13: "Everything else is a single row lookup or an indexed join." A
    // relationship column resolved per row would make the payout history cost
    // grow with an instructor's history, which is exactly what §13 rules out.
    foreach (['2026-01', '2026-02', '2026-03'] as $month) {
        $payout = PayoutFixtures::claimedPayout(57, 5_000, $month);
        $slot = PayoutFixtures::acquire($payout);
        (new SettlementService)->recordSuccess($slot->id, Outcome::success('txf_'.$month));
    }

    $owner = InstructorBalance::query()->findOrFail(57);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(PayoutsRelationManager::class, [
        'ownerRecord' => $owner,
        'pageClass' => ViewInstructor::class,
    ])->assertSee('txf_2026-03');

    $queries = collect(DB::getRawQueryLog())->pluck('raw_query');

    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'payout_batches'))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'payout_attempts'))->count())->toBe(1);
});
