<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InstructorBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorBalance>
 */
class InstructorBalanceFactory extends Factory
{
    protected $model = InstructorBalance::class;

    public function definition(): array
    {
        return [
            'instructor_id' => fake()->unique()->numberBetween(1, 10_000),
            'recognized_minor' => 0,
            'available_minor' => 0,
            'reserved_minor' => 0,
            'paid_minor' => 0,
            // §6.2: a null watermark means nothing has been posted yet, so the
            // first scheduled run has nothing to be a no-op against.
            'recognized_through_at' => null,
        ];
    }
}
