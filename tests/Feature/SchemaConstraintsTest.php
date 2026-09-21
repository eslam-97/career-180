<?php

declare(strict_types=1);

use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * ARCHITECTURE.md §14 — the schema itself.
 *
 * §14 closes with a note that where CHECK is unavailable the constraints are
 * "mirrored as model-level validation *and* as assertions in the test suite, so
 * no invariant is carried by convention alone". This file is the other half of
 * that promise for the deployment target that *does* support them: every CHECK,
 * UNIQUE, generated column and signedness decision is asserted to actually
 * reject a bad write, rather than being documented and unenforced.
 *
 * Every rejection goes through a raw DB::table() insert, deliberately bypassing
 * Eloquent casts and model state. What is under test is the database.
 */

// MySQL error numbers. Asserting the *specific* code matters: a test that only
// checked "something threw" would pass on a NOT NULL violation even if the
// CHECK constraint had never been created.
const ERR_NOT_NULL = 1048;          // ER_BAD_NULL_ERROR
const ERR_DUPLICATE = 1062;         // ER_DUP_ENTRY
const ERR_OUT_OF_RANGE = 1264;      // ER_WARN_DATA_OUT_OF_RANGE  (negative into UNSIGNED)
const ERR_BAD_ENUM = 1265;          // ER_WARN_DATA_TRUNCATED     (value outside an ENUM)
const ERR_FK_DELETE = 1451;         // ER_ROW_IS_REFERENCED_2
const ERR_FK_INSERT = 1452;         // ER_NO_REFERENCED_ROW_2
const ERR_CHECK = 3819;             // ER_CHECK_CONSTRAINT_VIOLATED

/**
 * ERR_OUT_OF_RANGE and ERR_BAD_ENUM are only errors because both mysql
 * connections run with 'strict' => true (config/database.php). Without strict
 * mode MySQL would silently clamp a negative to 0 and truncate a bad enum to
 * '' — which in a money system is worse than an exception.
 */
function rejectsWith(int $errno, Closure $write): void
{
    try {
        $write();
    } catch (QueryException $e) {
        expect($e->errorInfo[1])->toBe($errno);

        return;
    }

    Assert::fail("Expected the database to reject this write with MySQL error {$errno}, but it was accepted.");
}

/** A row that satisfies every constraint on `subscriptions`, before overrides. */
function validSubscriptionRow(array $overrides = []): array
{
    return array_merge([
        'student_id' => 1,
        'plan_code' => 'quarterly',
        'amount_minor' => 35_000,
        'currency' => 'EGP',
        'starts_at' => '2026-01-01 00:00:00',
        'ends_at' => '2026-04-01 00:00:00',
        'cancelled_at' => null,
        'access_ends_at' => null,
        'status' => 'active',
    ], $overrides);
}

/** A row that satisfies every constraint on `subscription_payments`. */
function validPaymentRow(int $subscriptionId, array $overrides = []): array
{
    return array_merge([
        'subscription_id' => $subscriptionId,
        'amount_minor' => 35_000,
        'currency' => 'EGP',
        'instructor_ids' => '[3,7,12]',
        'platform_rate_bps' => 2_000,
        'platform_cut_minor' => 7_000,
        'term_start' => '2026-01-01 00:00:00',
        'term_end' => '2026-04-01 00:00:00',
        'client_idempotency_key' => str_repeat('0', 36),
        'provider' => 'scripted',
        'provider_reference' => null,
        'status' => 'pending',
        'paid_at' => null,
    ], $overrides);
}

/** A row that satisfies every constraint on `ledger_entries`. */
function validLedgerRow(array $overrides = []): array
{
    return array_merge([
        'instructor_id' => 7,
        'type' => 'release',
        'amount_minor' => 3_111,
        'period_start' => '2026-03-01',
        'recognized_through_at' => '2026-03-31 00:00:00',
        'effective_at' => '2026-03-31 00:00:00',
        'source_ref' => 'period:2026-03',
        'payout_id' => null,
    ], $overrides);
}

