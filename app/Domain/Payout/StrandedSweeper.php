<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * §10.7: stranded payouts are re-dispatched.
 *
 * > A payout in **pending or in_progress** with **no active attempt** and
 * > attempt_count below the ceiling is re-dispatched. Above the ceiling it moves
 * > to failed via §10.6. **needs_review is never re-dispatched** and requires
 * > explicit human resolution.
 *
 * The status list is written out rather than expressed as "non-terminal". That
 * is not a detail: needs_review is non-terminal for MONEY — its entries stay
 * reserved, which is why §12 counts them in the reserved bucket — and terminal
 * for AUTOMATION. Any predicate written as "not finished" would silently undo
 * the §10.2 rule that a human-flagged payout is never retried by a machine
 * (invariant 24).
 *
 * Within that scope the sweep is unconditionally safe: the absence of an active
 * attempt — enforced by UNIQUE(active_payout_id) — means nothing is in flight
 * with the provider, so creating the next attempt cannot double-send. Two
 * sweeps racing are safe for the same reason as two workers: the §10.2 gate
 * hands the slot to one of them and the other exits.
 */
final class StrandedSweeper
{
    /** §10.7: the explicit list. needs_review, settled and failed are all absent. */
    private const SWEEPABLE_PAYOUT_STATUSES = ['pending', 'in_progress'];

    /** §10.2: an attempt in either of these is active, so the payout is not stranded. */
    private const LIVE_ATTEMPT_STATUSES = ['sending', 'unknown'];

    public function __construct(
        private readonly SettlementService $settlement,
        private readonly ?string $connection = null,
    ) {}

    /**
     * `dispatch` are the payouts that need a fresh attempt — the caller queues
     * them, because §9.2 keeps dispatches out of transactions. `failed` are the
     * ones that exhausted the ceiling and were failed here via §10.6.
     *
     * @return array{dispatch: list<int>, failed: list<int>}
     */
    public function sweep(): array
    {
        $ceiling = AttemptService::ceiling();

        // A batch-level read that only decides which payouts to act on, so it
        // takes no lock (§9.4). Every decision below is re-made under the locks
        // by the §10.2 gate or the §10.6 failure transaction.
        $stranded = $this->db()->table('payouts')
            ->whereIn('status', self::SWEEPABLE_PAYOUT_STATUSES)
            ->whereNotExists(fn (Builder $query) => $query
                ->select(DB::raw(1))
                ->from('payout_attempts')
                ->whereColumn('payout_attempts.payout_id', 'payouts.id')
                ->whereIn('payout_attempts.status', self::LIVE_ATTEMPT_STATUSES))
            ->orderBy('id')
            ->get(['id', 'attempt_count']);

        $dispatch = [];
        $failed = [];

        foreach ($stranded as $payout) {
            if ((int) $payout->attempt_count < $ceiling) {
                $dispatch[] = (int) $payout->id;

                continue;
            }

            // §10.7: "above the ceiling it moves to failed via §10.6" — the same
            // transaction as every other failure, preconditions included, so an
            // attempt that is still alive or already succeeded stops it.
            if ($this->settlement->failPayout((int) $payout->id)) {
                $failed[] = (int) $payout->id;
            }
        }

        return ['dispatch' => $dispatch, 'failed' => $failed];
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }
}
