<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Domain\Provider\Scenario;
use App\Domain\Refund\RefundPolicy;
use App\Jobs\CorrectInstructorRecognition;
use App\Jobs\SendPayoutAttempt;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;

/**
 * §11.4: "Refund after payout settled → correction → negative available → nets
 * against future", and the two rows around it — "Refund before release → clamp
 * stops recognition → no clawback" and "Claimed set nets to zero or negative →
 * transaction rolled back → no payout, no transfer".
 *
 * The money has already left the building. There is nothing to claw back and no
 * attempt is made to: the correction is a negative ledger row, `available` goes
 * negative, and that debt nets against the instructor's next recognition with
 * no manual step and no carry-forward mechanism. Invariant 33.
 *
 * §7's two halves both fall out of moving ONE column. Unearned money simply
 * never becomes payable, because a shortened `access_ends_at` shortens
 * `effective_days`. Already-recognized money produces a negative row, because
 * the same shortened cap makes expected(W) smaller than posted and §6.2's delta
 * goes negative on its own. Neither case is coded as a special case.
 */
final class DemoRefundAfterPayout extends DemoCommand
{
    protected $signature = 'demo:refund-after-payout';

    protected $description = '§11.4: a refund lands after the payout settled — the correction drives available negative, and future recognition clears it';

    protected function matrixRow(): string
    {
        return 'Refund after payout settled → correction → negative available → nets against future';
    }

    protected function scenario(): void
    {
        $instructorId = $this->world->freshInstructorId();

        $payment = $this->recognizeAndPay($instructorId);
        $paid = $this->buckets($instructorId, 'Paid: 30 of 90 days recognized, claimed and settled');

        $negative = $this->theRefund($instructorId, $payment, $paid);
        $this->futureRecognitionClearsIt($instructorId, $negative);

        $this->moneyOutcome('nets against future — no clawback, no manual step, no carry-forward mechanism');
    }

    /** The whole pipeline, up to money genuinely out the door. */
    private function recognizeAndPay(int $instructorId): SubscriptionPayment
    {
        $month = $this->world->freshMonth();

        $this->step('Setup: a 90-day subscription at 900.00, one month recognized, claimed and paid');

        $payment = $this->world->paidSubscription(
            [$instructorId],
            amountMinor: 90_000,
            platformRateBps: 2_000,
            termStart: DemoWorld::utc('2023-01-01 00:00:00'),
            termDays: 90,
        );

        // §6: released(2023-01-31) = floor(72,000 × 30 / 90) = 24,000.
        $this->world->release($instructorId, '2023-01');

        $payout = Payout::query()->findOrFail($this->world->claim($instructorId, $this->world->batch($month))->payoutId);

        $this->scripted(Scenario::Success);
        $this->runJob(new SendPayoutAttempt($payout->id));

        $this->payouts($instructorId);

        $this->check("the payout is 'settled' — the money has left", Payout::query()->find($payout->id)->status === 'settled');

        return $payment;
    }

