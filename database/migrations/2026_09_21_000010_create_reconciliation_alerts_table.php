<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_alerts', function (Blueprint $table) {
            $table->id();

            // §12: the three reconciliation levels raise bucket_mismatch,
            // ledger_mismatch and watermark_drift. §10.6 raises
            // late_success_on_failed_payout — the one path knowingly
            // unrecoverable by machine (§19).
            $table->enum('kind', [
                'late_success_on_failed_payout',
                'bucket_mismatch',
                'watermark_drift',
                'ledger_mismatch',
            ]);

            $table->string('subject_type', 191);
            $table->unsignedBigInteger('subject_id');

            $table->json('detail');

            $table->dateTime('detected_at');
            $table->dateTime('resolved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_alerts');
    }
};