/** A row that satisfies every constraint on `payout_attempts`. */
function validAttemptRow(int $payoutId, array $overrides = []): array
{
    return array_merge([
        'payout_id' => $payoutId,
        'attempt_no' => 1,
        'idempotency_key' => 'key-1',
        'status' => 'sending',
        'provider_reference' => null,
        'request_payload' => '{"amount_minor":4667}',
        'response_payload' => null,
        'resolution_evidence' => null,
        'started_at' => '2026-04-01 10:00:00',
        'lease_expires_at' => '2026-04-01 10:05:00',
        'polled_at' => null,
        'poll_count' => 0,
    ], $overrides);
}

describe('subscriptions', function () {
    it('rejects a term that does not advance', function () {
        // §4: term length is derived from the dates. ReleaseCalculator::termDays()
        // refuses a term of under one whole day; this is the same rule in the
        // database, so a bad row cannot reach the calculator in the first place.
        rejectsWith(ERR_CHECK, fn () => DB::table('subscriptions')->insert(
            validSubscriptionRow(['ends_at' => '2026-01-01 00:00:00'])
        ));

        rejectsWith(ERR_CHECK, fn () => DB::table('subscriptions')->insert(
            validSubscriptionRow(['ends_at' => '2025-12-31 00:00:00'])
        ));
    });

    it('rejects an access termination outside the term', function () {
        // §8 precondition 3: both the gross and the instructor shares must be
        // released against the same clamped fraction. An access_ends_at outside
        // [starts_at, ends_at] would put elapsed_days and effective_days into
        // disagreement and the platform-share proof would collapse.
        rejectsWith(ERR_CHECK, fn () => DB::table('subscriptions')->insert(
            validSubscriptionRow(['access_ends_at' => '2025-12-31 00:00:00'])
        ));

        rejectsWith(ERR_CHECK, fn () => DB::table('subscriptions')->insert(
            validSubscriptionRow(['access_ends_at' => '2026-04-02 00:00:00'])
        ));
    });

    it('accepts access ending exactly at the start, which is how a full refund is expressed', function () {
        // §6.1: setting access_ends_at = starts_at makes effective_days 0 and a
        // full refund falls out of the existing clamp with no new code. The
        // CHECK uses >= rather than > precisely so this stays expressible.
        DB::table('subscriptions')->insert(
            validSubscriptionRow(['access_ends_at' => '2026-01-01 00:00:00'])
        );

        // §6.1: and a subscription whose auto-renew was cancelled caps nothing.
        DB::table('subscriptions')->insert(validSubscriptionRow([
            'student_id' => 2,
            'cancelled_at' => '2026-02-01 00:00:00',
            'access_ends_at' => null,
        ]));

        expect(DB::table('subscriptions')->count())->toBe(2);
    });
});

