<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('instructor_id');

            // §10.1: deliberately NOT an enum and deliberately unconstrained.
            // The table is expected to hold types outside the payable set — a
            // future tax_withholding or audit_note — and the claim query's
            // explicit allowlist is what keeps them from being paid. Closing
            // this column would move that guarantee to the wrong place and make
            // invariant 29 untestable.
            $table->string('type', 32);

            // §3: SIGNED. Corrections and adjustments are compensating entries,
            // so a ledger amount can be negative. The alternative — unsigned
            // plus a direction flag — was rejected in §3.3 because it turns
            // every aggregate into a CASE expression.
            $table->bigInteger('amount_minor');

            // §3.3: three dates with three distinct jobs. Collapsing any two of
            // them loses information the system depends on.
            //
            // period_start          when the row was POSTED (a posting period,
            //                       not a recognition period — §6.3)
            $table->date('period_start');
            // recognized_through_at the recognition horizon this row accounts
            //                       for; a correction holds it, §6.2
            $table->dateTime('recognized_through_at');
            // effective_at          the business-effective instant, used for
            //                       payout eligibility, §10.1
            $table->dateTime('effective_at');

            // §14: NOT NULL with a deterministic value, precisely because MySQL
            // permits many NULLs in a unique index. Releases key on the posting
            // period ('period:2026-03'), corrections on the triggering event
            // ('refund:9812').
            $table->string('source_ref', 191);

            // §10.6 un-stamps this back to NULL when a payout fails, which is
            // why the constraint is RESTRICT rather than anything cascading.
            $table->foreignId('payout_id')
                ->nullable()
                ->constrained('payouts')
                ->restrictOnDelete();

            // §5.3-style immutability: a ledger row's only mutable field is
            // payout_id, and that history lives on payouts and payout_attempts.
            $table->timestamp('created_at')->nullable();

            // §9.1: guards the release run executed twice, or a refund processed
            // twice.
            $table->unique(['instructor_id', 'type', 'source_ref']);

            // §17: covering indexes for the §10.1 claim query, which filters on
            // both range predicates.
            $table->index(['instructor_id', 'payout_id', 'effective_at']);
            $table->index(['instructor_id', 'payout_id', 'id']);

            // §12 level three: MAX(recognized_through_at) per instructor.
            $table->index(['instructor_id', 'recognized_through_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
