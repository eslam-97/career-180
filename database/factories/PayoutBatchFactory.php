<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PayoutBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutBatch>
 */
class PayoutBatchFactory extends Factory
{
    protected $model = PayoutBatch::class;

    /**
     * §10.1 / §14: UNIQUE(period_start, period_end). Every instance used to
     * default to the current month, so two factory batches in one test now
     * collide. Each instance walks one month further back instead, which keeps
     * `PayoutBatch::factory()` usable wherever the period itself does not
     * matter — a fixture value the schema now requires, not a relaxed one.
     */
    private static int $monthsBack = 0;

    public function definition(): array
    {
        $periodStart = now()->utc()->startOfMonth()->subMonths(self::$monthsBack++)->toImmutable();

        return [
            'period_start' => $periodStart,
            'period_end' => $periodStart->endOfMonth()->startOfDay(),
            'cutoff_at' => now()->utc()->toImmutable(),
            // §10.1: frozen at batch creation. Zero means "claim nothing" until
            // a caller freezes a real high-water mark.
            'max_entry_id' => 0,
            'status' => 'open',
        ];
    }
}