describe('subscription_payments', function () {
    beforeEach(function () {
        $this->subscriptionId = Subscription::factory()->create()->id;
    });

    it('rejects a replayed client idempotency key', function () {
        // §5.1: generated by us before the call. Guards the inbound mirror of
        // the `unknown` payout state — a charge request that timed out, where
        // no provider reference exists to dedupe against yet.
        DB::table('subscription_payments')->insert(validPaymentRow($this->subscriptionId));

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, ['amount_minor' => 1, 'platform_cut_minor' => 0])
        ));
    });

    it('rejects a replayed provider reference within one provider', function () {
        // §5.1: guards webhook replay and settlement-file reprocessing.
        DB::table('subscription_payments')->insert(validPaymentRow($this->subscriptionId, [
            'client_idempotency_key' => str_repeat('1', 36),
            'provider_reference' => 'ch_abc',
        ]));

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, [
                'client_idempotency_key' => str_repeat('2', 36),
                'provider_reference' => 'ch_abc',
            ])
        ));

        // §5.1: references are only guaranteed unique *within* a provider, so
        // the same string under a second provider must still insert.
        DB::table('subscription_payments')->insert(validPaymentRow($this->subscriptionId, [
            'client_idempotency_key' => str_repeat('3', 36),
            'provider' => 'other',
            'provider_reference' => 'ch_abc',
        ]));

        expect(DB::table('subscription_payments')->count())->toBe(2);
    });

    it('tolerates many unconfirmed payments with a null provider reference', function () {
        // §5.1: this is the first of the three deliberate uses of NULL in a
        // unique index. Here many NULLs are *tolerated*, because uniqueness for
        // unconfirmed rows is carried by client_idempotency_key instead.
        foreach (['4', '5', '6'] as $n) {
            DB::table('subscription_payments')->insert(validPaymentRow($this->subscriptionId, [
                'client_idempotency_key' => str_repeat($n, 36),
                'provider_reference' => null,
            ]));
        }

        expect(DB::table('subscription_payments')->count())->toBe(3);
    });

    it('rejects a non-positive or negative gross', function () {
        rejectsWith(ERR_CHECK, fn () => DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, ['amount_minor' => 0, 'platform_cut_minor' => 0])
        ));

        // §3: BIGINT UNSIGNED — a payment is never negative. This is rejected by
        // the column type rather than the CHECK, which is why the error differs.
        rejectsWith(ERR_OUT_OF_RANGE, fn () => DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, ['amount_minor' => -1, 'platform_cut_minor' => 0])
        ));
    });

    it('rejects a rate above 100 percent', function () {
        // §3: 10000 basis points is 100%. Bps::guard() enforces the same bound
        // in PHP; this is the database refusing to store what it would reject.
        rejectsWith(ERR_CHECK, fn () => DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, ['platform_rate_bps' => 10_001])
        ));

        // Exactly 10000 is legal: a 100% platform rate leaves a pool of zero.
        DB::table('subscription_payments')->insert(validPaymentRow($this->subscriptionId, [
            'platform_rate_bps' => 10_000,
            'platform_cut_minor' => 35_000,
        ]));

        expect(DB::table('subscription_payments')->count())->toBe(1);
    });

    it('rejects a platform cut larger than the gross', function () {
        // §5.4: the pool is floored and the cut is the residue, so the cut can
        // never exceed the gross. §8 precondition 2 depends on pool <= gross.
        rejectsWith(ERR_CHECK, fn () => DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, ['platform_cut_minor' => 35_001])
        ));
    });

    it('rejects a payment that names no instructors', function () {
        // §5.2: the split is across the instructors the subscription grants
        // access to, frozen at payment time. An empty set would leave the whole
        // instructor pool unallocated and break invariant 1, so the database
        // refuses it rather than the allocation job discovering it later.
        rejectsWith(ERR_CHECK, fn () => DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, ['instructor_ids' => '[]'])
        ));
    });

    it('rejects an instructor set that is not a JSON array', function () {
        // JSON_LENGTH alone would accept an object — {"a":1} has length 1 — so
        // the type half of the CHECK is load-bearing, not decoration.
        rejectsWith(ERR_CHECK, fn () => DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, ['instructor_ids' => '{"3": 1}'])
        ));
    });

    it('accepts a single-instructor payment', function () {
        // The boundary on the other side: one instructor takes the whole pool,
        // which §5.5 handles as a one-way largest-remainder split.
        DB::table('subscription_payments')->insert(
            validPaymentRow($this->subscriptionId, ['instructor_ids' => '[3]'])
        );

        expect(DB::table('subscription_payments')->count())->toBe(1);
    });
});

