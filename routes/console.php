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

// §10.1 / §6.4: recognition is posted monthly, so the payout batch is monthly
// too and runs after the release has closed the period. withoutOverlapping() is
// belt to the command's own Cache::lock; the guarantees are the balance lock
// (§9.4) and UNIQUE(batch_id, instructor_id).
Schedule::command('payouts:run')
    ->monthlyOn(1, '04:00')
    ->withoutOverlapping();

// §10.7: a payout with money reserved and no attempt in flight. RECOVERY ONLY —
// §10.3's claim job dispatches the attempt itself, so on a healthy queue this
// sweeps nothing. What it catches is the attempt-creation job the queue dropped
// or the worker that died before the §10.2 transaction.
// needs_review is excluded by the sweeper's explicit status list.
Schedule::command('payouts:sweep-stranded')
    ->everyMinute()
    ->withoutOverlapping();

// §11.1: an attempt past its lease becomes unknown and is asked about. Never
// failed, never resent. Frequent, because the lease is the latency floor on
// recovering a dead worker.
Schedule::command('payouts:sweep-leases')
    ->everyMinute()
    ->withoutOverlapping();

// §11.2: the low-rate poll that continues indefinitely, so an answer arriving a
// day later — from a settlement file, or a human reading the provider's
// dashboard — is still recorded rather than dropped.
Schedule::command('payouts:poll-open')
    ->hourly()
    ->withoutOverlapping();
