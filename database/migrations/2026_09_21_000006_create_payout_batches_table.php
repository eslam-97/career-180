<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();

            $table->date('period_start');
            $table->date('period_end');

            // §10.1: business-time eligibility. Both this and max_entry_id are
            // load-bearing in the claim predicate.
            $table->dateTime('cutoff_at');

            // §10.1: frozen at batch creation. This is what makes the batch a
            // genuine snapshot — a backdated entry arriving after the batch was
            // created passes the cutoff_at test but fails this one.
            $table->unsignedBigInteger('max_entry_id');

            // §14 never enumerates the batch statuses, so this stays a plain
            // string rather than an invented ENUM.
            $table->string('status', 32);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
