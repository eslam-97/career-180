<?php

declare(strict_types=1);

namespace App\Domain\Provider;

/**
 * §16.3: "ScriptedProvider drives outcomes per call for the test suite — no
 * randomness, no flaky assertions."
 *
 * Outcomes are set per call, in order. Everything else — the durable map, the
 * replay branch, recording before throwing — is FakeProvider's, and is the same
 * code the demo provider runs.
 */
final class ScriptedProvider extends FakeProvider
{
    /** @var list<Scenario> */
    private array $script = [];

    /**
     * The scenario every call gets once the script runs out. A test that only
     * cares about the first call therefore does not have to script the retries
     * a sweeper might trigger, and the call counts stay assertable either way.
     */
    private Scenario $fallback = Scenario::Success;

    public function script(Scenario ...$scenarios): self
    {
        $this->script = array_values($scenarios);

        if ($scenarios !== []) {
            $this->fallback = $scenarios[array_key_last($scenarios)];
        }

        return $this;
    }

    public function always(Scenario $scenario): self
    {
        $this->script = [];
        $this->fallback = $scenario;

        return $this;
    }

    protected function nextScenario(): Scenario
    {
        return array_shift($this->script) ?? $this->fallback;
    }
}
