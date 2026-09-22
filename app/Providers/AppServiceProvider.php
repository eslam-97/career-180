<?php

namespace App\Providers;

use App\Domain\Allocation\EqualSplitAllocator;
use App\Domain\Allocation\RevenueAllocator;
use App\Domain\Provider\PaymentProvider;
use App\Domain\Provider\RandomProvider;
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

        // §16.3: there is no real payment provider in this scope, so the demo
        // runs the seeded one — same durable map, same record-then-throw
        // ordering as the scripted one the suite drives. A singleton because the
        // seeded sequence is per instance: resolving a new one per job would
        // replay the same first outcome forever.
        $this->app->singleton(PaymentProvider::class, RandomProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
