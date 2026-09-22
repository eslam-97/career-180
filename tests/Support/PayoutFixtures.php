<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Payout\AttemptService;
use App\Domain\Payout\AttemptSlot;
use App\Domain\Payout\BatchService;
use App\Domain\Payout\ClaimService;
use App\Domain\Provider\PaymentProvider;
use App\Domain\Provider\Scenario;
use App\Domain\Provider\ScriptedProvider;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\PayoutBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the §10.2 attempt, the §10.6 transitions and the §11 provider
 * paths. A class rather than Pest file-scope functions, which are global across
 * the whole suite — the same reason RecognitionFixtures is one.
 *
 * Everything here builds state the way the production path would: a payout is
 * produced by a real ClaimService run, so its entries are genuinely stamped and
 * its balance genuinely moved available -> reserved. A payout assembled by
 * factory alone would leave the buckets inconsistent before the transition under
 * test even ran, and invariant 4 would be asserting nothing.
 */
final class PayoutFixtures
{
    private static int $sequence = 0;

    /**
     * One release entry plus the §10.5 cache move the transaction that posted it
     * would have made: recognized += delta, available += delta.
     */
    public static function recognize(
        int $instructorId,
        int $amountMinor,
        string $effectiveAt = '2026-03-31 00:00:00',
        ?string $sourceRef = null,
    ): LedgerEntry {
        RecognitionFixtures::balanceRow($instructorId);

        self::$sequence++;

        $entry = LedgerEntry::query()->create([
            'instructor_id' => $instructorId,
            'type' => 'release',
            'amount_minor' => $amountMinor,
            'period_start' => substr($effectiveAt, 0, 7).'-01',
            'recognized_through_at' => $effectiveAt,
            'effective_at' => $effectiveAt,
            // §14: UNIQUE(instructor_id, type, source_ref), so every fixture
            // entry needs its own key.
            'source_ref' => $sourceRef ?? 'fixture:'.self::$sequence,
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

    public static function batch(?string $month = null): PayoutBatch
    {
        $start = RecognitionFixtures::utc(($month ?? self::nextPeriod()).'-01 00:00:00');

        return (new BatchService)->openFor($start, $start->modify('last day of this month'));
    }

    /**
     * §10.1: max_entry_id is frozen when the batch is opened and never again, so
     * a fixture that recognizes an entry after opening a batch would find it
     * excluded. Each call therefore opens its own period unless the test names
     * one — "the next batch" in §10.4 and invariant 32 is a different snapshot,
     * not the same one re-read.
     */
    private static function nextPeriod(): string
    {
        self::$sequence++;

        return RecognitionFixtures::utc('2026-01-01 00:00:00')
            ->modify('+'.self::$sequence.' months')
            ->format('Y-m');
    }

    /** A real §10.3 claim: entries stamped, available -> reserved, payout pending. */
    public static function claim(int $instructorId, ?string $month = null): Payout
    {
        $payout = self::tryClaim($instructorId, $month);

        if ($payout === null) {
            throw new RuntimeException("The claim for instructor {$instructorId} produced no payout.");
        }

        return $payout;
    }

    /** §10.4: a claim that nets to zero or less produces no payout at all. */
    public static function tryClaim(int $instructorId, ?string $month = null): ?Payout
    {
        $payoutId = (new ClaimService)->claim($instructorId, self::batch($month));

        return $payoutId === null ? null : Payout::query()->findOrFail($payoutId);
    }

    /** Recognize $amountMinor and claim it, in one step. */
    public static function claimedPayout(int $instructorId, int $amountMinor, ?string $month = null): Payout
    {
        self::recognize($instructorId, $amountMinor);

        return self::claim($instructorId, $month);
    }

    /** §10.2: the slot, acquired the way a worker acquires it. */
    public static function acquire(Payout $payout): AttemptSlot
    {
        $slot = (new AttemptService)->acquire($payout->id);

        if ($slot === null) {
            throw new RuntimeException("The slot for payout {$payout->id} was refused.");
        }

        return $slot;
    }

    /**
     * Put an attempt into one of the states §11 reaches, without pretending a
     * service did it. Used where the test's subject is what happens NEXT — the
     * transitions themselves are asserted by the tests that drive the services.
     */
    public static function forceAttemptStatus(int $attemptId, string $status): void
    {
        PayoutAttempt::query()->whereKey($attemptId)->update(['status' => $status]);
    }

    /** §11.1: push an attempt's lease into the past so the sweeper can see it. */
    public static function expireLease(int $attemptId): void
    {
        PayoutAttempt::query()->whereKey($attemptId)->update([
            'lease_expires_at' => now()->utc()->subMinute(),
        ]);
    }

    /** The scripted provider, bound into the container the jobs resolve from. */
    public static function scripted(Scenario ...$scenarios): ScriptedProvider
    {
        $provider = app(ScriptedProvider::class);

        if ($scenarios !== []) {
            $provider->script(...$scenarios);
        }

        app()->instance(PaymentProvider::class, $provider);
        app()->instance(ScriptedProvider::class, $provider);

        return $provider;
    }

    /**
     * The cached buckets, as §12 level one names them.
     *
     * @return array{recognized: int, available: int, reserved: int, paid: int}
     */
    public static function buckets(int $instructorId): array
    {
        $balance = InstructorBalance::query()->findOrFail($instructorId);

        return [
            'recognized' => $balance->recognized_minor,
            'available' => $balance->available_minor,
            'reserved' => $balance->reserved_minor,
            'paid' => $balance->paid_minor,
        ];
    }

    /**
     * §12 level one, computed from the ledger rather than read from the cache:
     *
     *     available  == SUM(entries WHERE payout_id IS NULL)
     *     reserved   == SUM(entries WHERE payout_id IN non-terminal payouts)
     *     paid       == SUM(entries WHERE payout_id IN settled payouts)
     *
     * @return array{recognized: int, available: int, reserved: int, paid: int}
     */
    public static function ledgerBuckets(int $instructorId): array
    {
        $sum = fn ($query) => (int) $query->sum('amount_minor');

        $entries = fn () => LedgerEntry::query()->where('instructor_id', $instructorId);

        $available = $sum($entries()->whereNull('payout_id'));

        $reserved = $sum($entries()->whereIn('payout_id', Payout::query()
            ->whereIn('status', ['pending', 'in_progress', 'needs_review'])
            ->select('id')));

        $paid = $sum($entries()->whereIn('payout_id', Payout::query()
            ->where('status', 'settled')
            ->select('id')));

        return [
            'recognized' => $available + $reserved + $paid,
            'available' => $available,
            'reserved' => $reserved,
            'paid' => $paid,
        ];
    }

    /**
     * Invariant 4: every ledger entry is in exactly one bucket. Any entry
     * stamped with a terminal-failed payout is in none of the three, which is
     * the state §10.6's single transaction exists to make impossible.
     */
    public static function orphanedEntries(): int
    {
        return LedgerEntry::query()
            ->whereNotNull('payout_id')
            ->whereIn('payout_id', Payout::query()->where('status', 'failed')->select('id'))
            ->count();
    }
}
