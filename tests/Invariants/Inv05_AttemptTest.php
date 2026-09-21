<?php

// Invariants 18, 20, 21, 22, 24, 27 — ARCHITECTURE.md §10.2, §10.6, §10.7, §11

it('inv-18: at most one attempt per payout is in a non-terminal state')
    // Create attempt 1 in 'sending'. Try to insert a second attempt for the same
    // payout directly, bypassing the service.
    // Assert the database rejects it (UNIQUE on the generated active_payout_id column).
    // Then mark attempt 1 'failed' and assert a second attempt CAN now be created.
    ->todo();

it('inv-20: a payout cannot be failed while an attempt is live or succeeded')
    // Attempt in 'unknown'. Try to fail the payout. Assert it is refused and the
    // ledger entries stay claimed.
    // Attempt in 'sending'. Same.
    // Attempt 'succeeded'. Same.
    // Attempt 'failed' and 'unresolved' with none succeeded -> failure is allowed.
    ->todo();

it('inv-21: a definitive outcome is recorded from sending, unknown and unresolved')
    // Three separate cases. For each, deliver a definitive SUCCESS and assert the
    // attempt reaches 'succeeded'.
    // The 'unresolved' case is the one that matters — excluding it would silently
    // drop a confirmed transfer (§11.2).
    ->todo();

it('inv-22: a late success on a failed payout moves no money and raises an alert')
    // Payout failed, entries released, claimed by a later batch.
    // Now deliver a definitive SUCCESS for the old attempt.
    // Assert: attempt -> 'succeeded', balance buckets UNCHANGED,
    // one reconciliation_alerts row of kind late_success_on_failed_payout.
    ->todo();

it('inv-24: a needs_review payout is never re-dispatched by any sweeper')
    // Payout in needs_review, attempt in unresolved, entries still claimed.
    // Run the stranded-payout sweeper and the lease sweeper.
    // Assert: no new attempt, attempt_count unchanged, status unchanged.
    ->todo();

it('inv-27: retrying an attempt sends nothing; a new attempt follows definitive failure')
    // Case A: retry the SAME attempt (same job, same id). Assert zero additional
    //   provider send() calls.
    // Case B: attempt 1 reaches definitive 'failed'. Create attempt 2.
    //   Assert exactly one additional send(), with a DIFFERENT idempotency key.
    ->todo();
