<?php

declare(strict_types=1);

namespace App\Domain\Provider;

use Illuminate\Support\Facades\DB;

/**
 * §16.3: the durable map both fakes keep, keyed by idempotencyKey.
 *
 * This is the PRETEND PAYMENT COMPANY'S storage, not ours. Nothing outside
 * ScriptedProvider and RandomProvider may read or write fake_provider_transfers,
 * and no part of the money path may take a decision from it — the system under
 * test learns what happened only through send() and status(), exactly as it
 * would from a real provider.
 *
 * Durable rather than an in-process array for two reasons. It is what makes
 * "the same key replayed returns the stored result" (§11.4) a property of the
 * provider instead of a property of one PHP process, and the demo runs its
 * worker in a different process from the command that queued it.
 */
final class FakeProviderTransfers
{
    private const TABLE = 'fake_provider_transfers';

    /**
     * The result stored against this key, or null if the provider has never
     * resolved it. Null is what makes status() answer UNKNOWN (§16.3).
     */
    public function find(string $idempotencyKey): ?Outcome
    {
        $row = DB::table(self::TABLE)->where('idempotency_key', $idempotencyKey)->first();

        if ($row === null) {
            return null;
        }

        return match (OutcomeStatus::from($row->outcome)) {
            OutcomeStatus::Success => Outcome::success((string) $row->provider_reference),
            OutcomeStatus::Failure => Outcome::failure(),
            OutcomeStatus::Unknown => Outcome::unknown(),
        };
    }

    /**
     * Store the result for this key exactly once. insertOrIgnore, so a second
     * send() with the same key cannot create a second transfer even if the
     * caller raced past find() — the provider's own version of §9.1's
     * "constraints are correctness".
     */
    public function remember(string $idempotencyKey, int $amountMinor, Outcome $outcome, bool $transferred): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'amount_minor' => $amountMinor,
            'outcome' => $outcome->status->value,
            'transferred' => $transferred,
            'provider_reference' => $outcome->reference,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }

    /**
     * §11.2: the answer that turns up a day later — from a settlement file, or a
     * human reading the provider's dashboard. It changes what the provider will
     * say from now on; it does not create a transfer that was not made.
     */
    public function resolveLate(string $idempotencyKey, Outcome $outcome): void
    {
        $existing = DB::table(self::TABLE)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing === null) {
            $this->remember($idempotencyKey, 0, $outcome, $outcome->status === OutcomeStatus::Success);

            return;
        }

        DB::table(self::TABLE)->where('idempotency_key', $idempotencyKey)->update([
            'outcome' => $outcome->status->value,
            'transferred' => $outcome->status === OutcomeStatus::Success,
            'provider_reference' => $outcome->reference,
            'updated_at' => now()->utc(),
        ]);
    }

    /**
     * How many transfers this key actually produced on the provider's side.
     * Invariant 27 and invariant 31 assert on this directly rather than on our
     * own attempt rows, which is the only way either test is not vacuous.
     */
    public function transferCount(string $idempotencyKey): int
    {
        return DB::table(self::TABLE)
            ->where('idempotency_key', $idempotencyKey)
            ->where('transferred', true)
            ->count();
    }
}
