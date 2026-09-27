<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §14: `INDEX(instructor_id, payment_id, amount_minor)` on
     * `revenue_allocations` — §17's "per-instructor expected sum: covering
     * index on allocations; without it each instructor is a full-table scan".
     *
     * `ExpectedRecognition::forInstructor()` is the hot read of §6.2 — one per
     * instructor, every posting period — and it filters `instructor_id = ?`.
     * `UNIQUE(payment_id, instructor_id)` is a correctness constraint and leads
     * on `payment_id`, so it cannot serve that predicate at all. Measured by
     * `ScaleSeeder` at 500,000 subscriptions before this index existed, MySQL
     * planned the read as `type=index, key=PRIMARY, rows=996,688` — a scan of
     * the whole table per instructor, at 180-370 ms each, whose cost grew with
     * the size of the table rather than with one instructor's share of it.
     *
     * The column order is §14's, and §14's order is the query in order:
     *
     *     instructor_id   the WHERE
     *     payment_id      the join to subscription_payments
     *     amount_minor    the only other column selected from this table
     *
     * With all three present the read is covering — `Using index`, no row
     * lookups — and InnoDB appends the primary key to every secondary index, so
     * `ra.id` comes along for the ORDER BY without being named.
     *
     * An index and nothing else: no column, constraint or stored value changes,
     * so it cannot alter what any query returns, only what it costs. §12's
     * reconciliation and the §15 invariants are untouched by construction.
     */
    public function up(): void
    {
        Schema::table('revenue_allocations', function (Blueprint $table) {
            $table->index(
                ['instructor_id', 'payment_id', 'amount_minor'],
                'revenue_allocations_instructor_covering_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('revenue_allocations', function (Blueprint $table) {
            $table->dropIndex('revenue_allocations_instructor_covering_index');
        });
    }
};
