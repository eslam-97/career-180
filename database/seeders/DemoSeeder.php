<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Allocation\AllocationService;
use App\Domain\Payment\ConfirmPaymentService;
use App\Domain\Payment\InitiatePaymentService;
use App\Domain\Recognition\ReleaseService;
use App\Domain\Refund\RecordRefundService;
use App\Domain\Refund\RefundPolicy;
use App\Filament\Support\MinorUnits;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The dataset the walkthrough runs on: 20 instructors, 200 subscriptions across
 * all three plan lengths, a handful of mid-term cancellations and a handful of
 * refunds, then ten months of recognition posted in order.
 *
 * Small and readable on purpose. Every row here is produced by the same
 * services production uses — InitiatePaymentService, ConfirmPaymentService,
 * AllocationService, RecordRefundService, ReleaseService — so what a viewer
 * sees on the balance screen is the output of the money path, not of a seeder
 * that knows the answers. `ScaleSeeder` is the opposite trade and says so.
 *
 * Seeded randomness (§16.3's principle, applied to the data rather than the
 * provider): a re-recorded walkthrough gets the same 200 subscriptions, with
 * the same terms, instructors, amounts and refunds. The client idempotency keys
 * differ, because §5.1 generates those as UUIDs and a seeder has no business
 * reaching past that.
 */
final class DemoSeeder extends Seeder
{
    /** A re-run produces an identical dataset. */
    private const SEED = 20260922;

    private const INSTRUCTORS = 20;

    /**
     * §4: term length is derived from the dates, never from a plan constant —
     * so these are the dates the plan implies, not a length the ledger reads.
     *
     * @var array<int, array{code: string, days: int, amount: int, count: int}>
     */
    private const PLANS = [
        ['code' => 'monthly', 'days' => 30, 'amount' => 15_000, 'count' => 100],
        ['code' => 'quarterly', 'days' => 90, 'amount' => 35_000, 'count' => 70],
        ['code' => 'annual', 'days' => 365, 'amount' => 120_000, 'count' => 30],
    ];

    /** §5.3: 20%, frozen onto every payment at initiate. */
    private const PLATFORM_RATE_BPS = 2_000;

    /** How far back the oldest subscription starts. Ten closed months to release. */
    private const MONTHS_OF_HISTORY = 10;

    private Randomizer $rng;

    public function run(): void
    {
        $this->rng = new Randomizer(new Mt19937(self::SEED));

        // Every service here dispatches its follow-up job — allocation after
        // confirmation, the targeted recompute after a refund. The seeder runs
        // them inline so a freshly seeded database is complete, rather than
        // correct only once somebody remembers to start a worker.
        config(['queue.default' => 'sync']);

        $this->say('Seeding the demo dataset — 20 instructors, 200 subscriptions, 10 months of history.');

        $payments = $this->moneyIn();
        $this->cancelSomeAutoRenewals();
        $refunds = $this->planRefunds($payments);
        $this->recognizeMonthByMonth($refunds);

        $this->summary();
    }

    /**
     * §2's inbound half, 200 times: a subscription, a payment initiated with
     * our own key, confirmed with theirs, and split across its instructors.
     *
     * @return array<int, SubscriptionPayment>
     */
    private function moneyIn(): array
    {
        $initiate = app(InitiatePaymentService::class);
        $confirm = app(ConfirmPaymentService::class);
        $allocate = app(AllocationService::class);

        $payments = [];

        foreach (self::PLANS as $plan) {
            for ($i = 0; $i < $plan['count']; $i++) {
                $termStart = $this->randomStart($plan['days']);
                $termEnd = $termStart->modify('+'.$plan['days'].' days');

                $subscription = Subscription::query()->create([
                    'student_id' => $this->rng->getInt(1, 10_000),
                    'plan_code' => $plan['code'],
                    'amount_minor' => $plan['amount'],
                    // §3.2: a single settlement currency, stored where money enters.
                    'currency' => 'EGP',
                    // §4: [starts_at, ends_at) — end exclusive.
                    'starts_at' => $termStart,
                    'ends_at' => $termEnd,
                    'cancelled_at' => null,
                    'access_ends_at' => null,
                    'status' => 'active',
                ]);

                $payment = $initiate->initiate(
                    $subscription,
                    $plan['amount'],
                    self::PLATFORM_RATE_BPS,
                    // §5.2: the distinct instructors the subscription grants
                    // access to, determined at payment time.
                    $this->someInstructors(),
                    'demo',
                    $termStart,
                    $termEnd,
                );

                // §5.1: money in is confirmed exactly once. This dispatches the
                // allocation job; the sync queue above runs it here.
                $confirm->confirm(
                    $payment->client_idempotency_key,
                    'demo_ref_'.$payment->id,
                    $termStart,
                );

                // Belt to the dispatch above, and free: the allocation set is
                // guarded by UNIQUE(payment_id, instructor_id), so a second call
                // verifies the set rather than writing one.
                $allocate->allocate($payment->id);

                $payments[] = $payment;
            }
        }

        $this->say(sprintf('  %d subscriptions paid, confirmed and allocated.', count($payments)));

        return $payments;
    }

