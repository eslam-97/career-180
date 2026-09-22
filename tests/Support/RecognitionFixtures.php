<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fixtures for the §6.2 release job and the §12 reconciliation checks.
 *
 * A class rather than Pest file-scope functions: those are global across the
 * whole suite, which is why every existing test file prefixes its own
 * (moneyTestEpoch, allocationTestInstant, sweepTestInstant …). These are shared
 * by the invariants, concurrency and feature tests, so they need one home.
 */
final class RecognitionFixtures
{
    /** §3.3: all timestamps are stored and compared in UTC. */
    public static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * One confirmed payment, fully allocated to one instructor, plus the zeroed
     * balance row allocation would have created (§9.4 — the release job's
     * FOR UPDATE needs it to exist).
     *
     * platform_rate_bps is 0 so the instructor's allocation IS the whole gross:
     * Σ allocations + platform_cut == amount holds exactly, which keeps §12
     * level zero green on every fixture rather than only on the ones a test
     * remembered to balance.
     */
    public static function allocation(
        int $instructorId,
        int $amountMinor,
        DateTimeImmutable $termStart,
        DateTimeImmutable $termEnd,
        ?DateTimeImmutable $accessEndsAt = null,
    ): SubscriptionPayment {
        $subscription = Subscription::factory()->create([
            'amount_minor' => $amountMinor,
            'starts_at' => $termStart,
            'ends_at' => $termEnd,
            'access_ends_at' => $accessEndsAt,
        ]);

        $payment = SubscriptionPayment::factory()->confirmed()->create([
            'subscription_id' => $subscription->id,
            'amount_minor' => $amountMinor,
            'instructor_ids' => [$instructorId],
            'platform_rate_bps' => 0,
            'platform_cut_minor' => 0,
            // §4: the term is derived from the dates, and the payment carries
            // the same window as the subscription it paid for.
            'term_start' => $termStart,
            'term_end' => $termEnd,
            // §5.1: money in at the start of the term it bought. The factory
            // would stamp this with now(), which would put every fixture inside
            // §12 level zero's grace window and quietly exempt it from the
            // check. A test that wants a just-confirmed payment sets this itself.
            'paid_at' => $termStart,
        ]);

        RevenueAllocation::factory()->create([
            'payment_id' => $payment->id,
            'instructor_id' => $instructorId,
            'amount_minor' => $amountMinor,
            'weight_numerator' => 1,
            'weight_denominator' => 1,
        ]);

        self::balanceRow($instructorId);

        return $payment;
    }

    /**
     * §9.4: allocation creates the balance row with an insert-if-missing, and
     * the release job's FOR UPDATE needs it to exist — a lock on a missing row
     * locks nothing.
     */
    public static function balanceRow(int $instructorId): void
    {
        InstructorBalance::query()->insertOrIgnore([
            'instructor_id' => $instructorId,
            'recognized_minor' => 0,
            'available_minor' => 0,
            'reserved_minor' => 0,
            'paid_minor' => 0,
            'recognized_through_at' => null,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }

    /**
     * §6.1: "whether a given refund sets access_ends_at is a business rule
     * applied by a policy class". That policy is slice 3b; until then a test
     * terminates access directly, which is exactly what the policy will do.
     */
    public static function terminateAccess(SubscriptionPayment $payment, DateTimeImmutable $at): void
    {
        Subscription::query()
            ->whereKey($payment->subscription_id)
            ->update(['access_ends_at' => $at->format('Y-m-d H:i:s')]);
    }

    public static function balance(int $instructorId): ?InstructorBalance
    {
        return InstructorBalance::query()->find($instructorId);
    }

    /** §1.1: `posted` — release + release_correction, never refund_adjustment. */
    public static function posted(int $instructorId): int
    {
        return (int) LedgerEntry::query()
            ->where('instructor_id', $instructorId)
            ->whereIn('type', ['release', 'release_correction'])
            ->sum('amount_minor');
    }

    /** §1.1: `recognized` — every entry type, refund_adjustment included. */
    public static function recognized(int $instructorId): int
    {
        return (int) LedgerEntry::query()
            ->where('instructor_id', $instructorId)
            ->sum('amount_minor');
    }

    /** @return Collection<int, LedgerEntry> */
    public static function entries(int $instructorId)
    {
        return LedgerEntry::query()
            ->where('instructor_id', $instructorId)
            ->orderBy('id')
            ->get();
    }
}
