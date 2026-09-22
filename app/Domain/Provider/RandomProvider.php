<?php

declare(strict_types=1);

namespace App\Domain\Provider;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * §16.3: "RandomProvider drives the demo, seeded, so a re-recorded
 * demonstration produces the same failure sequence."
 *
 * Same behaviour as ScriptedProvider in every respect that matters — the same
 * durable map, the same replay branch, the same record-then-throw ordering
 * (FakeProvider) — differing only in how a scenario is chosen. The weights put
 * every interesting path on screen in a short demo without making success rare.
 */
final class RandomProvider extends FakeProvider
{
    /**
     * Cumulative weights out of 100. Timeouts are over-represented on purpose:
     * they are the paths §11 exists for, and a demo where they never fire shows
     * nothing.
     */
    private const WEIGHTS = [
        [70, Scenario::Success],
        [80, Scenario::Failure],
        [92, Scenario::TimeoutAfterSuccess],
        [100, Scenario::TimeoutBeforeSend],
    ];

    private readonly Randomizer $randomizer;

    public function __construct(FakeProviderTransfers $transfers, ?int $seed = null)
    {
        parent::__construct($transfers);

        // Seeded, never the default engine: an unseeded demo cannot be
        // re-recorded, and a bug found in one run could not be reproduced.
        $this->randomizer = new Randomizer(new Mt19937($seed ?? (int) config('payouts.demo_provider_seed')));
    }

    protected function nextScenario(): Scenario
    {
        $roll = $this->randomizer->getInt(1, 100);

        foreach (self::WEIGHTS as [$upperBound, $scenario]) {
            if ($roll <= $upperBound) {
                return $scenario;
            }
        }

        return Scenario::Success;
    }
}
