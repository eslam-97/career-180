<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        // §3.3: UTC, and aligned to midnight so whole-day arithmetic in §6 has
        // no partial day to round away.
        $startsAt = now()->utc()->startOfDay()->subDays(30)->toImmutable();

        return [
            'student_id' => fake()->numberBetween(1, 10_000),
            'plan_code' => 'quarterly',
            'amount_minor' => 35_000,
            // §3.2: a single settlement currency; the code is stored where
            // money enters.
            'currency' => 'EGP',
            'starts_at' => $startsAt,
            // §4: [starts_at, ends_at) — end exclusive, 90 whole days.
            'ends_at' => $startsAt->addDays(90),
            'cancelled_at' => null,
            'access_ends_at' => null,
            'status' => 'active',
        ];
    }

    /**
     * §6.1: auto-renew cancelled, term continues. Recognition is NOT capped —
     * this is deliberately distinct from terminating access.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelled_at' => $attributes['starts_at']->addDays(45),
            'status' => 'cancelled',
        ]);
    }

    /**
     * §6.1: access terminated mid-term. effective_days becomes 45, which caps
     * recognition through the clamp in §6.
     */
    public function accessTerminatedOnDay(int $day): static
    {
        return $this->state(fn (array $attributes) => [
            'access_ends_at' => $attributes['starts_at']->addDays($day),
        ]);
    }

    /**
     * §6.1: a full refund sets access_ends_at = starts_at, so effective_days is
     * 0 and the existing clamp claws everything back with no new code.
     */
    public function fullyRefunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_ends_at' => $attributes['starts_at'],
        ]);
    }
}
