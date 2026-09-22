<?php

declare(strict_types=1);

namespace App\Domain\Provider;

/**
 * §16.3: what both PaymentProvider methods return.
 *
 * The payload is stored verbatim on the attempt as response_payload — §10.2's
 * evidence trail, "what happened on a given date" — so it is carried here rather
 * than reconstructed by the caller.
 */
final readonly class Outcome
{
    /** @param  array<string, mixed>  $payload */
    private function __construct(
        public OutcomeStatus $status,
        public ?string $reference,
        public array $payload,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function success(string $reference, array $payload = []): self
    {
        return new self(
            OutcomeStatus::Success,
            $reference,
            $payload + ['outcome' => 'success', 'provider_reference' => $reference],
        );
    }

    /** @param  array<string, mixed>  $payload */
    public static function failure(array $payload = []): self
    {
        return new self(OutcomeStatus::Failure, null, $payload + ['outcome' => 'failure']);
    }

    /** @param  array<string, mixed>  $payload */
    public static function unknown(array $payload = []): self
    {
        return new self(OutcomeStatus::Unknown, null, $payload + ['outcome' => 'unknown']);
    }

    /**
     * §11: "definitive" is the word the doc uses for an answer that may be
     * recorded against an attempt. Unknown is not one.
     */
    public function isDefinitive(): bool
    {
        return $this->status !== OutcomeStatus::Unknown;
    }
}
