<?php

namespace App\Providers;

use App\Domain\Allocation\EqualSplitAllocator;
use App\Domain\Allocation\RevenueAllocator;
use App\Domain\Provider\PaymentProvider;
use App\Domain\Provider\RandomProvider;
use App\Domain\Provider\ScriptedProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // §5.2: the split rule sits behind the interface so nothing downstream
        // knows how it was derived. Swapping in an enrolment-weighted allocator
        // is a one-line change here — a consumption-weighted one is not, because
        // it cannot be allocated at payment time at all (§5.2).
        $this->app->bind(RevenueAllocator::class, EqualSplitAllocator::class);

        // §16.3: "ScriptedProvider drives outcomes per call for the test suite —
        // no randomness, no flaky assertions. RandomProvider drives the demo,
        // seeded". Two environments, two defaults, and the suite's is the one
        // that answers nothing until a test says what it should answer: an
        // unscripted send throws rather than drawing a random outcome, so a test
        // that moves money it never meant to move fails on the spot instead of
        // passing four times in five.
        //
        // A singleton either way. The seeded sequence and the script are both
        // per instance, so resolving a new one per job would replay the first
        // outcome forever.
        $this->app->singleton(
            PaymentProvider::class,
            $this->app->environment('testing') ? ScriptedProvider::class : RandomProvider::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
