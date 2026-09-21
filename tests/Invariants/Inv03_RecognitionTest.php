<?php

// Invariants 13, 14, 15, 16, 26 — ARCHITECTURE.md §6.2, §12

it('inv-13: the watermark only moves forward and a covered target posts nothing')
    // Run the scheduled release for April. Watermark -> 30 April.
    // Run it again for April: zero new rows, watermark unchanged.
    // Run it for March: zero new rows, watermark unchanged.
    ->todo();

it('inv-14: a late scheduled run does not reverse a later catch-up')
    // Skip March entirely.
    // Run April: posts +200 (expected 200, posted 0). Watermark -> 30 April.
    // NOW run March late.
    // Assert: no release_correction of -100 is created, ledger total stays 200.
    // This is the bug the monotonic guard exists to stop.
    ->todo();

it('inv-15: an event correction leaves the watermark unchanged')
    // Watermark at 31 March. Process a refund dated 15 March.
    // Assert a release_correction row is created,
    // AND instructor_balances.recognized_through_at is still 31 March,
    // AND the correction row's own recognized_through_at is 31 March (§3.3).
    ->todo();

it('inv-16: the cached watermark matches the ledger')
    // After any sequence of scheduled runs and corrections:
    //   instructor_balances.recognized_through_at
    //     === MAX(ledger_entries.recognized_through_at) for that instructor
    // This is level-three reconciliation (§12).
    ->todo();

it('inv-26: running the release job twice for a posting period posts one row')
    // Run the March scheduled release twice in sequence.
    // Assert exactly one ledger row with source_ref = 'period:2026-03'.
    // Blocked by BOTH the unique constraint and the watermark guard — assert the
    // row count, not which mechanism caught it.
    ->todo();
