<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §16.3: the pretend payment company's own storage. NOT part of the schema in
 * §14 and not part of our money system — no model, no factory, no relation to
 * anything. Only App\Domain\Provider\FakeProviderTransfers reads or writes it,
 * and only ScriptedProvider and RandomProvider go through that.
 *
 * It exists because the brief's hardest scenario — timeout after already
 * succeeding — cannot be simulated by a stateless stub. A provider that throws
 * without recording a transfer tests our error handling, not the
 * money-already-moved case. So the fake keeps a durable map keyed by
 * idempotency_key, which is also what makes "a replayed key returns the stored
 * result and creates nothing new" a real assertion (invariant 27).
 *
 * A table rather than an in-memory array because the demo runs the queue worker
 * in a different process from the command, and an array would double-send there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_provider_transfers', function (Blueprint $table) {
            $table->id();

            // §11.3: the key the provider deduplicates on, server-side. One row
            // per key is the whole mechanism.
            $table->string('idempotency_key', 64)->unique();

            $table->unsignedBigInteger('amount_minor');

            // success | failure | unknown — the stored result, returned verbatim
            // to a replayed send() and to every status() query.
            $table->string('outcome', 16);

            // False for a failure: a result was stored, but no money moved. This
            // column is what invariant 31 counts, rather than inferring the
            // transfer from the final status.
            $table->boolean('transferred');

            $table->string('provider_reference', 191)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_provider_transfers');
    }
};
