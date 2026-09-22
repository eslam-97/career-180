<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_batches', function (Blueprint $table) {
            // §10.1: the frozen max_entry_id is only a genuine snapshot if one
            // period maps to exactly one batch row. Re-running payouts:run for
            // a period must reuse that row and its frozen high-water mark —
            // creating a second batch for the same period would re-freeze it at
            // a later id and sweep entries the first batch deliberately left for
            // the next one, which is the bug §10.1 exists to prevent.
            //
            // §9.1: BatchService::openFor() is the mechanism; this is the
            // constraint that makes it true under two servers.
            $table->unique(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::table('payout_batches', function (Blueprint $table) {
            $table->dropUnique(['period_start', 'period_end']);
        });
    }
};
