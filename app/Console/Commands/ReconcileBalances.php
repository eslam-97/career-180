<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reconciliation\BalanceReconciler;
use App\Domain\Reconciliation\ReconciliationFinding;
use App\Models\ReconciliationAlert;
use Illuminate\Console\Command;

/**
 * §12: all four checks run as a scheduled `reconcile:balances` command that
 * fails loudly rather than repairing silently.
 *
 * Loudly means two things, and both are needed: a non-zero exit code so the
 * scheduler notices, and a durable `reconciliation_alerts` row so a human can
 * still find it tomorrow. Nothing here writes to a money table.
 */
final class ReconcileBalances extends Command
{
    protected $signature = 'reconcile:balances';

    protected $description = 'Check the balance cache, the ledger and the allocations against each other (§12)';

    public function handle(BalanceReconciler $reconciler): int
    {
        $findings = $reconciler->run();

        if ($findings === []) {
            $this->info('Reconciliation clean: all four levels agree.');

            return self::SUCCESS;
        }

        $raised = 0;

        foreach ($findings as $finding) {
            $this->error($finding->summary());

            if ($this->raise($finding)) {
                $raised++;
            }
        }

        $this->error(sprintf(
            '%d reconciliation finding(s), %d new alert(s). Nothing was repaired — see §12.',
            count($findings),
            $raised,
        ));

        // §12: fails loudly. The command is the smoke alarm, not the window you
        // open to let the smoke out.
        return self::FAILURE;
    }

    /**
     * True if a new alert row was written.
     *
     * An unresolved alert for the same (kind, subject) is left to stand rather
     * than duplicated: `resolved_at` makes an alert an open item, and a nightly
     * schedule would otherwise pile up one row per night for a single unfixed
     * drift. Re-raising nothing changes nothing about the exit code, which is
     * what actually makes the failure loud.
     */
    private function raise(ReconciliationFinding $finding): bool
    {
        $alreadyOpen = ReconciliationAlert::query()
            ->where('kind', $finding->kind)
            ->where('subject_type', $finding->subjectType)
            ->where('subject_id', $finding->subjectId)
            ->whereNull('resolved_at')
            ->exists();

        if ($alreadyOpen) {
            return false;
        }

        ReconciliationAlert::query()->create([
            'kind' => $finding->kind,
            'subject_type' => $finding->subjectType,
            'subject_id' => $finding->subjectId,
            'detail' => $finding->detail,
            'detected_at' => now()->utc(),
            'resolved_at' => null,
        ]);

        return true;
    }
}
