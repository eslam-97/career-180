<?php

// ARCHITECTURE.md §6.1, §7, §7.1, §3.3 — recording a refund and what each kind
// does to access.
//
// The §6 worked example is the fixture throughout: 9,334 over a 90-day term.
// Released in full at term end it is 9,334; terminated on day 45 it is 4,667,
// and the instructor keeps that and is never owed the rest (§7, "there is no
// clawback in the common case").

use App\Domain\Recognition\ReleaseService;
use App\Domain\Refund\RecordRefundService;
use App\Domain\Refund\RefundPolicy;
use App\Jobs\CorrectInstructorRecognition;
use App\Models\LedgerEntry;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Support\RecognitionFixtures;

/**
 * Pest file-scope functions are global across the whole suite, so these carry a
 * prefix — the same convention every other test file here follows.
 */
function refundTestRecord(SubscriptionPayment $payment, array $overrides = []): Refund
{
    return app(RecordRefundService::class)->record(
        $overrides['payment_id'] ?? $payment->id,
        $overrides['amount_minor'] ?? 5_000,
        $overrides['kind'] ?? RefundPolicy::TERMINATION_PRORATA,
        $overrides['reason'] ?? 'Student terminated mid-term.',
        $overrides['effective_at'] ?? RecognitionFixtures::utc('2026-02-15 00:00:00'),
        $overrides['provider'] ?? 'scripted',
        $overrides['provider_reference'] ?? 'rfnd_'.Str::random(16),
        $overrides['processed_at'] ?? RecognitionFixtures::utc('2026-02-16 09:00:00'),
    );
}

function refundTestSubscription(SubscriptionPayment $payment): Subscription
{
    return Subscription::query()->findOrFail($payment->subscription_id);
}

function refundTestAccessEndsAt(SubscriptionPayment $payment): ?string
{
    return refundTestSubscription($payment)->access_ends_at?->format('Y-m-d H:i:s');
}

beforeEach(function () {
    $this->instructorId = 3;
    // §4: [starts_at, ends_at) — 90 whole days, so day 45 is 2026-02-15.
    $this->termStart = RecognitionFixtures::utc('2026-01-01 00:00:00');
    $this->termEnd = RecognitionFixtures::utc('2026-04-01 00:00:00');

    $this->payment = RecognitionFixtures::allocation(
        $this->instructorId,
        9_334,
        $this->termStart,
        $this->termEnd,
    );

    // The whole term recognized, watermark parked at term end. Every refund
    // below therefore has something to claw back, which is the interesting case.
    app(ReleaseService::class)->releaseScheduled($this->instructorId, $this->termEnd);

    expect(RecognitionFixtures::posted($this->instructorId))->toBe(9_334);
});

// §6.1's table, one row at a time. "Each kind has its own test."

it('termination_prorata ends access at the refund date and corrects the ledger', function () {
    $refund = refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-02-15 00:00:00'),
    ]);

    // §7.1: "terminating access at the refund date is taken to be the correct
    // representation of the student's remaining financial entitlement".
    expect(refundTestAccessEndsAt($this->payment))->toBe('2026-02-15 00:00:00');

    // §6: effective_days 45 of 90, so released(W) is floor(9,334 × 45/90).
    // §7: the already-recognized half comes back as a compensating entry; the
    // unearned half simply never becomes payable again.
    $correction = LedgerEntry::query()
        ->where('instructor_id', $this->instructorId)
        ->where('type', 'release_correction')
        ->sole();

    expect($correction->amount_minor)->toBe(-4_667)
        ->and($correction->source_ref)->toBe('refund:'.$refund->id)
        ->and(RecognitionFixtures::posted($this->instructorId))->toBe(4_667);

    // §10.5: the cache is maintained by the transaction that moved the ledger.
    expect(RecognitionFixtures::balance($this->instructorId)->recognized_minor)->toBe(4_667)
        ->and(RecognitionFixtures::balance($this->instructorId)->available_minor)->toBe(4_667);
});

it('termination_full ends access at starts_at and claws everything back', function () {
    refundTestRecord($this->payment, [
        'kind' => RefundPolicy::TERMINATION_FULL,
        'amount_minor' => 9_334,
        'effective_at' => RecognitionFixtures::utc('2026-02-15 00:00:00'),
    ]);

    // §6.1: "setting access_ends_at = starts_at makes a full refund fall out of
    // the existing clamp with no new code" — and note it is starts_at, NOT the
    // refund's own date, which is what separates this kind from prorata.
    expect(refundTestAccessEndsAt($this->payment))->toBe('2026-01-01 00:00:00');

    // effective_days = 0, so released(W) = 0 and the correction is the whole
    // posted amount.
    $correction = LedgerEntry::query()
        ->where('instructor_id', $this->instructorId)
        ->where('type', 'release_correction')
        ->sole();

    expect($correction->amount_minor)->toBe(-9_334)
        ->and(RecognitionFixtures::posted($this->instructorId))->toBe(0)
        ->and(RecognitionFixtures::balance($this->instructorId)->available_minor)->toBe(0);
});

