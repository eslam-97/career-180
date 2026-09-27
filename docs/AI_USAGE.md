# AI Usage

AI was used heavily on this task. Not to think for me — to argue with.

## The workflow

I worked in two phases, and the split matters more than any single prompt.

**Phase 1 — design, before any code.** I used Claude as a design partner over about eight
rounds of review. Each round: it drafted or defended part of the architecture, I read it
looking for contradictions, and I sent back the holes I found. I iterated until the major
contradictions and edge cases were resolved, then treated that architecture as the fixed
implementation target. `ARCHITECTURE.md` is the output of those rounds, not a document
generated in one pass.

**Phase 2 — build, against a fixed target.** I turned the invariants in `ARCHITECTURE.md`
§15 into failing tests **before any implementation existed**, and committed them first.
Then I built in slices, each one a scoped prompt: which sections of the doc apply, which
invariant numbers must go green, which directories may be touched. A prose spec lets an agent
drift. A failing test does not.

Two rules held the whole build together, written into the repo's `CLAUDE.md`:

```
Never weaken a test to make it pass. Once a test passes, its assertions never change.
Never change a decision in ARCHITECTURE.md. If the code cannot match it, stop and explain.
```

I checked the second one mechanically after every slice — `git log -p -- tests/Invariants/`,
looking for an assertion edited in a test that was already green. There are none.

## What was generated vs mine

| Part | Who |
|---|---|
| Architecture, all decisions and trade-offs | Mine, argued out over eight review rounds |
| The 33 invariants | Mine — they are the spec |
| Test skeletons (what each test must assert) | Mine |
| Test bodies, implementation code, migrations | Largely AI-generated against the above |
| Design issues I identified, and the decisions taken | Mine (see below) |
| Review of every diff on the money path | Mine |

So: the code is mostly AI-written. The specification it was written against, and the standard
it had to meet, are not.

## What I rejected

This is the part I would most like you to check against the code.

The design AI produced was good, and wrong in specific, repeatable ways. Several of these were
contradictions it introduced and then carried across multiple rounds, defending them, until I
found them. Ten worth naming:

1. **A `carried` payout state for negative balances.** It broke the accounting identity the
   same document asserted two sections earlier — entries in a terminal payout are in no
   bucket. I cut the state entirely. A non-positive claim now rolls the whole transaction
   back and the debt simply stays as unclaimed negative ledger rows, which the next batch
   picks up. Fewer states, not more (§10.4).

2. **The release job was not concurrency-safe.** Two runs for different months, started
   before either commits, each read "nothing posted yet" from its own snapshot and both
   posted — inventing money. No unique constraint catches it, because the months differ. I
   made `instructor_balances` the serialisation point every money transaction locks first
   (§9.4, invariant 12).

3. **Stamping ledger rows with `payout_id` made retries impossible**, and directly
   contradicted "a failed payment gets a new attempt". I separated a payout (a claim on
   money, one per instructor per batch) from an attempt (one try at sending it) (§10.2).

4. **The release formula ignored refunds.** It would have kept paying out money already
   refunded to the student, and a negative ledger entry does not fix that — the job just
   re-releases it next month. The fix is a clamp at source, which also removed a separate
   "cap" expression that could drift out of step (§6).

5. **A signed ledger column declared `BIGINT UNSIGNED`.** Straight bug, carried for three
   rounds after refunds made negative rows necessary.

6. **A payout could be built with a negative amount.** A claim can pick up more refund than
   earnings; the provider would reject it, or worse, treat it as a reversal (§10.4).

7. **The batch cutoff was described in the doc but never reached the claim query.** Replaying
   a batch would have produced a different answer than the original run. Fixed with both a
   frozen `max_entry_id` and `effective_at` in the predicate (§10.1).

8. **A payout could be marked failed while an attempt was still live.** The money comes back
   to `available`, the next batch pays it, and then the original answer arrives: succeeded.
   Paid twice, unrecoverable. Now failure requires no attempt in `sending`, `unknown`, or
   `succeeded` (§10.6).

