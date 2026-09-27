<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use App\Domain\Provider\FakeProviderTransfers;
use App\Domain\Provider\PaymentProvider;
use App\Domain\Provider\Scenario;
use App\Domain\Provider\ScriptedProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The shape every §11.4 scenario demo shares: set the scenario up, run it one
 * visible step at a time, and print the ledger before and after.
 *
 * Three decisions apply to all seven, and each is here rather than repeated:
 *
 * **The queue is a null queue.** Every job in this system dispatches its
 * successor — a claim dispatches an attempt, an attempt dispatches a poll, a
 * poll dispatches the next poll. Under `sync` the whole cascade would run
 * inside the first call and a viewer would see only the end state, which is the
 * one state these scenarios are not about. So queued work is discarded and each
 * step is run explicitly by `runJob()`, in the order the queue would have run
 * it. Nothing is skipped and nothing is faked; the steps are just visible.
 *
 * **The provider is scripted.** §16.3 gives the demo the seeded RandomProvider,
 * which is right for a walk through the happy path — it is what `DemoSeeder`'s
 * recording uses. A demo of one *named* failure needs that named outcome on the
 * wire, so these commands script it per call. The implementation underneath is
 * identical either way: `FakeProvider` holds the durable map, the replay branch
 * and the record-then-throw ordering, and the subclasses choose only which
 * scenario a call gets.
 *
 * **Every claim is checked on screen.** A demo that asserts nothing is a
 * screenshot. Each `check()` is a real assertion against the database, the
 * command exits non-zero if one fails, and the bucket identity of §1.1 plus
 * §12's level-one agreement are re-checked at every snapshot.
 */
abstract class DemoCommand extends Command
{
    protected DemoWorld $world;

    private int $checks = 0;

    private int $failures = 0;

    private bool $clockMoved = false;

    /** The §11.4 row this demo is the worked example of. */
    abstract protected function matrixRow(): string;

    /** The scenario itself. Everything it needs is already set up. */
    abstract protected function scenario(): void;

    public function handle(DemoWorld $world): int
    {
        $this->world = $world;

        // A null queue, so no dispatched job runs behind the narration. See the
        // class docblock: the steps are run explicitly instead, in queue order.
        config([
            'queue.connections.demo_null' => ['driver' => 'null'],
            'queue.default' => 'demo_null',
        ]);

        $this->banner();

        try {
            $this->scenario();
        } finally {
            // §3.3: the app's clock is the one that can be frozen, so a demo
            // that moved it puts it back rather than leaving a later command in
            // a different year.
            $this->restoreClock();
        }

        return $this->verdict();
    }

    // ---------------------------------------------------------------- running

    /**
     * Run one queued job by hand, exactly as a worker would: resolved from the
     * container, `handle()` called with its dependencies injected.
     */
    protected function runJob(object $job): void
    {
        $this->laravel->call([$job, 'handle']);
    }

    /**
     * §16.3: outcomes per call, in order. The last scripted scenario is also
     * the fallback, so a demo scripts what it means to demonstrate and not the
     * retries around it.
     */
    protected function scripted(Scenario ...$scenarios): ScriptedProvider
    {
        // A NEW instance each time, never the container's: the call counters
        // this demo asserts on (`sendCalls()`, `statusKeys()`) are per instance,
        // and a scenario that scripts twice must not inherit the first script's
        // tally. The durable transfer map is a table, so the provider still
        // remembers every transfer it has ever made (§16.3).
        $provider = new ScriptedProvider($this->laravel->make(FakeProviderTransfers::class));

        if ($scenarios !== []) {
            $provider->script(...$scenarios);
        }

        // The jobs resolve PaymentProvider from the container at handle() time,
        // so binding the instance here is what puts this script on the wire.
        $this->laravel->instance(PaymentProvider::class, $provider);
        $this->laravel->instance(ScriptedProvider::class, $provider);

        return $provider;
    }

    /**
     * §11.1: the lease is 300 seconds, and a demo has 30. The clock moves
     * rather than the data: rewriting `lease_expires_at` into the past would
     * demonstrate a sweeper against a row no worker ever wrote.
     */
    protected function advanceClock(int $seconds, string $why): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
        $this->clockMoved = true;

