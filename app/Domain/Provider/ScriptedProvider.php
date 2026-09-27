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
 *
 * Unconfigured, it answers nothing at all: a send() before script() or always()
 * is an UnscriptedProviderCall. This is the binding the testing environment
 * gets, so a test that moves money it never meant to move stops dead instead of
 * drawing a random outcome from RandomProvider and passing four times in five.
 */
final class ScriptedProvider extends FakeProvider
{
    /** @var list<Scenario> */
    private array $script = [];

    /**
     * The scenario every call gets once the script runs out. A test that only
     * cares about the first call therefore does not have to script the retries
     * a sweeper might trigger, and the call counts stay assertable either way.
     *
     * Null until the test says otherwise, and null is NOT a default outcome —
     * it is the absence of one. Defaulting this to Success would make the
     * unscripted send indistinguishable from a deliberate one.
     */
    private ?Scenario $fallback = null;

    /** Unscripted calls that were thrown on, for the tests/Pest.php guard to re-raise. */
    private int $unscriptedCalls = 0;

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

    /**
     * §11: every caller of send() sits inside a `catch (Throwable)`, because a
     * timeout is not a failure. So throwing alone would be swallowed into an
     * 'unknown' attempt and the test would fail somewhere else, for some other
     * reason. The count is what tests/Pest.php re-raises after the test, where
     * nothing can catch it.
     */
    public function unscriptedCalls(): int
    {
        return $this->unscriptedCalls;
    }

    protected function nextScenario(): Scenario
    {
        $scenario = array_shift($this->script) ?? $this->fallback;

        if ($scenario === null) {
            $this->unscriptedCalls++;

            throw new UnscriptedProviderCall(
                'Unscripted provider call: this test reached PaymentProvider::send() without '
                .'scripting an outcome. Call PayoutFixtures::scripted(Scenario::…) — or '
                .'->always(Scenario::…) — before the code under test sends, or fake the job '
                .'if it was not meant to send at all.'
            );
        }

        return $scenario;
    }
}
