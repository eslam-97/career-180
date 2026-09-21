<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_balances', function (Blueprint $table) {
            // §9.4: the per-instructor serialisation point. Every money-moving
            // transaction takes this row FOR UPDATE first. The key is the
            // instructor id itself, not a surrogate — there is exactly one row
            // per instructor and it is looked up by that id under a lock.
            $table->unsignedBigInteger('instructor_id')->primary();

            // §1.1: recognized covers ALL entry types, including
            // refund_adjustment, because §12's bucket identity runs over all of
            // them.
            $table->bigInteger('recognized_minor')->default(0);

            // §3: SIGNED. Debt is a negative available balance (§10.4) and nets
            // against future recognition with no manual step.
            $table->bigInteger('available_minor')->default(0);

            // Claimed by a payout that has not reached a terminal state, and by
            // a settled payout respectively. §10.4 guarantees every payout
            // amount is positive, so neither bucket can legitimately go negative.
            $table->unsignedBigInteger('reserved_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);

            // §1.1: the watermark — the instant up to which recognition has been
            // posted. §12 level three checks it against MAX(ledger.recognized_through_at).
            $table->dateTime('recognized_through_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_balances');
    }
};