describe('refunds', function () {
    beforeEach(function () {
        $this->paymentId = SubscriptionPayment::factory()->create()->id;
    });

    it('rejects a replayed refund webhook', function () {
        // §9.1: guards a refund webhook delivered twice.
        DB::table('refunds')->insert([
            'payment_id' => $this->paymentId, 'amount_minor' => 5_000,
            'kind' => 'termination_prorata', 'reason' => 'Terminated mid-term.',
            'effective_at' => '2026-03-15 00:00:00', 'provider' => 'scripted',
            'provider_reference' => 'rfnd_1', 'processed_at' => '2026-04-03 00:00:00',
        ]);

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('refunds')->insert([
            'payment_id' => $this->paymentId, 'amount_minor' => 5_000,
            'kind' => 'termination_prorata', 'reason' => 'Terminated mid-term.',
            'effective_at' => '2026-03-15 00:00:00', 'provider' => 'scripted',
            'provider_reference' => 'rfnd_1', 'processed_at' => '2026-04-03 00:00:00',
        ]));
    });

    it('rejects a zero-magnitude refund', function () {
        // §3: magnitude only — direction is implied by kind, so zero has no
        // meaning here.
        rejectsWith(ERR_CHECK, fn () => DB::table('refunds')->insert([
            'payment_id' => $this->paymentId, 'amount_minor' => 0,
            'kind' => 'goodwill_partial', 'reason' => 'x',
            'effective_at' => '2026-03-15 00:00:00', 'provider' => 'scripted',
            'provider_reference' => 'rfnd_2', 'processed_at' => '2026-04-03 00:00:00',
        ]));
    });

    it('rejects a refund kind the design does not define', function () {
        // §6.1: each kind has its own recognition behaviour and its own test.
        // A fourth kind arriving unannounced would fall through that table.
        rejectsWith(ERR_BAD_ENUM, fn () => DB::table('refunds')->insert([
            'payment_id' => $this->paymentId, 'amount_minor' => 1,
            'kind' => 'chargeback', 'reason' => 'x',
            'effective_at' => '2026-03-15 00:00:00', 'provider' => 'scripted',
            'provider_reference' => 'rfnd_3', 'processed_at' => '2026-04-03 00:00:00',
        ]));
    });
});

describe('revenue_allocations', function () {
    beforeEach(function () {
        $this->paymentId = SubscriptionPayment::factory()->create()->id;
    });

    it('rejects a second allocation for the same instructor on one payment', function () {
        // §5.1: payment uniqueness does NOT make allocation idempotent. An
        // allocation job retried against an already-confirmed payment would
        // otherwise allocate twice; this constraint is what stops it.
        DB::table('revenue_allocations')->insert([
            'payment_id' => $this->paymentId, 'instructor_id' => 3,
            'amount_minor' => 9_334, 'weight_numerator' => 1, 'weight_denominator' => 3,
        ]);

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('revenue_allocations')->insert([
            'payment_id' => $this->paymentId, 'instructor_id' => 3,
            'amount_minor' => 9_333, 'weight_numerator' => 1, 'weight_denominator' => 3,
        ]));
    });

    it('rejects a zero weight denominator', function () {
        // §3.1: the weight is an exact rational. LargestRemainder::apportion()
        // refuses a zero total weight for the same reason — there is no split
        // to divide by.
        rejectsWith(ERR_CHECK, fn () => DB::table('revenue_allocations')->insert([
            'payment_id' => $this->paymentId, 'instructor_id' => 4,
            'amount_minor' => 1, 'weight_numerator' => 1, 'weight_denominator' => 0,
        ]));
    });

    it('rejects a negative allocation, because an entitlement is unsigned', function () {
        // §3: revenue_allocations.amount_minor is BIGINT UNSIGNED — the
        // canonical entitlement, never negative. This is the deliberate
        // counterpart to the SIGNED ledger amount asserted below. §19 records
        // that the unbuilt counter-allocation path is what would force this
        // column to become signed.
        rejectsWith(ERR_OUT_OF_RANGE, fn () => DB::table('revenue_allocations')->insert([
            'payment_id' => $this->paymentId, 'instructor_id' => 5,
            'amount_minor' => -1, 'weight_numerator' => 1, 'weight_denominator' => 3,
        ]));
    });
});

