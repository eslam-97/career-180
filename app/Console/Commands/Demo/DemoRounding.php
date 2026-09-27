<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Domain\Money\Bps;
use App\Domain\Money\LargestRemainder;
use App\Domain\Recognition\ReleaseCalculator;
use App\Models\RevenueAllocation;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * §11.4: "Rounding → pool floored, largest remainder, platform residual → sums
 * exact, always".
 *
 * The only scenario in the matrix with no failure in it. It is here because the
 * piastre that goes missing in a rounding bug goes missing silently, on every
 * payment, forever — and because §5.4, §5.5 and §8 are one decision stated
 * three times: the platform is the residual claimant, at allocation and at
 * release alike.
 */
final class DemoRounding extends DemoCommand
{
    protected $signature = 'demo:rounding';

    protected $description = '§11.4: the pool is floored, the leftover piastre is apportioned by largest remainder, and the sums are exact';

    /** Enough to make a rounding bug certain to show, and instant to run. */
    private const RANDOMISED_CASES = 5_000;

    protected function matrixRow(): string
    {
        return 'Rounding → pool floored, largest remainder, platform residual → sums exact, always';
    }

    protected function scenario(): void
    {
        $this->floorThePool();
        $this->largestRemainder();
        $this->magnitudesNotSignedValues();
        $this->throughTheRealAllocator();
        $this->theResidualAtReleaseTime();
        $this->randomisedInputs();

        $this->moneyOutcome('sums exact, always — the platform absorbs the sub-piastre, at allocation and at release');
    }

    /** §5.4: `pool = floor(gross × (10000 − bps) / 10000)`, `cut = gross − pool`. */
    private function floorThePool(): void
    {
        $this->step('§5.4  Floor the pool, derive the cut — never the other way round');
        $this->note('the obvious alternative also sums exactly, and hands the residue to instructors');

        $rows = [];

        foreach ([[10_001, 2_000], [35_000, 2_000], [9_999, 3_333], [1, 10_000]] as [$gross, $bps]) {
            $pool = Bps::pool($gross, $bps);
            $cut = Bps::cut($gross, $bps);

            // The rejected direction, computed here purely so the difference is
            // on screen. Nothing in the money path computes the cut this way.
            $rejectedCut = intdiv($gross * $bps, Bps::SCALE);

            $rows[] = [
                DemoLedger::money($gross),
                $bps,
                DemoLedger::money($pool),
                DemoLedger::money($cut),
                DemoLedger::money($gross - $rejectedCut),
                DemoLedger::money($rejectedCut),
            ];

            $this->checkEquals("invariant 2  pool + cut == gross at {$gross} / {$bps} bps", $gross, $pool + $cut);
            $this->check("invariant 2  pool <= gross at {$gross} / {$bps} bps", $pool <= $gross);
        }

        $this->table(
            ['gross', 'bps', 'pool (ours)', 'cut (ours)', 'pool (rejected)', 'cut (rejected)'],
            $rows,
            'box',
        );

        $this->say('Both columns sum exactly. They differ on who absorbs the sub-piastre, and §8');
        $this->say('makes the platform the residual claimant at release time — so it is the platform');
        $this->say('at allocation time too, rather than each half quietly disagreeing.');
    }

    /** §5.5: Hamilton's method, ties to the lowest instructor id. */
    private function largestRemainder(): void
    {
        $this->step('§5.5  The leftover piastre goes out by largest remainder, not to the last party');

        $pool = Bps::pool(35_000, 2_000);
        $shares = LargestRemainder::apportion($pool, [3 => 1, 7 => 1, 12 => 1]);

        $this->say('gross 35,000 · cut 7,000 at 2000 bps · pool 28,000 ÷ 3 = 9,333.33…');
        $this->table(
            ['instructor', 'floor', 'largest remainder'],
            [
                ['3', DemoLedger::money(9_333), DemoLedger::money($shares[3])],
                ['7', DemoLedger::money(9_333), DemoLedger::money($shares[7])],
                ['12', DemoLedger::money(9_333), DemoLedger::money($shares[12])],
                ['<options=bold>Σ</>', DemoLedger::money(27_999), '<options=bold>'.DemoLedger::money(array_sum($shares)).'</>'],
            ],
            'box',
        );

        $this->checkEquals('§5.5  the shares sum to the pool exactly', $pool, array_sum($shares));
        $this->checkEquals('§5.5  the tie is broken by the lowest instructor id', 9_334, $shares[3]);
        $this->say('Maximum deviation from the exact share is one piastre, for every participant —');
        $this->say('not the whole residue concentrated on whoever the loop happened to end on.');
    }

    /** Hard rule 7 / §5.5: largest remainder applies to magnitudes, never to signed values. */
    private function magnitudesNotSignedValues(): void
    {
        $this->step('§5.5  Largest remainder is applied to magnitudes, never to signed values');

        // §5.5: "floor(-28000/3) is -9334, and three of those overshoot to
        // -28,002". A true floor, computed in integers: intdiv() rounds toward
        // zero, so it would answer -9333 here and hide the very bug this shows.
        $flooredShare = intdiv(-28_000, 3) - ((-28_000 % 3 !== 0) ? 1 : 0);
        $naive = 3 * $flooredShare;
        $correct = -array_sum(LargestRemainder::apportion(28_000, [3 => 1, 7 => 1, 12 => 1]));

        $this->table(
            ['approach', 'Σ of the three shares', 'target'],
            [
                ['floor the signed value (-9,334 × 3)', DemoLedger::money($naive), DemoLedger::money(-28_000)],
                ['apportion the magnitude, then negate', DemoLedger::money($correct), DemoLedger::money(-28_000)],
            ],
            'box',
        );

        $this->checkEquals('§5.5  apportioning the magnitude reverses exactly', -28_000, $correct);
        $this->check('§5.5  apportioning the signed value would overshoot by 2', $naive === -28_002);
        $this->note('LargestRemainder::apportion() refuses a negative total outright, so this cannot be written by accident');
    }

