<?php

// Invariants 17, 25, 28, 29, 33 — ARCHITECTURE.md §10.1, §10.4

it('inv-17: a visible payout always has a positive amount')
    // Query every payout row from a SECOND connection while a claim transaction
    // is open on the first. Assert no row with a NULL or zero amount is visible.
    // Then assert the committed row has amount > 0.
    // The NULL exists only inside the uncommitted transaction (§10.4).
    ->todo();

it('inv-25: running the payout command twice creates one payout per instructor')
    // php artisan payouts:run twice for the same batch period.
    // Assert exactly one payout row per instructor.
    // Assert the second run claimed zero ledger entries.
    ->todo();

it('inv-28: replaying a batch claims an identical entry set')
    // Create batch N (cutoff 10:00, max_entry_id 5000). Run it.
    // Insert a backdated entry: effective_at 09:00, but a NEW id (5001).
    // Replay batch N.
    // Assert the claimed entry set is byte-identical to the first run —
    // entry 5001 is excluded by `id <= max_entry_id` despite passing the date test.
    // Then assert batch N+1 DOES claim entry 5001.
    ->todo();

it('inv-29: a ledger type outside the allowlist is never claimed')
    // Insert a ledger entry with a type not in the payable allowlist
    // (e.g. 'tax_withholding') that otherwise satisfies every predicate.
    // Run a payout batch.
    // Assert the entry is still unclaimed.
    ->todo();

it('inv-33: a negative balance is cleared by future recognition automatically')
    // March: release +100, correction -150. Run the batch.
    //   -> assert NO payout row exists, and both entries are still unclaimed.
    // April: release +200. Run the batch.
    //   -> assert one payout of 150, claiming all three entries.
    // No manual step anywhere.
    ->todo();
