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
