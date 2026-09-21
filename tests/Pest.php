<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\DatabaseTruncation;

// Most tests: fast, transaction-wrapped.
uses(Tests\TestCase::class, RefreshDatabase::class)
    ->in('Feature', 'Unit', 'Invariants');

// Concurrency tests MUST NOT use RefreshDatabase. It wraps each test in a
// transaction on the default connection, so a second connection would never
// see the first one's writes — every concurrency test would pass vacuously.
uses(Tests\TestCase::class, DatabaseTruncation::class)
    ->in('Concurrency');