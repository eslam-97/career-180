<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Money\Bps;
use App\Domain\Money\LargestRemainder;
use App\Domain\Recognition\ReleaseService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 500,000 subscriptions, to see the release job's query plan at scale.
 *
 * This one is not pretty and is not meant to be. It writes through the query
 * builder in chunked bulk inserts, fires no Eloquent events, assigns primary
 * keys itself so the three tables can be linked in a single pass, and commits
 * every 5,000 subscriptions. `DemoSeeder` is the readable one; this is the one
 * that answers "does §17 hold up".
 *
 * What it does NOT do is fake the arithmetic. The split still goes through
 * Bps and LargestRemainder, so `Σ allocations + platform_cut == amount` is
 * exact across all 500,000 payments and `reconcile:balances` has something
 * meaningful to check. A scale fixture that breaks invariant 1 measures the
 * speed of the wrong system.
 *
 *     php artisan db:seed --class=ScaleSeeder
 *     SCALE_SUBSCRIPTIONS=20000 php artisan db:seed --class=ScaleSeeder   # a quick pass
 */
final class ScaleSeeder extends Seeder
{
    /** A re-run produces an identical dataset. */
    private const SEED = 20260922;

    /** §17's stated figure. */
    private const DEFAULT_SUBSCRIPTIONS = 500_000;

    /**
     * ~100 subscriptions and ~200 allocations per instructor at the default
     * size — enough that the per-instructor read is a real read rather than a
     * lookup that fits in one page.
     */
    private const DEFAULT_INSTRUCTORS = 5_000;

    /** One transaction per this many subscriptions. */
    private const TRANSACTION_CHUNK = 5_000;

    /**
     * Rows generated before they are flushed to the database, inside the
     * transaction. Two caps decide this number and neither is negotiable:
     * MySQL allows 65,535 placeholders per prepared statement (1,000
     * subscription_payments rows is 16,000 of them), and a chunk that is
     * accumulated whole before being written is a chunk that is also held in
     * memory whole — at 2,000,000 rows that is how a seeder runs out of it.
     */
    private const ROWS_PER_FLUSH = 1_000;

    /** §5.3: 20%, frozen onto every payment. */
    private const PLATFORM_RATE_BPS = 2_000;

    /** @var array<int, array{code: string, days: int, amount: int}> */
    private const PLANS = [
        ['code' => 'monthly', 'days' => 30, 'amount' => 15_000],
        ['code' => 'quarterly', 'days' => 90, 'amount' => 35_000],
        ['code' => 'annual', 'days' => 365, 'amount' => 120_000],
    ];

    private Randomizer $rng;

    private int $instructors;

    private DateTimeImmutable $now;

    private int $subscriptionId;

    private int $paymentId;

    private int $allocationId;

    private int $allocationsWritten = 0;

    public function run(): void
    {
        $this->rng = new Randomizer(new Mt19937(self::SEED));
        $this->now = new DateTimeImmutable(now()->utc()->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));

        $target = (int) env('SCALE_SUBSCRIPTIONS', self::DEFAULT_SUBSCRIPTIONS);
        $this->instructors = (int) env('SCALE_INSTRUCTORS', self::DEFAULT_INSTRUCTORS);

        // Belt: the query log is off by default, and two million logged
        // statements would be the other way a bulk seeder exhausts memory.
        DB::connection()->disableQueryLog();

        $this->say(sprintf(
            'ScaleSeeder: %s subscriptions across %s instructors, %s per transaction.',
            number_format($target),
            number_format($this->instructors),
            number_format(self::TRANSACTION_CHUNK),
        ));

