<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReconciliationAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationAlert>
 */
class ReconciliationAlertFactory extends Factory
{
    protected $model = ReconciliationAlert::class;

    public function definition(): array
    {
        return [
            // §10.6: the one path in the design knowingly unrecoverable by
            // machine — it is detected and alerted, never silently absorbed.
            'kind' => 'late_success_on_failed_payout',
            'subject_type' => 'payout',
            'subject_id' => fake()->numberBetween(1, 10_000),
            'detail' => ['note' => 'Provider confirmed a transfer for an already-failed payout.'],
            'detected_at' => now()->utc()->toImmutable(),
            'resolved_at' => null,
        ];
    }
}
