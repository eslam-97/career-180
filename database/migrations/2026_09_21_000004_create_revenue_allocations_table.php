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
        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('subscription_payments')
                ->restrictOnDelete();

            $table->unsignedBigInteger('instructor_id');

            // §3: UNSIGNED. The canonical entitlement, frozen at payment and
            // never negative — the deliberate counterpart to the SIGNED
            // amount_minor on ledger_entries. §19 notes that the unbuilt
            // counter-allocation path would be what forces this to become signed.
            $table->unsignedBigInteger('amount_minor');

            // §3.1: the split rule as an exact rational. A three-way split
            // stores 1/3, never 0.3333333333. Audit record, not arithmetic input.
            $table->unsignedInteger('weight_numerator');
            $table->unsignedInteger('weight_denominator');

            // §5.3: allocations are immutable, so there is nothing for an
            // updated_at to record.
            $table->timestamp('created_at')->nullable();

            // §5.1: payment uniqueness does not make allocation idempotent.
            // This is what guards an allocation job retried after a crash.
            $table->unique(['payment_id', 'instructor_id']);
        });

        // §3.1: LargestRemainder::apportion() refuses a zero total weight for
        // the same reason — there is no meaningful split to divide by.
        DB::statement(
            'ALTER TABLE revenue_allocations ADD CONSTRAINT chk_revenue_allocations_weight_denominator
             CHECK (weight_denominator > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_allocations');
    }
};