describe('ledger_entries', function () {
    it('accepts a negative amount, because corrections are compensating entries', function () {
        // §3: BIGINT SIGNED, and the exact opposite of the allocation column
        // above. §6.2's worked example posts release_correction -104 when a
        // termination shortens the term after a release has already landed.
        DB::table('ledger_entries')->insert(validLedgerRow([
            'type' => 'release_correction',
            'amount_minor' => -104,
            'source_ref' => 'refund:9812',
        ]));

        expect(DB::table('ledger_entries')->value('amount_minor'))->toBe(-104);
    });

    it('rejects the same source ref twice for one instructor and type', function () {
        // §9.1: guards a release run executed twice and a refund processed
        // twice. §6.2 keys a scheduled run on the posting period, so re-running
        // a month is a no-op by constraint as well as by the watermark guard.
        DB::table('ledger_entries')->insert(validLedgerRow());

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('ledger_entries')->insert(
            validLedgerRow(['amount_minor' => 9_999])
        ));
    });

    it('allows one source ref to appear under different types', function () {
        // The unique key is (instructor_id, type, source_ref), not
        // (instructor_id, source_ref): a period can carry both a release and a
        // later release_correction, and a single refund event can produce a
        // correction and an adjustment.
        DB::table('ledger_entries')->insert(validLedgerRow(['source_ref' => 'refund:9812']));
        DB::table('ledger_entries')->insert(validLedgerRow([
            'type' => 'release_correction', 'amount_minor' => -104, 'source_ref' => 'refund:9812',
        ]));
        DB::table('ledger_entries')->insert(validLedgerRow([
            'type' => 'refund_adjustment', 'amount_minor' => -30, 'source_ref' => 'refund:9812',
        ]));

        // ...and a different instructor is a different key again.
        DB::table('ledger_entries')->insert(validLedgerRow([
            'instructor_id' => 8, 'source_ref' => 'refund:9812',
        ]));

        expect(DB::table('ledger_entries')->count())->toBe(4);
    });

    it('rejects a null source ref', function () {
        // §14: NOT NULL with a deterministic value, precisely because MySQL
        // permits many NULLs in a unique index. This is the second of the three
        // NULL modes in §5.1 — here NULLs are *forbidden*, because uniqueness
        // is the entire point of the column.
        rejectsWith(ERR_NOT_NULL, fn () => DB::table('ledger_entries')->insert(
            validLedgerRow(['source_ref' => null])
        ));
    });

    it('accepts a type outside the payable allowlist', function () {
        // §10.1: the claim query enumerates what may be paid rather than
        // assuming the table holds nothing else. That argument only works if
        // the table can actually hold something else, so `type` is deliberately
        // an open VARCHAR with no CHECK. Closing it would move the guarantee to
        // the wrong place and make invariant 29 untestable.
        DB::table('ledger_entries')->insert(validLedgerRow([
            'type' => 'tax_withholding', 'amount_minor' => -250, 'source_ref' => 'tax:2026-03',
        ]));

        expect(DB::table('ledger_entries')->where('type', 'tax_withholding')->exists())->toBeTrue();
    });

    it('stores the three timestamps of §3.3 independently', function () {
        // §3.3's worked example: a refund dated 15 March, processed 3 April,
        // watermark at 31 March. All three values differ, and collapsing any
        // two of them would lose information the system depends on —
        // effective_at drives payout eligibility (§10.1), recognized_through_at
        // is the horizon the row accounts for (§6.2), and period_start is
        // merely when it was posted (§6.3).
        DB::table('ledger_entries')->insert(validLedgerRow([
            'type' => 'release_correction',
            'amount_minor' => -104,
            'period_start' => '2026-04-01',
            'recognized_through_at' => '2026-03-31 00:00:00',
            'effective_at' => '2026-03-15 00:00:00',
            'source_ref' => 'refund:9812',
        ]));

        $row = DB::table('ledger_entries')->first();

        expect($row->period_start)->toBe('2026-04-01')
            ->and($row->recognized_through_at)->toBe('2026-03-31 00:00:00')
            ->and($row->effective_at)->toBe('2026-03-15 00:00:00');
    });
});

