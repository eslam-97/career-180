<?php

declare(strict_types=1);

namespace App\Domain\Provider;

/**
 * §16.3: "both implementations keep a durable map keyed by idempotencyKey", and
 * the map is consulted the same way in both. The algorithm lives here once
 * rather than twice, because the property under test is that a replayed key
 * creates nothing new — a property that must not be able to drift between the
 * provider the tests use and the one the demo uses.
 *
 *     send(key)
 *         if key already seen  ->  return the stored result, create nothing
 *         create the transfer exactly once, store the result
 *         scenario TIMEOUT_AFTER_SUCCESS  ->  store SUCCESS, then throw
 *
 *     status(key)
 *         return the stored result, or UNKNOWN if still unresolved
 *
 * Subclasses decide only WHICH scenario a call gets: scripted for the suite,
 * seeded-random for the demo.
 */
abstract class FakeProvider implements PaymentProvider
{
    /** @var list<string> every key send() was called with, in call order — replays included. */
    protected array $sentKeys = [];

    /** @var list<string> every key status() was called with, in call order. */
    protected array $statusKeys = [];

    public function __construct(protected readonly FakeProviderTransfers $transfers) {}

    public function send(int $amountMinor, string $idempotencyKey): Outcome
    {
        $this->sentKeys[] = $idempotencyKey;

        // §11.3 / §11.4: "same key replayed to provider -> the provider returns
        // the stored result". No second transfer, and the scenario is not
        // consumed either: a replay is not a new call on the wire.
        $stored = $this->transfers->find($idempotencyKey);

        if ($stored !== null) {
            return $stored;
        }

        $scenario = $this->nextScenario();

        // Nothing recorded, and no answer: the worker died on the wire, or the
        // provider never replied and never processed it. status() will answer
        // UNKNOWN from here on, which is §11.2's road into 'unresolved'.
        if ($scenario === Scenario::TimeoutBeforeSend) {
            throw new ProviderTimeout("No response for {$idempotencyKey}.");
        }

        $outcome = $scenario === Scenario::Failure
            ? Outcome::failure()
            : Outcome::success('txf_'.substr($idempotencyKey, 0, 12));

        // The transfer, created exactly once. A failure stores a result too —
        // it is an answer, and a replay must return that answer rather than
        // trying again — but it moves no money, so it is not a transfer.
        $this->transfers->remember(
            $idempotencyKey,
            $amountMinor,
            $outcome,
            transferred: $outcome->status === OutcomeStatus::Success,
        );

        // §16.3: the money has moved and the caller will never hear it. Recorded
        // FIRST, thrown second — reverse the two lines and invariant 31 becomes
        // a test of our error handling instead of the money-already-moved case.
        if ($scenario === Scenario::TimeoutAfterSuccess) {
            throw new ProviderTimeout("No response for {$idempotencyKey}, after the transfer was made.");
        }

        return $outcome;
    }

    public function status(string $idempotencyKey): Outcome
    {
        $this->statusKeys[] = $idempotencyKey;

        // §16.3: "return the stored result, or UNKNOWN if still unresolved".
        return $this->transfers->find($idempotencyKey) ?? Outcome::unknown();
    }

    /** §16.3's two tests: a replayed key produces no second transfer. */
    public function transferCount(string $idempotencyKey): int
    {
        return $this->transfers->transferCount($idempotencyKey);
    }

    /** @return list<string> */
    public function sentKeys(): array
    {
        return $this->sentKeys;
    }

    public function sendCalls(): int
    {
        return count($this->sentKeys);
    }

    /** @return list<string> */
    public function statusKeys(): array
    {
        return $this->statusKeys;
    }

    public function statusCalls(): int
    {
        return count($this->statusKeys);
    }

    /**
     * §11.2: a definitive answer arriving a day later, from a low-rate
     * background poll, a settlement file, or a human reading the provider's
     * dashboard. It is still recorded and still authoritative.
     */
    public function resolveLate(string $idempotencyKey, Outcome $outcome): void
    {
        $this->transfers->resolveLate($idempotencyKey, $outcome);
    }

    abstract protected function nextScenario(): Scenario;
}
