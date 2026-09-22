<?php

declare(strict_types=1);

namespace App\Domain\Payout;

/**
 * §10.2: what a worker gets when it wins the slot — everything it needs to make
 * the provider call, read under the locks and frozen at that moment.
 *
 * A plain value object rather than an Eloquent model, because AttemptService
 * carries a connection seam (§16.2) and a model would silently read the default
 * connection back.
 */
final readonly class AttemptSlot
{
    public function __construct(
        public int $id,
        public int $payoutId,
        public int $instructorId,
        public int $attemptNo,
        public string $idempotencyKey,
        public int $amountMinor,
    ) {}
}