    /** The same rules, through InitiatePaymentService and AllocationService, on real rows. */
    private function throughTheRealAllocator(): void
    {
        $this->step('The same arithmetic, through the real services, onto real rows');

        $first = $this->world->freshInstructorId();
        $instructorIds = [$first, $first + 1, $first + 2];

        $payment = $this->world->paidSubscription(
            $instructorIds,
            amountMinor: 35_000,
            platformRateBps: 2_000,
            termStart: DemoWorld::utc('2023-01-01 00:00:00'),
            termDays: 90,
        );

        $allocations = RevenueAllocation::query()
            ->where('payment_id', $payment->id)
            ->orderBy('instructor_id')
            ->get();

        $this->table(
            ['instructor', 'amount_minor', 'weight'],
            $allocations->map(fn (RevenueAllocation $allocation): array => [
                (string) $allocation->instructor_id,
                DemoLedger::money($allocation->amount_minor),
                // §3.1: the rule as an exact rational, for audit — 1/3, never 0.3333.
                $allocation->weight_numerator.'/'.$allocation->weight_denominator,
            ])->all(),
            'box',
        );

        $allocated = (int) $allocations->sum('amount_minor');

        // Invariant 1, on live rows rather than in a test.
        $this->checkEquals(
            'invariant 1  Σ allocations + platform_cut == payment.amount',
            $payment->amount_minor,
            $allocated + $payment->platform_cut_minor,
        );
        $this->checkEquals('§5.5  the leftover piastre went to the lowest instructor id', 9_334, (int) $allocations->first()->amount_minor);
    }

    /** §8 / invariant 11: the platform share at release time is a residual, and never negative. */
    private function theResidualAtReleaseTime(): void
    {
        $this->step('§8  At release time the platform share is a residual too — and never negative');

        $gross = 35_000;
        $pool = Bps::pool($gross, 2_000);
        $shares = LargestRemainder::apportion($pool, [1 => 1, 2 => 1, 3 => 1]);
        $termDays = 90;

        $rows = [];

        foreach ([0, 1, 30, 45, 89, 90] as $elapsed) {
            $grossReleased = ReleaseCalculator::releasedFor($gross, $elapsed, $termDays);

            $instructorReleased = 0;

            foreach ($shares as $share) {
                // §8 precondition 3: the gross side and every instructor share
                // release against the SAME clamped fraction. Same (elapsed,
                // term) pair, passed to both — not two expressions that agree.
                $instructorReleased += ReleaseCalculator::releasedFor($share, $elapsed, $termDays);
            }

            $residual = $grossReleased - $instructorReleased;

            $rows[] = [
                (string) $elapsed,
                DemoLedger::money($grossReleased),
                DemoLedger::money($instructorReleased),
                DemoLedger::money($residual),
            ];

            $this->check("invariant 11  platform_released >= 0 at day {$elapsed}", $residual >= 0);
        }

        $this->table(['elapsed days', 'gross released', 'Σ instructor released', 'platform residual'], $rows, 'box');
        $this->note('day 90 releases the whole gross: 35,000 = 28,000 + 7,000, the frozen cut exactly');
    }

    /** §5.5: "asserted, not assumed: exactly, for randomised inputs". */
    private function randomisedInputs(): void
    {
        $this->step(sprintf('%s randomised inputs — the identities hold exactly, or this exits non-zero', number_format(self::RANDOMISED_CASES)));

        // Seeded, for the same reason §16.3 seeds the demo provider: a
        // re-recorded demonstration must produce the same sequence.
        $rng = new Randomizer(new Mt19937((int) config('payouts.demo_provider_seed')));

        $allocationMismatches = 0;
        $residualMismatches = 0;

        for ($i = 0; $i < self::RANDOMISED_CASES; $i++) {
            $gross = $rng->getInt(1, 100_000_000);
            $bps = $rng->getInt(0, Bps::SCALE);
            $count = $rng->getInt(1, 8);

            $pool = Bps::pool($gross, $bps);
            $cut = Bps::cut($gross, $bps);
            $shares = LargestRemainder::apportion($pool, array_fill_keys(range(1, $count), 1));

            // Invariant 1 and invariant 2, together.
            if (array_sum($shares) + $cut !== $gross || $pool > $gross) {
                $allocationMismatches++;
            }

            $termDays = $rng->getInt(1, 366);
            $elapsed = $rng->getInt(0, $termDays);

            $released = 0;

            foreach ($shares as $share) {
                $released += ReleaseCalculator::releasedFor($share, $elapsed, $termDays);
            }

            // Invariant 11.
            if (ReleaseCalculator::releasedFor($gross, $elapsed, $termDays) - $released < 0) {
                $residualMismatches++;
            }
        }

        $this->checkEquals('invariants 1 and 2  Σ allocations + cut == gross, and pool <= gross', 0, $allocationMismatches);
        $this->checkEquals('invariant 11  platform_released >= 0, for every randomised term and instant', 0, $residualMismatches);
    }
}