describe('instructor_balances', function () {
    it('rejects a second balance row for one instructor', function () {
        // §9.4: this row is *the* per-instructor serialisation point. Two rows
        // for one instructor would mean two serialisation points, and every
        // FOR UPDATE in the design would be locking a coin flip.
        DB::table('instructor_balances')->insert(['instructor_id' => 11]);

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('instructor_balances')->insert(['instructor_id' => 11]));
    });

    it('accepts a negative available balance, because debt lives here', function () {
        // §10.4: "Debt is not a payout." A negative balance is unclaimed
        // negative ledger entries, and the cache mirrors that. Invariant 33
        // depends on this being storable rather than clamped at zero.
        DB::table('instructor_balances')->insert([
            'instructor_id' => 12,
            'recognized_minor' => -50,
            'available_minor' => -50,
        ]);

        $row = DB::table('instructor_balances')->where('instructor_id', 12)->first();

        expect((int) $row->available_minor)->toBe(-50)
            ->and((int) $row->recognized_minor)->toBe(-50);
    });

    it('rejects a negative reserved or paid bucket', function () {
        // §10.4 guarantees every committed payout has a positive amount, so
        // neither bucket can legitimately go negative. Unsigned makes an
        // arithmetic slip in the §10.5 cache updates fail at the write rather
        // than surface later as a §12 bucket mismatch.
        rejectsWith(ERR_OUT_OF_RANGE, fn () => DB::table('instructor_balances')->insert([
            'instructor_id' => 13, 'reserved_minor' => -1,
        ]));

        rejectsWith(ERR_OUT_OF_RANGE, fn () => DB::table('instructor_balances')->insert([
            'instructor_id' => 14, 'paid_minor' => -1,
        ]));
    });

    it('carries the watermark as a nullable column', function () {
        // §1.1 / §12 level three: the stored watermark is an operational cursor
        // checked against MAX(ledger_entries.recognized_through_at). Null means
        // nothing has been posted yet.
        DB::table('instructor_balances')->insert(['instructor_id' => 15]);
        expect(DB::table('instructor_balances')->where('instructor_id', 15)->value('recognized_through_at'))->toBeNull();

        DB::table('instructor_balances')->where('instructor_id', 15)
            ->update(['recognized_through_at' => '2026-03-31 00:00:00']);

        expect(DB::table('instructor_balances')->where('instructor_id', 15)->value('recognized_through_at'))
            ->toBe('2026-03-31 00:00:00');
    });
});