it('goodwill_partial records the refund and caps nothing', function () {
    $before = RecognitionFixtures::balance($this->instructorId)->getAttributes();

    $refund = refundTestRecord($this->payment, [
        'kind' => RefundPolicy::GOODWILL_PARTIAL,
        'amount_minor' => 1_200,
        'reason' => 'Service complaint; access continues.',
    ]);

    // The event is recorded — a goodwill refund really happened at the provider.
    expect(Refund::query()->count())->toBe(1)
        ->and($refund->kind)->toBe(RefundPolicy::GOODWILL_PARTIAL)
        ->and($refund->amount_minor)->toBe(1_200);

    // §6.1: "Goodwill partial refund, access continues — recognition cap: none".
    expect(refundTestAccessEndsAt($this->payment))->toBeNull();

    // §7.1 + §8: the instructor's entitlement is capped by access, not by the
    // refund amount, so the whole 1,200 comes out of the platform share. The
    // ledger does not move at all.
    //
    // §19 is the limitation this makes reachable from production code: the
    // refund_adjustment counter-allocation that would reduce the instructor's
    // entitlement is not implemented, and nothing here pretends otherwise by
    // terminating an access the student still has.
    expect(LedgerEntry::query()->where('instructor_id', $this->instructorId)->count())->toBe(1)
        ->and(RecognitionFixtures::posted($this->instructorId))->toBe(9_334)
        ->and(RecognitionFixtures::balance($this->instructorId)->getAttributes())->toBe($before);
});

it('goodwill_partial dispatches no recompute', function () {
    Bus::fake();

    refundTestRecord($this->payment, ['kind' => RefundPolicy::GOODWILL_PARTIAL]);

    // Nothing moved expected(t), so there is no delta to post. The job is not
    // dispatched to do nothing.
    Bus::assertNothingDispatched();
});

// §6.1: "access termination is not cancellation".

it('never touches cancelled_at', function () {
    // §6.1, first row of the table: the student cancelled auto-renew and the
    // term continues. That fact must survive a refund that terminates access,
    // because the two answer different questions.
    Subscription::query()
        ->whereKey($this->payment->subscription_id)
        ->update(['cancelled_at' => '2026-01-20 00:00:00', 'status' => 'cancelled']);

    refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-02-15 00:00:00'),
    ]);

    $subscription = refundTestSubscription($this->payment);

    expect($subscription->cancelled_at->format('Y-m-d H:i:s'))->toBe('2026-01-20 00:00:00')
        ->and($subscription->access_ends_at->format('Y-m-d H:i:s'))->toBe('2026-02-15 00:00:00');
});

// §14: CHECK (access_ends_at >= starts_at AND access_ends_at <= ends_at). A
// refund dated outside the term is clamped into the window; the refunds row
// keeps its own date (§3.3).

it('clamps a prorata refund dated after the term, leaving recognition uncapped', function () {
    $refund = refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-05-01 00:00:00'),
    ]);

    // effective_days == term_days, so §6's clamp caps nothing — correct,
    // because nothing was unearned.
    expect(refundTestAccessEndsAt($this->payment))->toBe('2026-04-01 00:00:00')
        ->and(RecognitionFixtures::posted($this->instructorId))->toBe(9_334)
        ->and(LedgerEntry::query()->where('type', 'release_correction')->count())->toBe(0);

    // §3.3: only access_ends_at was clamped. The refund's business date is the
    // payout-eligibility predicate (§10.1) and is stored exactly as given.
    expect($refund->fresh()->effective_at->format('Y-m-d H:i:s'))->toBe('2026-05-01 00:00:00');
});

it('clamps a prorata refund dated before the term, clawing everything back', function () {
    $refund = refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2025-12-01 00:00:00'),
    ]);

    // access_ends_at = starts_at, which is the termination_full shape:
    // effective_days = 0. Correct, because nothing was earned.
    expect(refundTestAccessEndsAt($this->payment))->toBe('2026-01-01 00:00:00')
        ->and(RecognitionFixtures::posted($this->instructorId))->toBe(0);

    expect($refund->fresh()->effective_at->format('Y-m-d H:i:s'))->toBe('2025-12-01 00:00:00');
});

