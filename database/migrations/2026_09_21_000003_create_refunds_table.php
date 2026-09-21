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
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            // §14 names the column payment_id, so the table is given explicitly.
            $table->foreignId('payment_id')
                ->constrained('subscription_payments')
                ->restrictOnDelete();

            // §3: magnitude only. The direction is implied by kind.
            $table->unsignedBigInteger('amount_minor');

            // §6.1: whether a given kind sets access_ends_at is a business rule
            // applied by a policy class, not assumed by the ledger.
            $table->enum('kind', [
                'termination_prorata',
                'termination_full',
                'goodwill_partial',
            ]);

            $table->string('reason', 255);

            // §3.3: the refund's business date, which becomes the ledger row's
            // effective_at — not the date it was processed.
            $table->dateTime('effective_at');

            $table->string('provider', 64);

            // NOT NULL here, unlike on payments: §14 marks NULL where it means
            // it, and a refund row is written once the provider has confirmed.
            $table->string('provider_reference', 191);
            $table->dateTime('processed_at');

            $table->timestamps();

            // §9.1: guards a refund webhook replay.
            $table->unique(['provider', 'provider_reference']);
        });

        DB::statement(
            'ALTER TABLE refunds ADD CONSTRAINT chk_refunds_amount_positive
             CHECK (amount_minor > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
