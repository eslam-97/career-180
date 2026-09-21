<?php

namespace Tests\Concurrency;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Two real connections to the same database, for testing that a row lock
 * actually exists.
 *
 * `mysql`   — connection A, holds the lock
 * `mysql_b` — connection B, must block
 *
 * B runs with innodb_lock_wait_timeout = 1 so a genuine block surfaces as a
 * fast, deterministic exception instead of hanging the suite.
 */
trait TwoConnections
{
    protected function connA()
    {
        return DB::connection('mysql');
    }

    protected function connB()
    {
        $b = DB::connection('mysql_b');
        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $b;
    }

    /** True if the callback was blocked by a row lock. */
    protected function blocks(callable $fn): bool
    {
        try {
            $fn();

            return false;
        } catch (QueryException $e) {
            return str_contains($e->getMessage(), '1205')
                || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
        }
    }
}
