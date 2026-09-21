<?php

// Invariants 3, 4, 5, 6 — ARCHITECTURE.md §1.1, §10.5, §12

it('inv-03: recognized equals available plus reserved plus paid')
    // Build an instructor with releases, a correction and a refund_adjustment,
    // one settled payout and one in-flight payout.
    // Assert the cached balance matches:
    //   available  = SUM(entries WHERE payout_id IS NULL)
    //   reserved   = SUM(entries claimed by non-terminal payouts)
    //   paid       = SUM(entries claimed by settled payouts)
    //   recognized = available + reserved + paid
    // recognized must include refund_adjustment. If it does not, this fails (§1.1).
    ->todo();

it('inv-04: every ledger entry is in exactly one bucket at all times')
    // Assert no entry is stamped with a payout in a terminal-failed state.
    // Run after: a normal settlement, a failed payout, a rolled-back claim,
    // and a stranded payout swept by §10.7.
    // Also assert the three bucket queries partition the entry set with no overlap
    // and no gaps.
    ->todo();

it('inv-05: posted release entries match the calculated total at the watermark')
    // SUM(release + release_correction) === sum over allocations of released(alloc, W)
    // where W is instructor_balances.recognized_through_at.
    // Note: refund_adjustment is EXCLUDED from the left side (§1.1).
    ->todo();

it('inv-06: a refund adjustment is never reversed by a later release run')
    // Post a goodwill refund_adjustment of -30 with access unchanged.
    // Run the scheduled release for the next period.
    // Assert the -30 entry is untouched and no +30 compensating entry appears.
    // This fails if `posted` wrongly includes refund_adjustment.
    ->todo();
