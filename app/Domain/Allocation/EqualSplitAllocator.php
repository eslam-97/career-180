<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Money\LargestRemainder;
use InvalidArgumentException;

/**
 * §5.2: equal split across the distinct instructors whose courses the
 * subscription grants access to, determined at payment time.
 *
 * Choosing equal split is what makes allocate-once-at-payment available: a
 * consumption-weighted split cannot be allocated at payment time because the
 * basis does not exist until the period has elapsed (§5.2).
 */
final class EqualSplitAllocator implements RevenueAllocator
{
    /**
     * @param  array<int, int>  $instructorIds
     * @return array<int, array{amount_minor: int, weight_numerator: int, weight_denominator: int}>
     */
    public function allocate(int $poolMinor, array $instructorIds): array
    {
        // §5.2: "distinct instructors" — the same instructor teaching two of
        // the subscription's courses is one participant, not two.
        $ids = array_values(array_unique($instructorIds));
        sort($ids);

        if ($ids === []) {
            throw new InvalidArgumentException('Cannot allocate across an empty set of instructors.');
        }

        $count = count($ids);

        // §5.5: floor each share, hand the leftover out by descending
        // remainder with ties going to the lowest instructor id.
        $amounts = LargestRemainder::apportion($poolMinor, array_fill_keys($ids, 1));

        $allocations = [];

        foreach ($ids as $id) {
            $allocations[$id] = [
                'amount_minor' => $amounts[$id],
                // §3.1: the weight is stored as an exact rational — a three-way
                // split is 1/3, never 0.3333333333. It records *why* an
                // instructor received 9,334, for audit; amount_minor above
                // stays the canonical amount to reverse.
                'weight_numerator' => 1,
                'weight_denominator' => $count,
            ];
        }

        return $allocations;
    }
}
