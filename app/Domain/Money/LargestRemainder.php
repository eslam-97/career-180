<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Hamilton's method (largest remainder) with a deterministic tie-break.
 *
 * §5.5: floor each share, then distribute the leftover one unit at a time by
 * descending remainder, ties broken by ascending instructor id. Maximum
 * deviation from the exact share is one piastre for every participant.
 *
 * Post-condition, asserted by invariant 1: sum(result) === $total, exactly.
 */
final class LargestRemainder
{
    /**
     * @param  int  $total  the magnitude to apportion
     * @param  array<int, int>  $weights  instructorId => weight
     * @return array<int, int> instructorId => amount_minor, in the input key order
     */
    public static function apportion(int $total, array $weights): array
    {
        // §5.5: largest remainder is applied to magnitudes, never to signed
        // values. floor(-28000/3) is -9334 and three of those overshoot to
        // -28,002. A caller with a signed amount must round the magnitude and
        // negate afterwards (§3.1).
        if ($total < 0) {
            throw new InvalidArgumentException("Largest remainder applies to magnitudes only, got total {$total}.");
        }

        if ($weights === []) {
            throw new InvalidArgumentException('Cannot apportion across an empty set of weights.');
        }

        foreach ($weights as $key => $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException("Weight for key {$key} must be non-negative, got {$weight}.");
            }
        }

        $totalWeight = array_sum($weights);

        // §3.1: weight_denominator has CHECK (> 0); a zero total weight has no
        // meaningful split and must not silently divide by zero.
        if ($totalWeight <= 0) {
            throw new InvalidArgumentException('Total weight must be greater than zero.');
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            // §3: no floats. The remainder is the exact integer numerator left
            // over, not a ratio, so comparisons below stay exact.
            $shares[$key] = intdiv($total * $weight, $totalWeight);
            $remainders[$key] = $total * $weight - $shares[$key] * $totalWeight;
        }

        $leftover = $total - array_sum($shares);

        $order = array_keys($weights);

        // §5.5: descending remainder, ties broken by ascending instructor id.
        // The tie-break is explicit rather than leaning on sort stability, so
        // the answer is the same whatever order the caller supplied.
        usort($order, function (int|string $a, int|string $b) use ($remainders): int {
            return $remainders[$b] <=> $remainders[$a]
                ?: $a <=> $b;
        });

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i]]++;
        }

        return $shares;
    }
}
