<?php

// Invariants 12, 19, 23 — ARCHITECTURE.md §6.2 Hazard A, §9.4, §10.2, §10.6

use Tests\Concurrency\TwoConnections;

uses(TwoConnections::class);

it('inv-12a: the release job holds the instructor balance row')
    // A real implementation will:
    // 1. Create an instructor with allocations.
    // 2. Connection A starts a transaction and locks instructor_balances.
    // 3. Connection B attempts to lock the same instructor_balances row.
    // 4. Assert B blocks.
    // 5. Assert another instructor does NOT block.
    //
    // This must use two real database connections. Do not replace it with
    // two sequential calls on the same connection.
    ->todo();

it('inv-12b: two release runs with different targets do not over-recognize')
    // Watermark at end of February. Two allocations such that
    //   expected(March) = 100, expected(April) = 200.
    // Run the March release to completion, then the April release.
    // Assert the ledger total is 200, not 300.
    //
    // Then the real interleaving check: open the March run's transaction on A and
    // hold it before commit; assert the April run on B blocks (via blocks()).
    // Commit A, run B, assert total 200.
    //
    // WITHOUT the lock this produces 300 — both runs read `posted` as 0 from their
    // own snapshots. That is the bug (§6.2 Hazard A).
    ->todo();

it('inv-19: attempt_count increments only when the slot is acquired')
    // A acquires the slot for payout #500 and holds the transaction open
    // (attempt 2 created, status 'sending').
    // B tries to acquire the slot on connection B.
    // Assert B blocks, and after A commits, B's attempt to acquire is REFUSED
    // (a live attempt exists) and attempt_count is still 2, not 3.
    //
    // The counter must not be burned by the loser. If it reaches 3, the gate is
    // relying on the unique constraint instead of doing the work itself (§10.2).
    ->todo();

it('inv-23: settlement and failure move the balance exactly once when replayed')
    // Settle a payout. Record the balance buckets.
    // Replay the exact same settlement call (same payout, same attempt).
    // Assert reserved and paid are UNCHANGED — the balance update is gated on the
    // payout transition's affected-row count (§10.6).
    //
    // Repeat for the failure path: fail a payout, replay, assert reserved and
    // available are unchanged and entries are not un-stamped twice.
    ->todo();
