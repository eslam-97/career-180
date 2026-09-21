<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RevenueAllocation>
 */
class RevenueAllocationFactory extends Factory
{
    protected $model = RevenueAllocation::class;

    public function definition(): array
    {
        return [
            'payment_id' => SubscriptionPayment::factory(),
            'instructor_id' => fake()->numberBetween(1, 10_000),
            // §5.5: one third of a 28,000 pool, largest-remainder rounded.
            'amount_minor' => 9_334,
            // §3.1: the exact rational, never 0.3333333333.
            'weight_numerator' => 1,
            'weight_denominator' => 3,
            'created_at' => now()->utc(),
        ];
    }
}
