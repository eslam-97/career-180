<?php

declare(strict_types=1);

namespace App\Domain\Provider;

/**
 * §16.3: "ScriptedProvider drives outcomes per call for the test suite — no
 * randomness, no flaky assertions". One case per thing that can happen on the
 * wire, and the two timeout cases are deliberately different.
 */
enum Scenario
{
    case Success;

    case Failure;

    /**
     * §16.3: the brief's hardest scenario. The transfer IS recorded and THEN the
     * call throws — money has moved as far as the provider is concerned, and our
     * side never heard. A stateless stub cannot express this.
     */
    case TimeoutAfterSuccess;

    /**
     * The other half of the same uncertainty: nothing was recorded and the call
     * threw. status() answers UNKNOWN forever after, which is what drives the
     * §11.2 polling-exhaustion path into 'unresolved'.
     */
    case TimeoutBeforeSend;
}