    /**
     * §6.1 / §7.1: a prorated termination refund ends access at the refund's
     * effective date. Everything else is arithmetic that was already there.
     */
    private function theRefund(int $instructorId, SubscriptionPayment $payment, array $paid): array
    {
        $this->step('A termination refund lands, effective 2023-01-15 — two weeks into a 90-day term');

        $refund = $this->world->refund(
            $payment,
            // §7.1: the unearned portion of the term, by the stated assumption.
            amountMinor: 76_000,
            kind: RefundPolicy::TERMINATION_PRORATA,
            effectiveAt: DemoWorld::utc('2023-01-15 00:00:00'),
        );

        $subscription = Subscription::query()->findOrFail($payment->subscription_id);

        $this->say(sprintf(
            'refund %d · %s · kind %s → access_ends_at moved to %s',
            $refund->id,
            DemoLedger::money($refund->amount_minor),
            $refund->kind,
            $subscription->access_ends_at?->format('Y-m-d') ?? 'unchanged',
        ));
        $this->note('one column moved. No clawback code exists, and none runs.');

        $this->step('§6.1: the refund dispatches a TARGETED recompute, rather than waiting for the month');

        // §6.1: "otherwise a payout could go out in a window where the refund
        // exists but the corresponding correction is not yet in the ledger".
        $this->runJob(new CorrectInstructorRecognition(
            $instructorId,
            // §6.2: keyed on the EVENT, never on the period.
            'refund:'.$refund->id,
            // §3.3: the refund's own business date, unclamped.
            $refund->effective_at->format('Y-m-d H:i:s'),
        ));

        $negative = $this->buckets($instructorId, 'After the correction — available goes negative', $paid);
        $this->entries($instructorId);

        $this->say('expected(W) fell from 24,000 to floor(72,000 × 14 / 90) = 11,200, so §6.2\'s');
        $this->say('cumulative delta came out at −12,800 and posted itself as a release_correction.');

        $this->check('§6.2  the correction is a negative release_correction row', $negative['recognized'] === 11_200);
        $this->checkEquals('§10.4  available is negative — debt, held on the balance row', -12_800, $negative['available']);
        $this->checkEquals('nothing was clawed back from the settled payout', $paid['paid'], $negative['paid']);
        $this->check('§6.2  a correction does not advance the watermark (invariant 15)', $this->watermark($instructorId) === '2023-01-31 00:00:00');

        $this->step('§10.4: a claim whose set nets to zero or less produces no payout at all');

        $result = $this->world->claim($instructorId, $this->world->batch($this->world->freshMonth()));

        $this->check('the claim rolled back — no payout row, entries stay unclaimed', $result->payoutId === null);
        $this->note('debt is not a payout; the transaction is discarded whole rather than writing a non-positive row');

        return $negative;
    }

    /** Invariant 33: a net-negative balance is cleared by future recognition with no manual step. */
    private function futureRecognitionClearsIt(int $instructorId, array $negative): void
    {
        $this->step('The instructor sells again in February — and the debt clears itself');

        $this->world->paidSubscription(
            [$instructorId],
            amountMinor: 90_000,
            platformRateBps: 2_000,
            termStart: DemoWorld::utc('2023-02-01 00:00:00'),
            termDays: 90,
        );

        // §6.2: always the cumulative total, never an increment. The February
        // run recomputes expected(t) across BOTH subscriptions — the terminated
        // one is still capped at 11,200 — and posts the difference.
        $this->world->release($instructorId, '2023-02');

        $cleared = $this->buckets($instructorId, 'After February\'s release — the debt is netted off', $negative);
        $this->entries($instructorId);

        $this->checkEquals('invariant 33  available is positive again, with no manual step', 8_800, $cleared['available']);
        $this->check('§6.1  the terminated subscription is still capped — no recognition resumed', $cleared['recognized'] === 32_800);
        $this->check('invariant 6  the correction was not reversed by the later release run', $this->correctionStillPosted($instructorId));

        $this->step('And the netted balance is payable by the ordinary claim');

        $result = $this->world->claim($instructorId, $this->world->batch($this->world->freshMonth()));
        $payout = $result->payoutId === null ? null : Payout::query()->find($result->payoutId);

        $this->payouts($instructorId);

        $this->checkEquals('the next batch claims the net, not the gross', 8_800, (int) ($payout?->amount_minor ?? 0));
        $this->say('The claim swept the release, the correction and the new release together. There is');
        $this->say('no carry-forward mechanism because none is needed: the entries were always there.');
    }

    private function watermark(int $instructorId): string
    {
        return (string) DB::table('instructor_balances')
            ->where('instructor_id', $instructorId)
            ->value('recognized_through_at');
    }

    /** §1.1: `posted` is release + release_correction, and the correction is still in it. */
    private function correctionStillPosted(int $instructorId): bool
    {
        // Cast: MySQL hands SUM() back as a string through PDO, so a strict
        // comparison against an int would be false whatever the ledger says.
        return (int) LedgerEntry::query()
            ->where('instructor_id', $instructorId)
            ->where('type', 'release_correction')
            ->sum('amount_minor') === -12_800;
    }
}