9. **The attempt gate leaned on a unique constraint instead of doing its own job**, so a
   losing worker still burned an attempt number. The check has to come first; the constraint
   is the net underneath (§10.2).

10. **The claim job never started the attempt**, so the recovery sweeper had quietly become
    the only thing that sent a payment — the §10.7 recovery path doing the normal path's job.

I also overruled it during the build: it recommended no foreign keys (arguing unique
constraints carry referential integrity — they don't, they stop duplicates, not orphans), a
signed type for columns that can never be negative, no `created_at` anywhere, and throwing an
exception on goodwill refunds rather than recording them.

And twice it caught its own vacuous tests, which is worth saying plainly: a lock test that
passed with the lock deleted, because any write to a row locks it anyway. That is the kind of
test that makes a suite look green and prove nothing.

## Decisions I made myself

Not topics — decisions:

- **Allocate once at payment, release gradually** — so a mid-term cancellation takes nothing
  back from an instructor who did nothing wrong. The AI's first recommendation was to
  recognise everything immediately and claw back later; I rejected it because clawback would
  be the *normal* path, not an edge case.
- **Allocation strategy** — for this challenge I used an equal split, because the brief
  provides no enrollment or consumption data. In a real LMS I would prefer an
  enrollment-based, consumption-based, or hybrid rule, and I would keep the allocator
  strategy swappable so the business rule can evolve without changing recognition or payout
  logic. The constraint that matters is that whatever rule is chosen for a payment must
  produce frozen allocations for that payment (§5.2, §5.3).
- **The platform is the residual claimant** — it takes the rounding remainder at allocation
  and at release, so the two halves of the system can never disagree about a piastre (§5.4,
  §8).
- **Ledger is truth, balance is a cache, maintained transactionally** — reconciliation
  detects drift and refuses to repair it. Silent self-repair hides the bug that caused it.
- **"No answer" is not "no"** — a timeout gets its own state, the money stays held, and the
  only way out is asking the provider with the same key (§11).
- **Four separate timestamps with four separate jobs** — posting period, recognition horizon,
  business-effective date, and row creation. Collapsing any two of them causes a real bug.
- **A test oracle, not just rules** — a calculation that is consistently wrong passes every
  constraint check *and* the nightly reconciliation, because they share the calculation
  (§16.1).

## Trade-offs I chose on purpose

- **One ledger row per instructor per month, not per subscription.** The ledger can answer
  "what did instructor 7 earn in March", not "which student paid for it". At the stated scale
  that is thousands of rows instead of tens of millions. Per-payment detail still exists in
  the allocation table.
- **Self-healing over exact period labels.** Each release run recomputes the total and posts
  the gap, so a missed month fixes itself with no backfill. The cost is that a row's period
  means "when it was posted", not "what it covers" — so the coverage is recorded explicitly
  (§6.3).
- **A window I could not close, named rather than hidden.** If a human rules an unresolved
  payment failed and a success turns up later, the money cannot be recovered automatically.
  Closing it fully would mean holding an instructor's money indefinitely whenever a provider
  goes quiet. I judged that worse, wrote down why, and made the case raise an alert (§11.3).
- **Smaller scope, deeper.** No tax, no invoicing, no multi-currency, no minimum payout
  threshold. The brief says a smaller solution with strong reasoning beats a larger one.

## What I think makes this different

Most of the money-correctness bugs in this system were not in the code. They were in the
design, they were subtle, and an AI both introduced them and defended them when asked. What
I did was build a process that surfaces them: argue the design until it stops producing new
contradictions, turn the result into 33 invariants, write those as failing tests first, and
then check that the guards actually guard by deliberately breaking them and watching the
right test go red.

The parts I would point at as evidence: `unknown` as a first-class state; the payout/attempt
split; the clamp that means a refund takes nothing back in the normal case; one serialisation
point per instructor; the reference implementation that exists solely to disagree with the
production one; and the limitations section, which names the things this design does *not*
solve instead of quietly leaving them out.