<?php

declare(strict_types=1);

namespace App\Domain\Payout;

/**
 * §10.3: what the claim transaction reports back, so the caller can tell a
 * payout THIS invocation created from one that was already there.
 *
 * The distinction exists for exactly one reason: "COMMIT — then, and only then
 * — acquire slot + create attempt". The claim job dispatches that attempt, and
 * it must dispatch it once per payout, not once per claim invocation. A bare
 * payout id cannot express that — §11.4's replay branch returns a perfectly
 * good id for a payout whose attempt may already be in flight.
 *
 * `created` is decided INSIDE the claim transaction, on the same branch that
 * inserted the row, and travels out as its return value. Deriving it from a
 * separate "does a payout exist?" read before or after the transaction would
 * let two concurrent workers both answer "I created it" (§9.4).
 */
final readonly class ClaimResult
{
    private function __construct(
        public ?int $payoutId,
        public bool $created,
    ) {}

    /** This invocation inserted the payout and committed it (§10.4). */
    public static function created(int $payoutId): self
    {
        return new self($payoutId, true);
    }

    /** §11.4, "command run twice": the batch already had this instructor's payout. */
    public static function existing(int $payoutId): self
    {
        return new self($payoutId, false);
    }

    /**
     * No payout at all: no balance row to serialise on (§9.4), nothing
     * eligible, or a claimed sum of zero or less rolled back (§10.4).
     */
    public static function none(): self
    {
        return new self(null, false);
    }

    /**
     * The payout id, but only when this invocation created it — the one value
     * it is safe to start an attempt for. Null on a replay and on no payout
     * alike, so the caller needs a single branch rather than a bool combined
     * with a nullable int.
     */
    public function createdPayoutId(): ?int
    {
        return $this->created ? $this->payoutId : null;
    }
}