    /**
     * §6.1: "access termination is not cancellation". A cancelled auto-renewal
     * does NOT cap recognition — the term the student paid for still runs, and
     * the instructors still earn every day of it.
     *
     * Conflating the two is the bug this row exists to make visible on screen:
     * fifteen subscriptions here are cancelled and recognize in full.
     */
    private function cancelSomeAutoRenewals(): void
    {
        $subscriptions = Subscription::query()
            ->where('plan_code', '!=', 'monthly')
            ->orderBy('id')
            ->limit(15)
            ->get();

        foreach ($subscriptions as $subscription) {
            $termDays = (int) $subscription->starts_at->diffInDays($subscription->ends_at);
            $elapsed = max(1, intdiv($termDays, 3));

            Subscription::query()->whereKey($subscription->getKey())->update([
                // §6.1: cancelled_at only. access_ends_at is untouched, which is
                // the entire distinction.
                'cancelled_at' => $subscription->starts_at->addDays($elapsed),
                'status' => 'cancelled',
            ]);
        }

        $this->say('  15 auto-renewals cancelled mid-term — recognition is NOT capped (§6.1).');
    }

    /**
     * §7: the refunds, dated but not yet recorded. They are applied inside the
     * month loop below, when their effective date arrives, so the corrections
     * land against a ledger that already has releases in it.
     *
     * @param  array<int, SubscriptionPayment>  $payments
     * @return array<int, array{payment: SubscriptionPayment, kind: string, amount: int, at: DateTimeImmutable}>
     */
    private function planRefunds(array $payments): array
    {
        $kinds = [
            RefundPolicy::TERMINATION_PRORATA,
            RefundPolicy::TERMINATION_PRORATA,
            RefundPolicy::TERMINATION_FULL,
            RefundPolicy::GOODWILL_PARTIAL,
        ];

        $planned = [];

        // Spread across the set rather than taken from the front, so the
        // refunded subscriptions are not all the same plan length.
        for ($i = 7; $i < count($payments); $i += 17) {
            $payment = $payments[$i];
            $kind = $kinds[count($planned) % count($kinds)];

            $termDays = (int) $payment->term_start->diffInDays($payment->term_end);
            $elapsed = intdiv($termDays * 2, 5);
            $at = $this->utc($payment->term_start->modify("+{$elapsed} days")->format('Y-m-d 00:00:00'));

            // A refund dated in the future has no business being recorded yet.
            if ($at > $this->today()) {
                continue;
            }

            $planned[] = [
                'payment' => $payment,
                'kind' => $kind,
                'amount' => $this->refundAmount($kind, $payment->amount_minor, $termDays, $elapsed),
                'at' => $at,
            ];
        }

        // Oldest first, so the loop below can drain them in calendar order.
        usort($planned, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $planned;
    }

    /**
     * §7.1: "a prorated termination refund is assumed to correspond to the
     * unused portion of the term". The assumption is stated, and this is it.
     */
    private function refundAmount(string $kind, int $amountMinor, int $termDays, int $elapsedDays): int
    {
        return match ($kind) {
            RefundPolicy::TERMINATION_FULL => $amountMinor,
            // §3: integer division only, and never below one piastre.
            RefundPolicy::TERMINATION_PRORATA => max(1, intdiv($amountMinor * ($termDays - $elapsedDays), $termDays)),
            default => 2_000,
        };
    }

    /**
     * §6.4: recognition is computed daily but POSTED monthly, so history is
     * built one posting period at a time, oldest first — exactly as ten
     * scheduled runs of `release:run` would have built it.
     *
     * @param  array<int, array{payment: SubscriptionPayment, kind: string, amount: int, at: DateTimeImmutable}>  $refunds
     */
    private function recognizeMonthByMonth(array $refunds): void
    {
        $release = app(ReleaseService::class);
        $record = app(RecordRefundService::class);

        $instructorIds = InstructorBalance::query()->orderBy('instructor_id')->pluck('instructor_id')->all();

        $month = $this->today()->modify('first day of this month')->modify('-'.self::MONTHS_OF_HISTORY.' months');
        $lastClosedMonth = $this->today()->modify('first day of this month')->modify('-1 month');

        $recorded = 0;

        while ($month <= $lastClosedMonth) {
            $monthEnd = $month->modify('last day of this month');

            // §6.1: a refund dispatches a targeted recompute rather than waiting
            // for the next monthly run, so it is recorded before this month's
            // release — the same order the calendar would have produced.
            while ($refunds !== [] && $refunds[0]['at'] <= $monthEnd) {
                $refund = array_shift($refunds);

                $record->record(
                    $refund['payment']->id,
                    $refund['amount'],
                    $refund['kind'],
                    'demo: '.$refund['kind'],
                    $refund['at'],
                    'demo',
                    'demo_refund_'.$refund['payment']->id,
                    $refund['at'],
                );

                $recorded++;
            }

            // §6.2: the target horizon for the month's run — the last day of the
            // posting period, which keys the row on 'period:YYYY-MM'.
            foreach ($instructorIds as $instructorId) {
                $release->releaseScheduled((int) $instructorId, $monthEnd);
            }

            $month = $month->modify('+1 month');
        }

        $this->say(sprintf('  %d refunds recorded, and %d posting periods released.', $recorded, self::MONTHS_OF_HISTORY));
    }

    /** What the walkthrough opens on. */
    private function summary(): void
    {
        $balances = InstructorBalance::query()->orderBy('instructor_id')->get();

        $this->say('');
        $this->say(sprintf(
            '  payments %d · allocations %d · ledger entries %d · instructors %d',
            SubscriptionPayment::query()->count(),
            RevenueAllocation::query()->count(),
            LedgerEntry::query()->count(),
            $balances->count(),
        ));

        $recognized = (int) $balances->sum('recognized_minor');
        $available = (int) $balances->sum('available_minor');

        $this->say(sprintf(
            '  recognized %s · available %s · corrections %s',
            MinorUnits::format($recognized),
            MinorUnits::format($available),
            MinorUnits::format((int) LedgerEntry::query()->where('type', 'release_correction')->sum('amount_minor')),
        ));

        // Invariant 3, on the seeded data rather than in a test: nothing is
        // claimed or paid yet, so recognized must equal available exactly.
        $this->say(sprintf(
            '  invariant 3  recognized == available + reserved + paid  %s',
            $recognized === $available + (int) $balances->sum('reserved_minor') + (int) $balances->sum('paid_minor') ? 'OK' : 'FAILED',
        ));

        // Invariant 1, on the seeded data: every confirmed payment is fully
        // split between its instructors and the platform.
        $unallocated = (int) DB::selectOne(
            'SELECT COUNT(*) AS mismatches FROM (
                 SELECT p.id
                   FROM subscription_payments p
                   LEFT JOIN revenue_allocations ra ON ra.payment_id = p.id
                  WHERE p.provider_reference IS NOT NULL
                  GROUP BY p.id, p.amount_minor, p.platform_cut_minor
                 HAVING COALESCE(SUM(ra.amount_minor), 0) + p.platform_cut_minor <> p.amount_minor
             ) AS per_payment'
        )->mismatches;

        $this->say(sprintf('  invariant 1  Σ allocations + platform_cut == amount  %s', $unallocated === 0 ? 'OK' : 'FAILED'));
        $this->say('');
        $this->say('  Next: php artisan payouts:run --month='.$this->today()->modify('-1 month')->format('Y-m'));
    }

    /** @return array<int, int> one to three distinct instructors, as §5.2 requires. */
    private function someInstructors(): array
    {
        $count = $this->rng->getInt(1, 3);
        $ids = [];

        while (count($ids) < $count) {
            $ids[$this->rng->getInt(1, self::INSTRUCTORS)] = true;
        }

        return array_keys($ids);
    }

    /**
     * §3.3: UTC, midnight-aligned, so whole-day arithmetic in §6 has no partial
     * day to round away.
     */
    private function randomStart(int $termDays): DateTimeImmutable
    {
        $daysBack = $this->rng->getInt(min($termDays, 25), self::MONTHS_OF_HISTORY * 30);

        return $this->today()->modify("-{$daysBack} days");
    }

    private function today(): DateTimeImmutable
    {
        return $this->utc(now()->utc()->startOfDay()->format('Y-m-d H:i:s'));
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function say(string $message): void
    {
        $this->command?->getOutput()->writeln($message);
    }
}