// A refund may only ever shorten access. Otherwise a later, weaker refund would
// push access_ends_at back out and §6.2 would re-release money already clawed
// back.

it('does not extend access when a later prorata refund follows an earlier one', function () {
    refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-02-15 00:00:00'),
        'provider_reference' => 'rfnd_first',
    ]);

    expect(RecognitionFixtures::posted($this->instructorId))->toBe(4_667);

    // Day 70. Without the monotonic rule this would set effective_days to 70,
    // make released(W) 7,259 and post a +2,592 release — re-recognizing money
    // the first refund removed.
    refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-03-12 00:00:00'),
        'provider_reference' => 'rfnd_second',
    ]);

    expect(refundTestAccessEndsAt($this->payment))->toBe('2026-02-15 00:00:00')
        ->and(RecognitionFixtures::posted($this->instructorId))->toBe(4_667)
        ->and(Refund::query()->count())->toBe(2);
});

it('does not extend access when a prorata refund follows a full refund', function () {
    refundTestRecord($this->payment, [
        'kind' => RefundPolicy::TERMINATION_FULL,
        'provider_reference' => 'rfnd_full',
    ]);

    expect(RecognitionFixtures::posted($this->instructorId))->toBe(0);

    refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-02-15 00:00:00'),
        'provider_reference' => 'rfnd_prorata',
    ]);

    // access_ends_at is already starts_at; a prorata refund cannot give access
    // back, and the ledger stays at zero.
    expect(refundTestAccessEndsAt($this->payment))->toBe('2026-01-01 00:00:00')
        ->and(RecognitionFixtures::posted($this->instructorId))->toBe(0);
});

// §9.1: UNIQUE(provider, provider_reference) guards a refund webhook replay.

it('changes nothing when the same refund is received twice', function () {
    $first = refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-02-15 00:00:00'),
        'provider_reference' => 'rfnd_replayed',
    ]);

    $ledgerBefore = RecognitionFixtures::entries($this->instructorId)->pluck('id')->all();
    $balanceBefore = RecognitionFixtures::balance($this->instructorId)->getAttributes();

    $second = refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-02-15 00:00:00'),
        'provider_reference' => 'rfnd_replayed',
    ]);

    // The replay learns it lost and returns the row the first delivery wrote.
    expect($second->id)->toBe($first->id)
        ->and(Refund::query()->count())->toBe(1)
        ->and(refundTestAccessEndsAt($this->payment))->toBe('2026-02-15 00:00:00')
        ->and(RecognitionFixtures::entries($this->instructorId)->pluck('id')->all())->toBe($ledgerBefore)
        ->and(RecognitionFixtures::balance($this->instructorId)->getAttributes())->toBe($balanceBefore);
});

it('dispatches the recompute once across two deliveries of one refund', function () {
    Bus::fake();

    refundTestRecord($this->payment, ['provider_reference' => 'rfnd_replayed']);
    refundTestRecord($this->payment, ['provider_reference' => 'rfnd_replayed']);

    Bus::assertDispatchedTimes(CorrectInstructorRecognition::class, 1);
});

it('refuses a different refund reusing an existing provider reference', function () {
    refundTestRecord($this->payment, [
        'amount_minor' => 5_000,
        'provider_reference' => 'rfnd_reused',
    ]);

    // Swallowing a duplicate is only safe if it really is the same event.
    refundTestRecord($this->payment, [
        'amount_minor' => 7_500,
        'provider_reference' => 'rfnd_reused',
    ]);
})->throws(DomainException::class, 'different details');

// §6.1: "a refund event dispatches a targeted recompute for the affected
// instructors".

it('dispatches one recompute per instructor on the payment', function () {
    Bus::fake();

    $subscription = Subscription::factory()->create([
        'starts_at' => $this->termStart,
        'ends_at' => $this->termEnd,
    ]);

    // §5.2 / §5.3: the frozen set, sorted and distinct, read back off the row.
    $payment = SubscriptionPayment::factory()->confirmed()->create([
        'subscription_id' => $subscription->id,
        'instructor_ids' => [3, 7, 12],
        'term_start' => $this->termStart,
        'term_end' => $this->termEnd,
    ]);

    $refund = refundTestRecord($payment, [
        'effective_at' => RecognitionFixtures::utc('2026-02-15 00:00:00'),
    ]);

    Bus::assertDispatchedTimes(CorrectInstructorRecognition::class, 3);

    foreach ([3, 7, 12] as $instructorId) {
        Bus::assertDispatched(
            CorrectInstructorRecognition::class,
            fn (CorrectInstructorRecognition $job) => $job->instructorId === $instructorId
                // §6.2: keyed on the event, never on the period.
                && $job->sourceRef === 'refund:'.$refund->id
                // §3.3: the refund's own business date, not the clamped access
                // date and not now().
                && $job->effectiveAt === '2026-02-15 00:00:00',
        );
    }
});

