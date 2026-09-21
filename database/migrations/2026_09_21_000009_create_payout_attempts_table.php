<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();

            // Declared before active_payout_id: the generated column below reads
            // both this and status.
            $table->foreignId('payout_id')
                ->constrained('payouts')
                ->restrictOnDelete();

            $table->unsignedInteger('attempt_no');

            // §9.1: hash(payout_id, attempt_no) — derived deterministically, so
            // a re-run produces the same key and the insert simply fails.
            $table->string('idempotency_key', 64);

            // §10.2: there is NO 'pending' attempt state. An attempt row is
            // created already in 'sending', inside the transaction that acquires
            // the slot, so no attempt can exist without a worker having
            // committed to calling the provider.
            $table->enum('status', [
                'sending',
                'succeeded',
                'failed',
                'unknown',
                'unresolved',
            ]);

            $table->string('provider_reference', 191)->nullable();

            // §10.2: the per-attempt evidence trail — what was asked and what
            // came back. The response is null until the provider answers, which
            // is the whole point of the §11.1 recovery boundary.
            $table->json('request_payload');
            $table->json('response_payload')->nullable();

            // §11.2: a human resolving an 'unresolved' attempt is making an
            // assertion about evidence outside the system. It is recorded.
            $table->text('resolution_evidence')->nullable();

            $table->dateTime('started_at');

            // §11.1: the lease sweeper's only legal transition is
            // sending -> unknown, once this passes.
            $table->dateTime('lease_expires_at');

            $table->dateTime('polled_at')->nullable();
            $table->unsignedInteger('poll_count')->default(0);

            // §10.2: "at most one attempt in flight" expressed as a constraint
            // rather than as a gate. NULLs in a unique index are *exploited*
            // here (§5.1): a terminal attempt generates NULL and MySQL tolerates
            // many of those, while two non-terminal attempts for one payout
            // collide. The gate in §10.2 is the mechanism; this is the backstop
            // that holds even if the gate is bypassed.
            $table->unsignedBigInteger('active_payout_id')->nullable()->storedAs(
                "case when `status` in ('sending','unknown') then `payout_id` end"
            );

            $table->timestamps();

            // §9.1: guards a queued job retried mid-flight.
            $table->unique('idempotency_key');

            // §9.1: guards two workers creating attempt #2 concurrently.
            $table->unique(['payout_id', 'attempt_no']);

            $table->unique('active_payout_id');

            // §17: the lease sweeper's index.
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_attempts');
    }
};
