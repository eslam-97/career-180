<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('batch_id')
                ->constrained('payout_batches')
                ->restrictOnDelete();

            $table->unsignedBigInteger('instructor_id');

            // §10.4: nullable because the payout id must exist before the ledger
            // entries can be stamped with it, but the amount is not known until
            // after the claim. NULL never survives the transaction — it either
            // becomes a positive sum or the whole thing rolls back.
            $table->unsignedBigInteger('amount_minor')->nullable();

            $table->enum('status', [
                'pending',
                'in_progress',
                'settled',
                'failed',
                'needs_review',
            ]);

            // §10.2: increments if and only if a worker acquires the slot.
            $table->unsignedInteger('attempt_count')->default(0);

            $table->dateTime('settled_at')->nullable();

            $table->timestamps();

            // §9.1: guards the payout command run twice, or two servers at once.
            $table->unique(['batch_id', 'instructor_id']);
        });

        // §10.4: MySQL has no deferred constraints, so this is the strongest form
        // expressible. A status-conditional CHECK does not work — `pending` with
        // a known positive amount is a legitimate committed state, because §10.3
        // commits the claim while §10.2 only moves the payout to in_progress later.
        DB::statement(
            'ALTER TABLE payouts ADD CONSTRAINT chk_payouts_amount_null_or_positive
             CHECK (amount_minor IS NULL OR amount_minor > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