// §3.3: three dates with three distinct jobs, end to end through the refund
// path rather than through a hand-called ReleaseService.

it('stamps a correction with the three dates of the §3.3 worked example', function () {
    // Rebuild the watermark at 31 March rather than term end.
    LedgerEntry::query()->where('instructor_id', $this->instructorId)->delete();
    RecognitionFixtures::balance($this->instructorId)->update([
        'recognized_minor' => 0,
        'available_minor' => 0,
        'recognized_through_at' => null,
    ]);

    $watermark = RecognitionFixtures::utc('2026-03-31 00:00:00');
    app(ReleaseService::class)->releaseScheduled($this->instructorId, $watermark);

    // elapsed 89 of 90: floor(9,334 × 89/90) = 9,230.
    expect(RecognitionFixtures::posted($this->instructorId))->toBe(9_230);

    // "A refund dated 15 March, processed 3 April, watermark at 31 March."
    Carbon::setTestNow('2026-04-03 09:00:00');

    $refund = refundTestRecord($this->payment, [
        'effective_at' => RecognitionFixtures::utc('2026-03-15 00:00:00'),
        'processed_at' => RecognitionFixtures::utc('2026-04-03 09:00:00'),
    ]);

    $correction = LedgerEntry::query()
        ->where('instructor_id', $this->instructorId)
        ->where('type', 'release_correction')
        ->sole();

    // effective_days 73, so released(W) = floor(9,334 × 73/90) = 7,570.
    expect($correction->amount_minor)->toBe(-1_660)
        ->and($correction->source_ref)->toBe('refund:'.$refund->id)
        // §6.3: the POSTING period — April, because that is when it was written.
        ->and($correction->period_start->format('Y-m-d'))->toBe('2026-04-01')
        // §3.3: the horizon this row accounts for, held at W. A correction does
        // not advance time (invariant 15).
        ->and($correction->recognized_through_at->format('Y-m-d H:i:s'))->toBe('2026-03-31 00:00:00')
        // §3.3: the refund's own business date.
        ->and($correction->effective_at->format('Y-m-d H:i:s'))->toBe('2026-03-15 00:00:00');

    // Invariant 15: the cursor did not move.
    expect(RecognitionFixtures::balance($this->instructorId)->recognized_through_at->format('Y-m-d H:i:s'))
        ->toBe('2026-03-31 00:00:00');
});

// §6.1: the mapping itself, exercised directly. Pure — four dates in, one date
// or null out.

describe('RefundPolicy', function () {
    $starts = fn () => RecognitionFixtures::utc('2026-01-01 00:00:00');
    $ends = fn () => RecognitionFixtures::utc('2026-04-01 00:00:00');

    it('maps termination_prorata to the refund date', function () use ($starts, $ends) {
        expect(RefundPolicy::accessEndsAt(
            RefundPolicy::TERMINATION_PRORATA,
            RecognitionFixtures::utc('2026-02-15 00:00:00'),
            $starts(),
            $ends(),
        )->format('Y-m-d H:i:s'))->toBe('2026-02-15 00:00:00');
    });

    it('maps termination_full to starts_at whatever the refund date', function () use ($starts, $ends) {
        expect(RefundPolicy::accessEndsAt(
            RefundPolicy::TERMINATION_FULL,
            RecognitionFixtures::utc('2026-02-15 00:00:00'),
            $starts(),
            $ends(),
        )->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00');
    });

    it('maps goodwill_partial to no cap at all', function () use ($starts, $ends) {
        expect(RefundPolicy::accessEndsAt(
            RefundPolicy::GOODWILL_PARTIAL,
            RecognitionFixtures::utc('2026-02-15 00:00:00'),
            $starts(),
            $ends(),
        ))->toBeNull();
    });

    it('rejects a kind the design does not define', function () use ($starts, $ends) {
        RefundPolicy::accessEndsAt(
            'chargeback',
            RecognitionFixtures::utc('2026-02-15 00:00:00'),
            $starts(),
            $ends(),
        );
    })->throws(DomainException::class, 'no access policy');
});
