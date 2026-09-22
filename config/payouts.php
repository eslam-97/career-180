<?php

declare(strict_types=1);

/**
 * The numbers §10.2, §10.7 and §11 leave open. Every one of them is a policy
 * choice, not a derived value, so they live here rather than buried in a class
 * where nobody would find them.
 */
return [

    /*
     * §10.2 / §10.7: the same ceiling is read by the slot gate and by the
     * stranded sweeper. One number, deliberately: "the ceiling belongs in this
     * gate, not only in the sweeper — otherwise the gate can hand out an attempt
     * the sweeper would have refused".
     */
    'attempt_ceiling' => (int) env('PAYOUT_ATTEMPT_CEILING', 3),

    /*
     * §11.1: how long an attempt may sit in 'sending' before the lease sweeper
     * moves it to 'unknown'. It MUST exceed provider_timeout_seconds — a lease
     * that expires while the provider call is still legitimately in flight would
     * sweep a live attempt (asserted in tests/Feature/PayoutSweepersTest).
     */
    'lease_seconds' => (int) env('PAYOUT_LEASE_SECONDS', 300),

    /*
     * §11: how long the worker itself waits for the provider before giving up on
     * the response. Giving up is 'unknown', never 'failed'.
     */
    'provider_timeout_seconds' => (int) env('PAYOUT_PROVIDER_TIMEOUT_SECONDS', 30),

    /*
     * §11.2: when polling exhausts its backoff the attempt becomes 'unresolved'
     * and the payout moves to needs_review. That is US giving up, not the
     * provider saying no — the outcome predicate still accepts a later answer.
     */
    'max_polls' => (int) env('PAYOUT_MAX_POLLS', 8),

    /*
     * §11.2: the backoff itself, one entry per poll. The last entry is reused if
     * max_polls is ever raised past its length.
     */
    'poll_backoff_seconds' => [15, 30, 60, 120, 300, 600, 900, 1800],

    /*
     * §16.3: "RandomProvider drives the demo, seeded, so a re-recorded
     * demonstration produces the same failure sequence".
     */
    'demo_provider_seed' => (int) env('PAYOUT_DEMO_PROVIDER_SEED', 20260922),
];
