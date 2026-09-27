<?php

use App\Domain\Provider\PaymentProvider;
use App\Domain\Provider\ScriptedProvider;
use App\Domain\Provider\UnscriptedProviderCall;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §16.3: the testing environment binds an UNCONFIGURED ScriptedProvider, which
 * throws UnscriptedProviderCall rather than answering a send() no test asked
 * for. Throwing is not enough by itself: every provider call in this system is
 * made inside a `catch (Throwable)`, because §11's rule is that silence from a
 * provider is not failure. SendPayoutAttempt would therefore swallow it into an
 * 'unknown' attempt and the test would fail later, somewhere else, for a reason
 * that looks nothing like the cause.
 *
 * So the provider counts the call as well as throwing it, and this hook re-raises
 * the count after the test body has finished, where no money-path handler is on
 * the stack to catch it.
 *
 * It reads whatever is bound at the END of the test, which is the provider the
 * test actually sent through: a test that binds its own scripted one through
 * PayoutFixtures::scripted() reports zero, because every one of its calls was
 * scripted.
 */
function assertNoUnscriptedProviderCalls(): void
{
    $provider = app(PaymentProvider::class);

    if (! $provider instanceof ScriptedProvider || $provider->unscriptedCalls() === 0) {
        return;
    }

    throw new UnscriptedProviderCall(sprintf(
        'This test made %d unscripted provider call(s). PaymentProvider::send() was reached '
        .'without an outcome scripted for it, so the send was refused. Script the outcome with '
        .'PayoutFixtures::scripted(Scenario::…) if the send was intended, or keep the job from '
        .'running if it was not.',
        $provider->unscriptedCalls(),
    ));
}

// Most tests: fast, transaction-wrapped.
uses(TestCase::class, RefreshDatabase::class)
    ->afterEach(fn () => assertNoUnscriptedProviderCalls())
    ->in('Feature', 'Unit', 'Invariants');

// Concurrency tests MUST NOT use RefreshDatabase. It wraps each test in a
// transaction on the default connection, so a second connection would never
// see the first one's writes — every concurrency test would pass vacuously.
uses(TestCase::class, DatabaseTruncation::class)
    ->afterEach(fn () => assertNoUnscriptedProviderCalls())
    ->in('Concurrency');
