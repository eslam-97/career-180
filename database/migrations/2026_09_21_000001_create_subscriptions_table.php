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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            // §14: no instructors/students table exists, so student_id is an
            // opaque identifier with no foreign key to point at.
            $table->unsignedBigInteger('student_id');

            $table->string('plan_code', 64);

            // §3: a subscription price is never negative.
            $table->unsignedBigInteger('amount_minor');

            // §3.2: money enters the system here, so the currency code lives
            // here. Nothing downstream carries one.
            $table->char('currency', 3);

            // §4: terms are [starts_at, ends_at) — end exclusive. Term length
            // is derived from these dates, never from a plan constant.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // §6.1: auto-renew cancelled. Does NOT cap recognition.
            $table->dateTime('cancelled_at')->nullable();

            // §6.1: access terminated. DOES cap recognition, via effective_end.
            $table->dateTime('access_ends_at')->nullable();

            $table->string('status', 32);

            $table->timestamps();
        });

        // §14: CHECK (ends_at > starts_at). ReleaseCalculator::termDays() relies
        // on a term of at least one whole day.
        DB::statement(
            'ALTER TABLE subscriptions ADD CONSTRAINT chk_subscriptions_term_order
             CHECK (ends_at > starts_at)'
        );

        // §14: the lower bound is `>=`, not `>`, precisely so §6.1 can express a
        // full refund as access_ends_at = starts_at → effective_days = 0.
        DB::statement(
            'ALTER TABLE subscriptions ADD CONSTRAINT chk_subscriptions_access_window
             CHECK (access_ends_at IS NULL
                    OR (access_ends_at >= starts_at AND access_ends_at <= ends_at))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
