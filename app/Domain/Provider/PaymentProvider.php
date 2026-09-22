<?php

declare(strict_types=1);

namespace App\Domain\Provider;

/**
 * §16.3, verbatim.
 *
 * Two methods, because §11.3 needs two layers and both are required: the
 * idempotency key sent with the request lets the provider deduplicate a replay
 * server-side, and the status query by that same key covers the case where we
 * never learn the first attempt's outcome. The key alone is insufficient if the
 * provider does not honour it; polling alone leaves a window where a blind retry
 * would double-pay.
 *
 * send() may throw — a timeout is an expected outcome of a real call, and §11
 * turns it into 'unknown' rather than 'failed'. It must NEVER be assumed to mean
 * no money moved: the implementations model exactly that case (§16.3).
 */
interface PaymentProvider
{
    /** @throws ProviderTimeout when no answer arrives; the transfer may or may not have happened. */
    public function send(int $amountMinor, string $idempotencyKey): Outcome;

    public function status(string $idempotencyKey): Outcome;
}
