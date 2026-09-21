<?php
// Invariants 30, 31, 32 — ARCHITECTURE.md §11.1, §11.2, §16.3

it('inv-30: an attempt past its lease resolves by status query, never by resending')
    // Attempt in 'sending' with lease_expires_at in the past.
    // Run the lease sweeper.
    // Assert: status -> 'unknown', and provider send() was NOT called again.
    // Then assert the poller calls status() with the SAME idempotency key.
    ->todo();

it('inv-31: timeout after success resolves to paid with exactly one transfer')
    // ScriptedProvider in TIMEOUT_AFTER_SUCCESS mode: it RECORDS the transfer,
    // then throws. Money has moved as far as the provider is concerned.
    // Run the payout. Assert attempt -> 'unknown'.
    // Run the poller. status() returns SUCCESS. Assert payout -> 'settled'.
    // Assert the provider's internal transfer count for that key is exactly 1.
    // If the provider throws BEFORE recording, this test is vacuous — assert the
    // transfer count directly, not just the final status.
    ->todo();

it('inv-32: a failed payout returns its entries and the next batch claims them once')
    // Payout fails definitively (no live attempts, none succeeded).
    // Assert entries return to payout_id = NULL and available increases.
    // Run the next batch. Assert those entries are claimed exactly once,
    // by exactly one new payout.
    ->todo();