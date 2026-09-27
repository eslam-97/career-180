# Instructor Revenue Ledger

Laravel 11 implementation of the Career 180 Instructor Revenue Ledger challenge.

Students pay for a subscription up front. That money is shared between instructors and the
platform, earned gradually across the term, and paid out on a schedule through an unreliable
payment provider. The whole design aims at one thing: **pay each instructor once, only what
they are actually owed — and when the provider leaves it uncertain, hold the money rather
than guess.**

| Document | What's in it |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Every decision, the reasoning, the invariants, known limits |
| [`docs/AI_USAGE.md`](docs/AI_USAGE.md) | How AI was used, what I rejected, what is mine |

Senior bonus (a student changing plan mid-term) is answered in `ARCHITECTURE.md` §18.

---

## Stack

Laravel 11 · PHP 8.4 · MySQL 8.4 · Redis 7 · Filament 3 · Pest · Docker

**MySQL 8.0.16+ is required.** Older versions accept `CHECK` constraints and then silently
ignore them — the schema would look right and enforce nothing.

---

## Setup

```bash
# services first, migrations need the database up
docker compose up -d
docker compose exec mysql mysqladmin ping -uroot -psecret --wait

composer install
cp .env.example .env
php artisan key:generate

docker compose exec mysql mysql -uroot -psecret \
  -e "CREATE DATABASE IF NOT EXISTS revenue_ledger;
      CREATE DATABASE IF NOT EXISTS revenue_ledger_test;"

php artisan migrate:fresh --seed     # demo data, ~10s
php artisan make:filament-user
```

`.env.example` already points at Docker: MySQL on `3307`, Redis on `6380`.

The tests need a second connection, `mysql_b`, pointing at the same database. It is already in
`config/database.php` — the concurrency tests use two real connections and need it.

## Running it

```bash
php artisan serve          # Filament panel at /admin
php artisan queue:work     # payout attempts and polling
php artisan schedule:work  # release, sweepers, reconciliation
```

| Command | When | What it does |
|---|---|---|
| `release:run` | monthly | Recognise the period's earnings |
| `payouts:run` | monthly | Open a batch, claim entries, start attempts |
| `payouts:sweep-leases` | often | Attempt stuck in `sending` → `unknown` |
| `payouts:sweep-stranded` | often | Recover a payout whose attempt dispatch was lost |
| `payouts:poll-open` | hourly | Re-ask about `unknown` and `unresolved` attempts |
| `payments:allocate-missing` | 5 min | Recover a lost allocation job |
| `reconcile:balances` | nightly | Four drift checks; fails loudly, never repairs |

---

## Tests

```bash
php artisan test                    # everything, concurrency included
php artisan test tests/Invariants   # the 33 invariants
php artisan test tests/Concurrency  # two-connection tests
./vendor/bin/pint --test
```

**154 passed, 18,384 assertions.** Screenshot: `docs/screenshots/tests-passing.png`

**33 named invariants** (`ARCHITECTURE.md` §15) are the definition of done. They were written
as failing tests before any code existed, and each one names a way money could go wrong.

**Rules alone are not enough.** A release calculation that quietly halved every amount would
pass "never negative", "never above the cap", and the nightly reconciliation — because the
reconciliation uses the same calculation. So the arithmetic is also checked against
hand-written examples and a **reference implementation**: a deliberately slow day-by-day
version that shares no code with the real one. Thousands of random inputs, both must agree.

**Concurrency is tested with real concurrency.** Running a job twice in a row proves almost
nothing — the second run sees the first one's work and correctly does nothing. The tests in
`tests/Concurrency/` drive two database connections in a set order and check the second one
blocks or refuses.

**The critical guards were mutation-tested.** Deleting a `FOR UPDATE`, un-gating a balance
update, or making the fake provider throw before recording a transfer each turns a specific
invariant red. Two tests were found to be vacuous this way and rewritten — one passed with
the lock deleted, because any write to a row locks it anyway.

---

## Failure scenarios

Each command sets up a scenario, runs it, prints the ledger before and after, and checks the
result. They exit non-zero on failure, so they are smoke tests too. All under three seconds.

```bash
php artisan demo:double-run           # payouts run twice → one payout
php artisan demo:concurrent           # two workers, two connections → one payout
php artisan demo:crash-retry          # worker dies mid-send → we ask, we don't resend
php artisan demo:timeout              # provider times out after succeeding → no second transfer
php artisan demo:late-success         # answer arrives after we gave up → recorded, alerted
php artisan demo:refund-after-payout  # refund lands after payout → nets off by itself
php artisan demo:rounding             # 5,000 random splits → every one sums exactly
```

They step through the jobs one at a time so the in-between states are visible. On a sync queue
the whole chain fires at once and the states these scenarios are *about* never appear. Nothing
is skipped or faked — the normal automatic path is covered by the test suite.

They write to a demo range (instructors from 900001), so use a demo database.

## Scale

