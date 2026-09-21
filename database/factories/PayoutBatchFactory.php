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

    public function definition(): array
    {
        $periodStart = now()->utc()->startOfMonth()->toImmutable();

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
