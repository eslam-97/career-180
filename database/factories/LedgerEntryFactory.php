<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        $watermark = now()->utc()->startOfDay()->toImmutable();

        return [
            'instructor_id' => fake()->numberBetween(1, 10_000),
            'type' => 'release',
            // §3: SIGNED, so a correction state can simply negate this.
            'amount_minor' => 3_111,
            // §3.3: three dates, set independently. period_start is when the
            // row was POSTED (§6.3), recognized_through_at is the horizon it
            // accounts for, effective_at is the business event's instant.
            'period_start' => $watermark->startOfMonth(),
            'recognized_through_at' => $watermark,
            'effective_at' => $watermark,
            // §6.2: a scheduled release keys on the posting period, so
            // re-running a month is a no-op by constraint.
            'source_ref' => 'period:'.$watermark->format('Y-m'),
            'payout_id' => null,
            'created_at' => now()->utc(),
        ];
    }

    /**
     * §6.2: delta < 0 posts a release_correction. Keyed on the triggering
     * event, never on the period — otherwise a refund landing between two runs
     * of the same month would compute a delta it could not insert.
     */
    public function correction(int $amountMinor, string $sourceRef): static
    {
        return $this->state(fn () => [
            'type' => 'release_correction',
            'amount_minor' => $amountMinor,
            'source_ref' => $sourceRef,
        ]);
    }

    /**
     * §1.1: excluded from `posted`, because it is not derivable from
     * released(). Including it would make the release job reverse the refund.
     */
    public function refundAdjustment(int $amountMinor, string $sourceRef): static
    {
        return $this->state(fn () => [
            'type' => 'refund_adjustment',
            'amount_minor' => $amountMinor,
            'source_ref' => $sourceRef,
        ]);
    }
}
