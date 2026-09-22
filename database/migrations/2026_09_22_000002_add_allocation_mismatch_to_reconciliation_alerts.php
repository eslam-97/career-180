<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §12 level zero — "every confirmed payment is fully allocated" — is the one
 * reconciliation check with no alert kind to raise. §14 lists
 * allocation_mismatch in the enum; the original migration predates the check
 * and omits it.
 *
 * Raw ALTER TABLE rather than a Blueprint change: doctrine/dbal is not
 * installed, and §14 requires this column to stay a MySQL ENUM so strict mode
 * rejects a kind the design does not define.
 */
return new class extends Migration
{
    private const KINDS_AFTER = [
        'late_success_on_failed_payout',
        'bucket_mismatch',
        'watermark_drift',
        'ledger_mismatch',
        'allocation_mismatch',
    ];

    private const KINDS_BEFORE = [
        'late_success_on_failed_payout',
        'bucket_mismatch',
        'watermark_drift',
        'ledger_mismatch',
    ];

    public function up(): void
    {
        DB::statement($this->modifyKindTo(self::KINDS_AFTER));
    }

    public function down(): void
    {
        // An alert is a record that something drifted and money rows are never
        // deleted (§14), so rolling back past an allocation_mismatch that has
        // actually been raised will fail rather than discard it. That is the
        // correct direction to fail in.
        DB::statement($this->modifyKindTo(self::KINDS_BEFORE));
    }

    /** @param  array<int, string>  $kinds */
    private function modifyKindTo(array $kinds): string
    {
        $values = implode(', ', array_map(
            static fn (string $kind): string => "'{$kind}'",
            $kinds,
        ));

        return "ALTER TABLE reconciliation_alerts MODIFY kind ENUM({$values}) NOT NULL";
    }
};
