<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payout;
use App\Models\PayoutAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutAttempt>
 */
class PayoutAttemptFactory extends Factory
{
    protected $model = PayoutAttempt::class;

    public function definition(): array
    {
        $startedAt = now()->utc()->toImmutable();

        return [
            'payout_id' => Payout::factory(),
            'attempt_no' => 1,
            // §9.1: derived deterministically, so a re-run produces the SAME
            // key and the insert simply fails rather than creating a duplicate.
            // The real derivation lives in the §10.2 service; this mirrors its
            // shape so factory rows collide the way production ones do.
            'idempotency_key' => hash('sha256', 'attempt:'.fake()->unique()->numberBetween(1, 1_000_000).':1'),
            // §10.2: an attempt row is created already in 'sending'. There is
            // no 'pending' attempt state.
            'status' => 'sending',
            'provider_reference' => null,
            'request_payload' => ['amount_minor' => 4_667],
            'response_payload' => null,
            'resolution_evidence' => null,
            'started_at' => $startedAt,
            'lease_expires_at' => $startedAt->addMinutes(5),
            'polled_at' => null,
            'poll_count' => 0,
        ];
    }

    /**
     * §11.1: the lease sweeper's only legal transition. From here the normal
     * status-polling path applies, and active_payout_id still blocks a second
     * attempt underneath it.
     */
    public function unknown(): static
    {
        return $this->state(fn () => ['status' => 'unknown']);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => 'succeeded',
            'provider_reference' => 'txf_'.fake()->unique()->numberBetween(1, 1_000_000),
            'response_payload' => ['outcome' => 'success'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'response_payload' => ['outcome' => 'failure'],
        ]);
    }

    /**
     * §11.2: "the provider has not told us yet", not "it failed". A definitive
     * answer arriving later is still recorded and still authoritative.
     */
    public function unresolved(): static
    {
        return $this->state(fn () => [
            'status' => 'unresolved',
            'poll_count' => 8,
            'polled_at' => now()->utc()->toImmutable(),
        ]);
    }
}
