<?php

declare(strict_types=1);

namespace App\Domain\Provider;

use RuntimeException;

/**
 * §16.3: "ScriptedProvider drives outcomes per call for the test suite — no
 * randomness, no flaky assertions."
 *
 * Thrown when a test reaches the provider without having said what it should
 * answer. The alternative is worse than an error: the call would succeed or
 * fail at random, so a test that sends a payment it never meant to send would
 * pass most of the time and fail on someone else's machine.
 *
 * Every provider call in this system sits inside a `catch (Throwable)` — §11's
 * rule that silence is not failure — so this exception on its own is caught and
 * recorded as an 'unknown' attempt rather than surfacing. That is why
 * ScriptedProvider counts the call as well as throwing it, and why tests/Pest.php
 * re-raises the count after each test. Both halves are needed; neither is
 * decoration.
 */
final class UnscriptedProviderCall extends RuntimeException {}