describe('payouts', function () {
    beforeEach(function () {
        $this->batchId = PayoutBatch::factory()->create()->id;
    });

    it('rejects a second payout for one instructor in one batch', function () {
        // §11.4: the payout command run twice, or two servers running it at
        // once, both land on this constraint. Invariant 25.
        DB::table('payouts')->insert([
            'batch_id' => $this->batchId, 'instructor_id' => 21,
            'amount_minor' => 4_667, 'status' => 'pending',
        ]);

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('payouts')->insert([
            'batch_id' => $this->batchId, 'instructor_id' => 21,
            'amount_minor' => 4_667, 'status' => 'pending',
        ]));
    });

    it('accepts a null amount, which is the intermediate the claim transaction needs', function () {
        // §10.4: the payout id must exist before the ledger entries can be
        // stamped with it, but the amount is not known until after the claim.
        // CHECK (amount_minor > 0) on a NOT NULL column makes that impossible
        // to express. Invariant 17 is that this NULL never survives the
        // transaction — which is a property of the service, not of the schema,
        // and is asserted in tests/Invariants once that service exists.
        DB::table('payouts')->insert([
            'batch_id' => $this->batchId, 'instructor_id' => 22,
            'amount_minor' => null, 'status' => 'pending',
        ]);

        expect(DB::table('payouts')->where('instructor_id', 22)->value('amount_minor'))->toBeNull();
    });

    it('rejects a zero or negative payout amount', function () {
        // §10.4: a claimed set nets to zero or below, and the whole transaction
        // rolls back rather than committing a payout for nothing.
        rejectsWith(ERR_CHECK, fn () => DB::table('payouts')->insert([
            'batch_id' => $this->batchId, 'instructor_id' => 23,
            'amount_minor' => 0, 'status' => 'pending',
        ]));

        rejectsWith(ERR_OUT_OF_RANGE, fn () => DB::table('payouts')->insert([
            'batch_id' => $this->batchId, 'instructor_id' => 24,
            'amount_minor' => -1, 'status' => 'pending',
        ]));
    });

    it('rejects a payout status outside the state machine', function () {
        // §10.2: pending -> in_progress -> settled | failed | needs_review.
        // §10.7 warns that any sweeper predicate written as "non-terminal"
        // rather than an explicit status list silently undoes the rule that a
        // human-flagged payout is never machine-retried. Those lists are only
        // as trustworthy as the set of values that can reach the column.
        rejectsWith(ERR_BAD_ENUM, fn () => DB::table('payouts')->insert([
            'batch_id' => $this->batchId, 'instructor_id' => 25,
            'amount_minor' => 1, 'status' => 'cancelled',
        ]));
    });
});

