<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// §5.3 / §10.7: sweep up confirmed payments whose allocation job never landed.
// withoutOverlapping() is belt to the command's own Cache::lock braces — both
// are optimisations, and UNIQUE(payment_id, instructor_id) is the guarantee.
Schedule::command('payments:allocate-missing')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// §6.4: recognition is computed daily but POSTED monthly, so the release runs
// once a month and closes the period that just ended. withoutOverlapping() is
// belt to the command's own Cache::lock; the guarantees are the watermark guard
// (§6.2, Hazard B) and UNIQUE(instructor_id, type, source_ref).
Schedule::command('release:run')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping();

// §12: a materialised balance with no drift detector is a materialised balance
// that will eventually be wrong without anyone noticing. Detects, never repairs.
Schedule::command('reconcile:balances')
    ->dailyAt('03:00')
    ->withoutOverlapping();