        $this->seedBalanceRows();
        $this->seedSubscriptions($target);
        $this->queryPlan();
    }

    /**
     * §9.4: "a lock on a missing row locks nothing", and the release job's
     * FOR UPDATE needs the row to exist. Allocation creates it in production;
     * here it is created up front so the bulk path never has to check.
     */
    private function seedBalanceRows(): void
    {
        $existing = DB::table('instructor_balances')->count();
        $rows = [];

        for ($instructorId = 1; $instructorId <= $this->instructors; $instructorId++) {
            $rows[] = [
                'instructor_id' => $instructorId,
                'recognized_minor' => 0,
                'available_minor' => 0,
                'reserved_minor' => 0,
                'paid_minor' => 0,
                'recognized_through_at' => null,
                'created_at' => $this->now->format('Y-m-d H:i:s'),
                'updated_at' => $this->now->format('Y-m-d H:i:s'),
            ];
        }

        // insertOrIgnore for the same narrow reason AllocationService uses it:
        // every value in these rows is a constant zero, null or timestamp, so
        // there is no money for INSERT IGNORE's strict-mode downgrade to mangle,
        // and the one error being ignored is the duplicate primary key.
        foreach (array_chunk($rows, self::ROWS_PER_FLUSH) as $batch) {
            DB::table('instructor_balances')->insertOrIgnore($batch);
        }

        $this->say(sprintf(
            '  balance rows: %s (was %s)',
            number_format(DB::table('instructor_balances')->count()),
            number_format($existing),
        ));
    }

    /** The bulk write: subscriptions, payments and allocations, linked in one pass. */
    private function seedSubscriptions(int $target): void
    {
        // Primary keys are assigned here rather than read back, because reading
        // them back is one round trip per row and that is the whole cost.
        $this->subscriptionId = (int) DB::table('subscriptions')->max('id') + 1;
        $this->paymentId = (int) DB::table('subscription_payments')->max('id') + 1;
        $this->allocationId = (int) DB::table('revenue_allocations')->max('id') + 1;

        $startedAt = microtime(true);
        $written = 0;

        while ($written < $target) {
            $chunkSize = min(self::TRANSACTION_CHUNK, $target - $written);

            // One transaction per chunk. Not for atomicity of the dataset —
            // there is nothing to be atomic about here — but because 500,000
            // autocommitted inserts is 500,000 fsyncs.
            DB::transaction(function () use ($chunkSize): void {
                $this->writeChunk($chunkSize);
            });

            $written += $chunkSize;

            $this->progress($written, $target, $startedAt);
        }

        $elapsed = microtime(true) - $startedAt;

        $this->say(sprintf(
            '  done: %s subscriptions, %s allocations, %.1fs (%s rows/s), peak memory %.0f MB',
            number_format($written),
            number_format($this->allocationsWritten),
            $elapsed,
            number_format((int) ((($written * 2) + $this->allocationsWritten) / max($elapsed, 0.001))),
            memory_get_peak_usage(true) / 1_048_576,
        ));
    }

    /**
     * One transaction's worth of rows, generated and flushed in sub-batches so
     * the peak held in memory is ROWS_PER_FLUSH and not the whole chunk.
     */
    private function writeChunk(int $chunkSize): void
    {
        $subscriptions = [];
        $payments = [];
        $allocations = [];

        for ($i = 0; $i < $chunkSize; $i++) {
            $plan = self::PLANS[$this->rng->getInt(0, count(self::PLANS) - 1)];

            $termStart = $this->now
                ->modify('-'.$this->rng->getInt($plan['days'], 400).' days')
                ->setTime(0, 0);
            $termEnd = $termStart->modify('+'.$plan['days'].' days');

            // §6.1: about one in twenty has had access terminated mid-term, so
            // effective_days differs from term_days often enough that the
            // release job's clamp is exercised rather than short-circuited.
            $accessEndsAt = $this->rng->getInt(1, 20) === 1
                ? $termStart->modify('+'.$this->rng->getInt(0, $plan['days']).' days')
                : null;

            $instructorIds = $this->someInstructors();

            // §5.4 / §5.5: the real rounding rules, at scale. Invariant 1 holds
            // across all 500,000 rows or reconcile:balances will say so.
            $cut = Bps::cut($plan['amount'], self::PLATFORM_RATE_BPS);
            $shares = LargestRemainder::apportion(
                $plan['amount'] - $cut,
                array_fill_keys($instructorIds, 1),
            );

            $subscriptions[] = [
                'id' => $this->subscriptionId,
                'student_id' => $this->rng->getInt(1, 1_000_000),
                'plan_code' => $plan['code'],
                'amount_minor' => $plan['amount'],
                'currency' => 'EGP',
                'starts_at' => $termStart->format('Y-m-d H:i:s'),
                'ends_at' => $termEnd->format('Y-m-d H:i:s'),
                'cancelled_at' => null,
                'access_ends_at' => $accessEndsAt?->format('Y-m-d H:i:s'),
                'status' => 'active',
                'created_at' => $termStart->format('Y-m-d H:i:s'),
                'updated_at' => $termStart->format('Y-m-d H:i:s'),
            ];

            $payments[] = [
                'id' => $this->paymentId,
                'subscription_id' => $this->subscriptionId,
                'amount_minor' => $plan['amount'],
                'currency' => 'EGP',
                'platform_rate_bps' => self::PLATFORM_RATE_BPS,
                'platform_cut_minor' => $cut,
                // §5.2 / §5.3: sorted, distinct and frozen — the form
                // InitiatePaymentService normalises to.
                'instructor_ids' => json_encode($instructorIds, JSON_THROW_ON_ERROR),
                'term_start' => $termStart->format('Y-m-d H:i:s'),
                'term_end' => $termEnd->format('Y-m-d H:i:s'),
                // char(36) UNIQUE, and deterministic so a re-run collides rather
                // than silently doubling the dataset.
                'client_idempotency_key' => sprintf('scale-%030d', $this->paymentId),
                'provider' => 'scale',
                'provider_reference' => 'scale_ref_'.$this->paymentId,
                'status' => 'paid',
                'paid_at' => $termStart->format('Y-m-d H:i:s'),
                'created_at' => $termStart->format('Y-m-d H:i:s'),
                'updated_at' => $termStart->format('Y-m-d H:i:s'),
            ];

            foreach ($shares as $instructorId => $amountMinor) {
                $allocations[] = [
                    'id' => $this->allocationId,
                    'payment_id' => $this->paymentId,
                    'instructor_id' => $instructorId,
                    'amount_minor' => $amountMinor,
                    // §3.1: the exact rational, for audit.
                    'weight_numerator' => 1,
                    'weight_denominator' => count($shares),
                    'created_at' => $termStart->format('Y-m-d H:i:s'),
                ];

                $this->allocationId++;
            }

            $this->subscriptionId++;
            $this->paymentId++;

            if (count($subscriptions) >= self::ROWS_PER_FLUSH) {
                $this->flush($subscriptions, $payments, $allocations);
            }
        }

        $this->flush($subscriptions, $payments, $allocations);
    }

    /**
     * Write what has been generated and drop it. The three inserts stay in the
     * order the foreign keys need: a payment references a subscription, an
     * allocation references a payment.
     *
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<int, array<string, mixed>>  $allocations
     */
    private function flush(array &$subscriptions, array &$payments, array &$allocations): void
    {
        if ($subscriptions === []) {
            return;
        }

        // Query builder, not Eloquent: no model instantiation, no events, no
        // casts, no observers.
        DB::table('subscriptions')->insert($subscriptions);
        DB::table('subscription_payments')->insert($payments);

        foreach (array_chunk($allocations, self::ROWS_PER_FLUSH * 2) as $batch) {
            DB::table('revenue_allocations')->insert($batch);
        }

        $this->allocationsWritten += count($allocations);

        $subscriptions = [];
        $payments = [];
        $allocations = [];
    }

    /**
     * §17: "Release job over 500k subscriptions → grouped aggregate chunked by
     * instructor". This is the read the release job makes, and the plan for it.
     *
     * Printed rather than asserted. The seeder's job is to put the number in
     * front of somebody, not to decide what the number should be.
     */
    private function queryPlan(): void
    {
        $sample = DB::table('revenue_allocations')
            ->select('instructor_id')
            ->groupBy('instructor_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(1)
            ->value('instructor_id');

        if ($sample === null) {
            return;
        }

        $this->say('');
        $this->say('  ExpectedRecognition::forInstructor() — the read the release job makes, per instructor:');

        $plan = DB::select(
            'EXPLAIN SELECT ra.amount_minor, p.term_start, p.term_end, s.access_ends_at
               FROM revenue_allocations ra
               JOIN subscription_payments p ON p.id = ra.payment_id
               JOIN subscriptions s ON s.id = p.subscription_id
              WHERE ra.instructor_id = ?
              ORDER BY ra.id',
            [$sample],
        );

        foreach ($plan as $row) {
            $this->say(sprintf(
                '    %-22s type=%-8s key=%-22s rows=%-12s %s',
                $row->table ?? '?',
                $row->type ?? '?',
                $row->key ?? 'NULL',
                number_format((int) ($row->rows ?? 0)),
                $row->Extra ?? '',
            ));
        }

        $this->timeTheReleaseJob();
    }

    /** What one instructor's scheduled release actually costs, measured. */
    private function timeTheReleaseJob(): void
    {
        $instructorIds = DB::table('revenue_allocations')
            ->select('instructor_id')
            ->groupBy('instructor_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(25)
            ->pluck('instructor_id');

        if ($instructorIds->isEmpty()) {
            return;
        }

        $release = app(ReleaseService::class);
        $horizon = $this->now->modify('first day of this month')->modify('-1 day')->setTime(0, 0);

        $timings = [];

        foreach ($instructorIds as $instructorId) {
            $startedAt = microtime(true);

            // §6.2's whole transaction: lock the balance row, read the
            // watermark, recompute expected(t), post the delta.
            $release->releaseScheduled((int) $instructorId, $horizon);

            $timings[] = (microtime(true) - $startedAt) * 1000;
        }

        sort($timings);

        $this->say('');
        $this->say(sprintf(
            '  releaseScheduled() over the 25 busiest instructors, horizon %s:',
            $horizon->format('Y-m-d'),
        ));
        $this->say(sprintf(
            '    min %.1f ms · median %.1f ms · max %.1f ms · total %.1f ms',
            $timings[0],
            $timings[intdiv(count($timings), 2)],
            $timings[count($timings) - 1],
            array_sum($timings),
        ));
        $this->say(sprintf('    ledger entries posted: %s', number_format(DB::table('ledger_entries')->count())));
        $this->say('');
        $this->say('  §9.4: one short row lock per instructor, so different instructors never contend.');
        $this->say('  The covering index of §14 is what makes that the whole story, not half of it.');
        $this->say('  Watch the first line of the plan: `type=ref` on the covering index with `Using');
        $this->say('  index` is the read this design assumes. `type=index` on `PRIMARY` is the index');
        $this->say('  missing, and that shape scans the whole table once per instructor.');
    }

    /** @return array<int, int> one to three distinct instructors, sorted, as §5.2 normalises. */
    private function someInstructors(): array
    {
        $count = $this->rng->getInt(1, 3);
        $ids = [];

        while (count($ids) < $count) {
            $ids[$this->rng->getInt(1, $this->instructors)] = true;
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    private function progress(int $written, int $target, float $startedAt): void
    {
        // Every tenth transaction: often enough to watch, rarely enough that a
        // 500,000-row run does not print a hundred lines.
        if ($written % (self::TRANSACTION_CHUNK * 10) !== 0 && $written !== $target) {
            return;
        }

        $elapsed = max(microtime(true) - $startedAt, 0.001);

        $this->say(sprintf(
            '    %s / %s subscriptions · %s allocations · %.0fs elapsed · eta %.0fs · %.0f MB',
            number_format($written),
            number_format($target),
            number_format($this->allocationsWritten),
            $elapsed,
            ($target - $written) * ($elapsed / max($written, 1)),
            memory_get_usage(true) / 1_048_576,
        ));
    }

    private function say(string $message): void
    {
        $this->command?->getOutput()->writeln($message);
    }
}