describe('payout_attempts', function () {
    beforeEach(function () {
        $this->payoutId = Payout::factory()->create()->id;
    });

    it('rejects a replayed idempotency key', function () {
        // §9.1: guards a queued job retried mid-flight. The key is derived as
        // hash(payout_id, attempt_no), so a re-run produces the same key and
        // the insert simply fails instead of creating a second transfer.
        DB::table('payout_attempts')->insert(validAttemptRow($this->payoutId));

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('payout_attempts')->insert(
            validAttemptRow($this->payoutId, ['attempt_no' => 2, 'status' => 'failed'])
        ));
    });

    it('rejects two workers creating the same attempt number', function () {
        // §9.1 / §10.2: two workers racing for attempt #2.
        DB::table('payout_attempts')->insert(validAttemptRow($this->payoutId, ['status' => 'failed']));

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('payout_attempts')->insert(
            validAttemptRow($this->payoutId, ['idempotency_key' => 'key-other', 'status' => 'failed'])
        ));
    });

    it('rejects a second attempt while one is still in flight', function () {
        // §10.2: this is the constraint behind invariant 18, and the third of
        // §5.1's NULL modes — here NULLs are *exploited*. A non-terminal
        // attempt generates active_payout_id = payout_id; two of them collide.
        // The gate in §10.2 is the mechanism, this is the backstop that holds
        // even if the gate is bypassed entirely, as it is here.
        DB::table('payout_attempts')->insert(validAttemptRow($this->payoutId));

        rejectsWith(ERR_DUPLICATE, fn () => DB::table('payout_attempts')->insert(
            validAttemptRow($this->payoutId, ['attempt_no' => 2, 'idempotency_key' => 'key-2'])
        ));

        // §11.1: `unknown` is still non-terminal — an attempt whose lease
        // expired may be holding money in flight at the provider — so it blocks
        // a new attempt exactly as `sending` does.
        rejectsWith(ERR_DUPLICATE, fn () => DB::table('payout_attempts')->insert(
            validAttemptRow($this->payoutId, ['attempt_no' => 3, 'idempotency_key' => 'key-3', 'status' => 'unknown'])
        ));
    });

    it('allows a new attempt once the previous one is terminal', function () {
        // §10.7: with no active attempt, nothing is in flight with the
        // provider, so creating the next attempt cannot double-send. Invariant
        // 27's second half: a new provider call occurs only after a previous
        // attempt reached a definitive outcome.
        DB::table('payout_attempts')->insert(validAttemptRow($this->payoutId));

        expect(DB::table('payout_attempts')->where('idempotency_key', 'key-1')->value('active_payout_id'))
            ->toBe($this->payoutId);

        DB::table('payout_attempts')->where('idempotency_key', 'key-1')->update(['status' => 'failed']);

        // The generated column is recomputed by the update, not just the insert.
        expect(DB::table('payout_attempts')->where('idempotency_key', 'key-1')->value('active_payout_id'))
            ->toBeNull();

        DB::table('payout_attempts')->insert(
            validAttemptRow($this->payoutId, ['attempt_no' => 2, 'idempotency_key' => 'key-2'])
        );

        expect(DB::table('payout_attempts')->count())->toBe(2);
    });

    it('lets many terminal attempts coexist for one payout', function () {
        // Terminal attempts all generate NULL, and MySQL tolerates many NULLs
        // in a unique index. Without that, a payout could only ever have one
        // historical attempt and the §10.2 evidence trail would be lost.
        DB::table('payout_attempts')->insert(validAttemptRow($this->payoutId, [
            'attempt_no' => 1, 'idempotency_key' => 'key-1', 'status' => 'failed',
        ]));
        DB::table('payout_attempts')->insert(validAttemptRow($this->payoutId, [
            'attempt_no' => 2, 'idempotency_key' => 'key-2', 'status' => 'failed',
        ]));
        DB::table('payout_attempts')->insert(validAttemptRow($this->payoutId, [
            'attempt_no' => 3, 'idempotency_key' => 'key-3', 'status' => 'unresolved',
        ]));

        expect(DB::table('payout_attempts')->whereNull('active_payout_id')->count())->toBe(3);
    });

    it('rejects a pending attempt, because there is no such state', function () {
        // §10.2: an attempt row is created already in `sending`, inside the
        // transaction that acquires the slot, so no attempt can exist without a
        // worker having committed to calling the provider. A `pending` attempt
        // would be a stuck row that blocks the payout and falls outside the
        // lease sweeper — an entire class of bug removed by the enum.
        rejectsWith(ERR_BAD_ENUM, fn () => DB::table('payout_attempts')->insert(
            validAttemptRow($this->payoutId, ['status' => 'pending'])
        ));
    });
});

describe('reconciliation_alerts', function () {
    it('rejects an alert kind the design does not define', function () {
        // §12: reconciliation fails loudly rather than repairing silently, and
        // each kind names a specific class of bug.
        rejectsWith(ERR_BAD_ENUM, fn () => DB::table('reconciliation_alerts')->insert([
            'kind' => 'something_odd', 'subject_type' => 'payout', 'subject_id' => 1,
            'detail' => '{}', 'detected_at' => '2026-04-01 10:00:00',
        ]));
    });
});

describe('foreign keys', function () {
    it('rejects a ledger entry stamped with a payout that does not exist', function () {
        rejectsWith(ERR_FK_INSERT, fn () => DB::table('ledger_entries')->insert(
            validLedgerRow(['payout_id' => 999_999])
        ));
    });

    it('restricts rather than cascades when a referenced money row is deleted', function () {
        // Every foreign key here is ON DELETE RESTRICT. A cascade would let a
        // delete somewhere upstream silently remove committed money rows and
        // their audit trail — and §12's bucket identity would then balance
        // against a ledger that had quietly lost entries.
        $payout = Payout::factory()->create();
        DB::table('payout_attempts')->insert(validAttemptRow($payout->id));

        rejectsWith(ERR_FK_DELETE, fn () => DB::table('payouts')->where('id', $payout->id)->delete());

        expect(DB::table('payout_attempts')->count())->toBe(1);
    });
});