        $this->note(sprintf('demo clock advanced %d seconds — %s', $seconds, $why));
    }

    protected function restoreClock(): void
    {
        if ($this->clockMoved) {
            Carbon::setTestNow();
            $this->clockMoved = false;
        }
    }

    // --------------------------------------------------------------- printing

    private function banner(): void
    {
        $title = strtoupper((string) $this->getName());

        $this->newLine();
        $this->line('<fg=cyan>'.str_repeat('═', 78).'</>');
        $this->line('<fg=cyan;options=bold>  '.$title.'</>');
        $this->line('<fg=gray>  §11.4  '.$this->matrixRow().'</>');
        $this->line('<fg=cyan>'.str_repeat('═', 78).'</>');
    }

    /** One numbered step of the scenario. */
    protected function step(string $text): void
    {
        $this->newLine();
        $this->line('<fg=yellow;options=bold>▸ '.$text.'</>');
    }

    /** A parenthetical: why a step is done this way, or what to watch. */
    protected function note(string $text): void
    {
        $this->line('<fg=gray>    '.$text.'</>');
    }

    /** A line of plain narration under a step. */
    protected function say(string $text): void
    {
        $this->line('    '.$text);
    }

    /**
     * One assertion, against the database, printed. The command's exit code is
     * the conjunction of every one of them.
     */
    protected function check(string $claim, bool $passed): void
    {
        $this->checks++;

        if ($passed) {
            $this->line('    <fg=green>✓</> '.$claim);

            return;
        }

        $this->failures++;
        $this->line('    <fg=red;options=bold>✗ '.$claim.'</>');
    }

    /** `check()` for a value, printing what was expected and what was found. */
    protected function checkEquals(string $claim, int|string $expected, int|string $actual): void
    {
        $this->check(
            $expected === $actual ? $claim : $claim." — expected {$expected}, found {$actual}",
            $expected === $actual,
        );
    }

    /**
     * The four buckets, cached beside ledger-derived, with the §1.1 identity
     * and §12's level-one agreement checked as they are printed.
     *
     * @param  array{recognized: int, available: int, reserved: int, paid: int}|null  $before
     * @return array{recognized: int, available: int, reserved: int, paid: int}
     */
    protected function buckets(int $instructorId, string $label, ?array $before = null): array
    {
        $cached = DemoLedger::cached($instructorId);
        $derived = DemoLedger::derived($instructorId);

        $headers = $before === null
            ? ['bucket', 'balance cache', 'from the ledger']
            : ['bucket', 'before', 'after', 'from the ledger'];

        $rows = [];

        foreach (['recognized', 'available', 'reserved', 'paid'] as $bucket) {
            $rows[] = $before === null
                ? [$bucket, DemoLedger::money($cached[$bucket]), DemoLedger::money($derived[$bucket])]
                : [
                    $bucket,
                    DemoLedger::money($before[$bucket]),
                    DemoLedger::money($cached[$bucket]),
                    DemoLedger::money($derived[$bucket]),
                ];
        }

        $this->newLine();
        $this->line('    <options=bold>'.$label.'  (instructor '.$instructorId.')</>');
        $this->table($headers, $rows, 'box');

        // Invariant 3: recognized == available + reserved + paid, over ALL
        // entry types (§1.1).
        $this->check(
            'invariant 3  recognized == available + reserved + paid',
            $cached['recognized'] === $cached['available'] + $cached['reserved'] + $cached['paid'],
        );

        // §12 level one: the cache is maintained by the transactions that move
        // the ledger (§10.5), so it must agree with the ledger it caches.
        $this->check('§12 level one  the cache agrees with the ledger', $cached === $derived);

        // Invariant 4: no entry belongs to a terminally failed payout, which
        // would put it in none of the three buckets.
        $this->check('invariant 4  every entry is in exactly one bucket', DemoLedger::orphanedEntries($instructorId) === 0);

        return $cached;
    }

    protected function entries(int $instructorId, string $label = 'Ledger entries'): void
    {
        $this->newLine();
        $this->line('    <options=bold>'.$label.'</>');
        $this->table(
            ['id', 'type', 'amount', 'effective_at', 'source_ref', 'payout'],
            DemoLedger::entryRows($instructorId),
            'box',
        );
    }

    protected function payouts(int $instructorId, string $label = 'Payouts'): void
    {
        $this->newLine();
        $this->line('    <options=bold>'.$label.'</>');
        $this->table(
            ['id', 'batch', 'amount', 'status', 'attempts', 'settled_at'],
            DemoLedger::payoutRows($instructorId),
            'box',
        );
    }

    protected function attempts(int $payoutId, string $label = 'Payout attempts'): void
    {
        $this->newLine();
        $this->line('    <options=bold>'.$label.'</>');
        $this->table(
            ['id', 'no', 'status', 'idempotency_key', 'polls', 'provider_ref', 'evidence'],
            DemoLedger::attemptRows($payoutId),
            'box',
        );
    }

    protected function alerts(string $subjectType, int $subjectId, string $label = 'Reconciliation alerts'): void
    {
        $this->newLine();
        $this->line('    <options=bold>'.$label.'</>');
        $this->table(['id', 'kind', 'subject', 'state'], DemoLedger::alertRows($subjectType, $subjectId), 'box');
    }

    /** The one-line answer the §11.4 matrix gives in its "money outcome" column. */
    protected function moneyOutcome(string $outcome): void
    {
        $this->newLine();
        $this->line('<fg=magenta;options=bold>  MONEY OUTCOME  '.$outcome.'</>');
    }

    private function verdict(): int
    {
        $this->newLine();
        $this->line('<fg=cyan>'.str_repeat('─', 78).'</>');

        if ($this->failures === 0) {
            $this->line(sprintf('<fg=green;options=bold>  %d checks passed.</>', $this->checks));
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '<fg=red;options=bold>  %d of %d checks FAILED.</>',
            $this->failures,
            $this->checks,
        ));
        $this->newLine();

        return self::FAILURE;
    }
}
