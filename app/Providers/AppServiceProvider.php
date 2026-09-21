<?php

namespace App\Providers;

use App\Domain\Allocation\EqualSplitAllocator;
use App\Domain\Allocation\RevenueAllocator;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
