<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §14: `instructor_ids JSON NOT NULL` — sorted distinct ids, frozen at initiate.
 *
 * §5.2 requires the split to be across "the distinct instructors whose courses
 * the subscription grants access to, determined at payment time", and there is
 * no courses or enrolments table for the allocator to resolve that from. §5.3
 * therefore freezes the set onto the payment beside the rate and the cut, so the
 * allocation job reads it from the row rather than its own queue payload and a
 * lost job stays re-dispatchable from the database alone — the same property
 * §10.7 leans on for stranded payouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            // §5.3: frozen alongside platform_rate_bps and platform_cut_minor,
            // and never recomputed. Stored sorted and distinct so the frozen set
            // is canonical — EqualSplitAllocator normalises the same way (§5.2).
            $table->json('instructor_ids')->after('platform_cut_minor');
        });

        // A payment with no instructors would leave the entire pool unallocated
        // and break invariant 1. The database refuses it at write time rather
        // than the allocation job discovering it later.
        DB::statement(
            "ALTER TABLE subscription_payments ADD CONSTRAINT chk_subscription_payments_instructor_ids
             CHECK (JSON_TYPE(instructor_ids) = 'ARRAY' AND JSON_LENGTH(instructor_ids) > 0)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscription_payments DROP CONSTRAINT chk_subscription_payments_instructor_ids');

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('instructor_ids');
        });
    }
};
