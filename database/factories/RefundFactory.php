<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Refund;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'payment_id' => SubscriptionPayment::factory(),
            'amount_minor' => 5_000,
            'kind' => 'termination_prorata',
            'reason' => 'Student terminated mid-term.',
            // §3.3: the refund's business date, which is not the date it was
            // processed. The worked example has them 19 days apart.
            'effective_at' => now()->utc()->startOfDay()->subDays(19)->toImmutable(),
            'provider' => 'scripted',
            'provider_reference' => 'rfnd_'.Str::random(16),
            'processed_at' => now()->utc()->toImmutable(),
        ];
    }

    /**
     * §6.1: access ends, effective_days becomes 0, the clamp claws back
     * everything already recognized.
     */
    public function terminationFull(): static
    {
        return $this->state(fn () => ['kind' => 'termination_full']);
    }

    /**
     * §6.1: reduces entitlement without reducing access — the one case that
     * needs the refund_adjustment ledger type, and why §1.1 excludes it from
     * `posted`.
     */
    public function goodwillPartial(): static
    {
        return $this->state(fn () => ['kind' => 'goodwill_partial']);
    }
}