```bash
php artisan db:seed --class=ScaleSeeder                            # 500,000 subs, ~60s
SCALE_SUBSCRIPTIONS=20000 php artisan db:seed --class=ScaleSeeder  # quick pass
```

Query builder only, no Eloquent events, batched inserts. It prints the release job's `EXPLAIN`
and times recognition over the busiest instructors. Invariant 1 holds across all 500,000.

---

## Assumptions made

The brief left several rules open on purpose. These are my calls; each is argued in full in
`ARCHITECTURE.md`.

### How a payment is split

Equally between the distinct instructors the subscription covers. The rule sits behind a
`RevenueAllocator` interface, so it can be swapped without touching anything else.

> **In a real LMS I would prefer a business-driven rule — enrollment-based,
> consumption-based, or a mix of the two.** I would not keep the allocation-at-payment
> constraint either: I would choose the allocation *timing* from the business rule, rather
> than pick a business rule that fits a timing decided in advance.
>
> That constraint — the weights must be known on the day the student pays — is not a law. It
> is a consequence of choosing to allocate once, at payment. A consumption split can't be
> worked out in January, because March's watch time doesn't exist yet. But that only rules
> out allocating *at payment*. Move the split to **period close** and it works: each month,
> work out how much revenue that month recognised across active subscriptions, then divide
> that pool by that month's consumption.
>
> Notably, this costs no extra delay. This design already pays in arrears — an instructor is
> paid for March during April's batch — so a consumption split has the same cadence. It would
> also make the platform-residual proof (§8) simpler, not harder: you floor one period pool
> and split that exact integer, instead of proving two separate roundings agree.
>
> What it does cost is real, and is why it is out of scope here:
>
> - **Engagement data joins the money path.** Watch events stop being analytics and become
>   accounting — they need dedup, idempotency and their own reconciliation.
> - **A rule is needed for a month where the student watched nothing** — platform keeps it,
>   rolls it forward, or falls back to an equal split.
> - **Weights freeze per period instead of per payment**, so a later refund reverses against
>   the split that actually applied.
> - **Instructors can dispute the split, not just the amount**, so the evidence has to be
>   shown.
>
> Everything after recognition — the ledger, the payout claim, the provider handling — is
> untouched by any of this, because it only ever reads frozen amounts. That is what the
> `RevenueAllocator` interface is for.

### When money counts as earned

Not at payment. The instructor's full share is fixed once, then released gradually across the
term. A student leaving mid-term therefore costs the instructor only the part nobody paid for,
with **no clawback in the normal case** — which matters, because leaving mid-term is how
subscriptions usually end, not an edge case.

### What a prorata refund means

The unused part of the term. Ending access on the refund date stands for what the student is
still owed. A refund dated outside the term is clamped, not rejected: the money has already
moved, so refusing to record it would just leave a real refund out of the books.

### Cancelling is not the same as losing access

Switching off auto-renew does not stop earnings — the student keeps watching to the end of the
term. Only `access_ends_at` caps earnings, and it only ever moves *earlier*. Otherwise a later
refund could re-release money the student already got back in full.

### Goodwill refunds

Recorded, access unchanged, absorbed entirely by the platform. Sharing the cost with
instructors is not implemented (§19).

### Rounding

The platform takes whatever is left over. The instructors' pool is rounded down and the
platform gets the remainder — at allocation *and* when money is released, so the two halves
can never disagree about who absorbs a piastre. Between instructors, largest-remainder with a
fixed tie-break means nobody is ever off by more than one piastre.

### "Outstanding"

Means recognised, posted, and unclaimed. It is the number a payout uses and the only
authoritative one. The economically-earned-but-unposted amount is shown separately as
*pending* and is never payable.

### Other

- **Which instructors share a payment** — no courses or enrollments table is in scope, so the
  set is fixed onto the payment when it is created. That also means a lost allocation job can
  be recovered from the database alone.
- **Time** — everything UTC. Terms are `[starts_at, ends_at)`, end-exclusive, length derived
  from the dates, so leap years and 31-day months need no special case.
- **Currency** — one settlement currency.
- **The provider** — assumed to honour idempotency keys, with status polling as the fallback
  for when it does not.

---

## Known limitations

Deliberate. Full list in `ARCHITECTURE.md` §19; the ones worth naming here:

- **A confirmed transfer arriving after a human ruled an attempt failed cannot be recovered
  automatically.** It is detected and alerted. Closing it fully would mean holding an
  instructor's money indefinitely every time a provider goes quiet, which is worse.
- **Level-two reconciliation is not independent of the release job** — both use the same
  calculation, so it cannot catch wrong arithmetic. The reference implementation in the tests
  covers that gap.
- **The release job re-sums an instructor's whole history every run.** That is what makes it
  self-healing, and an index keeps it fast today, but the cost grows over time. Rolling
  finished allocations into a per-instructor total is the next step.
- No tax withholding, no invoicing, no minimum payout threshold, one currency.
- The ledger is unbounded; monthly partitioning is the obvious next move.