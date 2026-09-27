# Instructor Revenue Ledger

A money system. Correctness beats cleverness, features, and speed of delivery.

## Read before touching the money path

`docs/ARCHITECTURE.md` is **binding, not advisory**. Every decision in it was made
deliberately, and most have a named failure case attached. If the code cannot match the
doc, stop and explain why rather than deviating.


## Hard rules — violations are bugs, not style

1. **Never weaken a test to make it pass.** Filling in a `->todo()` with the assertions its
   comment describes is expected. Adding a required fixture value after a schema change is
   fine. But once a test has passed, its assertions never change, and a test is never
   skipped or deleted. If a test looks wrong, stop and ask.
2. **Never change a decision in ARCHITECTURE.md.** Not to simplify, not to make a test
   easier, not because a constraint looks redundant.
3. **All money is integer minor units (piastres).** No floats. No `round()`. No decimal
   division. Anywhere. Rates are basis points (`SMALLINT`), never decimals.
4. **Every transaction that reads or writes an instructor's ledger entries, payouts or
   attempts locks `instructor_balances` FOR UPDATE first** (§9.4). This looks redundant in
   single-threaded tests. It is not. Do not remove it. Allocation is the one exception: it
   takes no lock, and creates any missing balance row with insertOrIgnore instead.
5. **Never put a side effect inside `DB::transaction()`** — no dispatch, no HTTP call, no
   event. The closure retries on deadlock and would fire them twice. Dispatch with
   `->afterCommit()`.
6. **Do not add anything not in the doc.** No API routes, no auth beyond the Filament
   panel, no notifications, no extra models, no caching layer.
7. **Largest remainder is applied to magnitudes, never signed values.**
8. Every file in app/Domain/ starts with declare(strict_types=1). Money is always `int`.

## Definition of done for any slice

- `php artisan test` fully green
- `./vendor/bin/pint` clean
- Report which invariant numbers now pass, by number

## Commands

```bash
php artisan test
php artisan test --filter=Invariant
php artisan test tests/Concurrency
./vendor/bin/pint
```

## Style

- Service/Action classes in `app/Domain/*`. Thin models. No logic in controllers.
- Pest, not PHPUnit class syntax.
- Every non-obvious decision gets a one-line comment naming the `ARCHITECTURE.md` section
  it comes from, e.g. `// §10.4: rollback rather than create a non-positive payout`.