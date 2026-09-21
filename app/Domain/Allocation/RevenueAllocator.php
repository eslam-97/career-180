<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * §5.2: the split rule sits behind this interface, returning
 * [instructorId => [amount_minor, weight_numerator, weight_denominator]].
 * Nothing downstream knows how the split was derived.
 */
interface RevenueAllocator
{
    /**
     * Split the instructor pool across the instructors the subscription grants
     * access to. The pool is passed in, not the gross: §5.4 computes the pool
     * and this splits it, so `sum(amount_minor) === pool` is checkable in one
     * place — which is precondition 1 of the §8 proof.
     *
     * @param  int  $poolMinor  the instructor pool, already net of the platform cut (§5.4)
     * @param  array<int, int>  $instructorIds
     * @return array<int, array{amount_minor: int, weight_numerator: int, weight_denominator: int}>
     */
    public function allocate(int $poolMinor, array $instructorIds): array;
}
