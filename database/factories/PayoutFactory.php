<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payout;
use App\Models\PayoutBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        return [
            'batch_id' => PayoutBatch::factory(),
            'instructor_id' => fake()->numberBetween(1, 10_000),
            // §10.4: a committed payout always has a positive amount. The NULL
            // state exists only inside the uncommitted claim transaction, so a
            // factory must never produce it.
            'amount_minor' => 4_667,
            'status' => 'pending',
            'attempt_count' => 0,
            'settled_at' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'attempt_count' => 1,
        ]);
    }

    public function settled(): static
    {
        return $this->state(fn () => [
            'status' => 'settled',
            'attempt_count' => 1,
            'settled_at' => now()->utc()->toImmutable(),
        ]);
    }

    /**
     * §10.7: non-terminal for money — its entries stay reserved — but terminal
     * for automation. No sweeper may act on it.
     */
    public function needsReview(): static
    {
        return $this->state(fn () => [
            'status' => 'needs_review',
            'attempt_count' => 1,
        ]);
    }
}
