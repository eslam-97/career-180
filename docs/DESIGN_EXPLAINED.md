# The design, explained in plain words

This file explains every choice we made: the problem, what we did, a small example, and
what would break without it. Each one also gives you **the real name** of the pattern, so
you can look it up or say it out loud.

`ARCHITECTURE.md` is the exact technical version. This file is the explanation. You do not
need a background in system design to read it.

---

## The whole thing in one paragraph

A student pays for a subscription up front. That money is shared between the instructors
and the platform. Instructors do not get their share straight away. They earn it bit by
bit as the months pass, because a student might quit and ask for their money back. Every
month we pay each instructor what they have earned so far. Paying them means asking an
outside company to move money, and that company can fail, be slow, or go quiet. Most of
this design is about one thing: never pay someone twice, and never pay out money we will
have to ask for back.

## The five things that matter most

1. **Money is stored as whole numbers.** No decimals anywhere.
2. **We never change a balance. We add a line to a list and add the list up.**
3. **Instructors earn slowly over time**, not all at once on day one.
4. **"No answer" is not "no".** Treating a timeout as a failure is how people get paid twice.
5. **The database enforces the rules, not the code.** Code can be forgotten. A database rule
   cannot.

---

# Part 1 — How we store money

## 1. Whole numbers only

**Also called:** minor units, integer money, fixed-point arithmetic. The rule "never use
floating point for money".

**The problem.** A computer cannot store `0.1` exactly, the same way you cannot write one
third as `0.333`. The tiny errors add up. In money, that is cash that appears or vanishes.

**What we do.** Store everything in **piastres** (1 EGP = 100 piastres). 350.00 EGP is
stored as `35000`.

**Example.**

```
350.00 EGP  →  35000
35000 ÷ 3   →  11666, with 2 left over    ← exact, nothing lost
```

**Without it.** Split 350.00 three ways with decimals and you get 116.666… each. Add them
back and you get 349.999…, not 350. Someone has to eat the difference and nobody knows who.

---

## 2. Percentages as whole numbers too

**Also called:** basis points (bps).

**The problem.** The platform takes 20%. If we store that as `0.20` we have brought decimals
right back.

**What we do.** Store the rate in basis points — hundredths of a percent, as a whole number.

```
20%   →  2000
7.5%  →   750
100%  → 10000
```

**Example.** `35000 × 8000 ÷ 10000 = 28000`. Whole numbers all the way.

**Without it.** One decimal sneaks into the middle of the money path and you are back to
fractions of a piastre showing up and disappearing.

---

## 3. Some numbers may go negative, most may not

**Also called:** type-level invariants, domain constraints. "Let the column type carry the
rule."

**The problem.** A payment is never negative. But a refund line *is* negative.

**What we do.** Pick the column type case by case, so the database itself blocks impossible
values.

| Thing | Can it go negative? |
|---|---|
| A student's payment | No |
| An instructor's share of a payment | No |
| A line in the ledger | **Yes** |
| A payment sent to an instructor | No |
| An instructor's balance | **Yes** — they can owe us |

**Example.** An instructor earned 100. A refund takes back 150. Their balance is `−50`. That
is real and correct: they owe 50, and it comes out of next month.

**Without it.** If the balance could not go negative, you would need a second "debt" table
and a second system to net it off. Two systems that can disagree.

---

# Part 2 — Money coming in

## 4. Two keys on every payment

**Also called:** idempotency key (ours) and external reference deduplication (theirs).
Together: exactly-once processing.

**The problem.** We charge a card. Two different things go wrong, and each needs its own fix.

```
A.  We send the charge. No answer comes back.
    Did it happen? We have no reference to ask about.

B.  The payment company tells us "payment 998 worked" — twice,
    because their system retried the message.
```

**What we do.** Two IDs, one from each side.

- **Our key**, made before we send. Gives us something to ask about if we hear nothing.
- **Their reference**, made after. Marked unique, so the same message twice cannot make two
  payments.

**Example.**

```
we make key a1b2c3  →  send charge  →  silence            (problem A)
later: "what happened to a1b2c3?"  →  "worked, ref 998"

they send "998 worked"  →  saved
they send "998 worked"  →  blocked, 998 already exists    (problem B)
```

**Without it.** With only their reference, problem A leaves you nothing to ask about. You
either charge the student twice or lose the money.

---

## 5. Making the payment safe does not make the split safe

**Also called:** per-step idempotency. Each stage gets its own natural-key constraint.

**The problem.** After a payment lands, a background job splits it between instructors. That
job can crash and run again. The payment is protected. The split is not.

**What we do.** A second rule: one split line per payment per instructor. The database blocks
the repeat.

**Example.**

```
payment 501 → instructor 3 gets 9,334
job crashes right after saving, before it can mark itself done
job runs again → tries to save payment 501 / instructor 3
               → blocked. Nothing doubled.
```

**Without it.** A retried job doubles everyone's share and nothing notices.

**The bigger idea:** every step needs its own guard. Guarding step 1 does not guard step 2.

---

# Part 3 — Sharing out the money

## 6. How we split it

**Also called:** revenue sharing. The swappable rule is the strategy pattern.

**The problem.** One subscription unlocks many instructors' courses. Who gets what? The task
does not say on purpose.

**What we do.** Split it **equally** between the instructors whose courses are covered. The
rule sits behind one named piece of code, so it can be swapped later without touching
anything else.

**Example.** 28,000 piastres, three instructors, about 9,333 each.

**Why not split it by watch time?** A real platform would. We did not, and the reason is the
interesting part:

> If the split depends on what the student watched in March, **you cannot work it out when
> they pay in January.** March has not happened yet.

So watch-time would force a completely different design. Equal split lets us work everything
out once, at payment time. The two choices are linked. That link is the point.

---

## 7. Freeze the numbers forever

**Also called:** snapshotting, point-in-time data, immutable historical record.

**The problem.** Next year the platform changes its cut from 20% to 25%. If old payments are
worked out with today's rate, last year's books silently change.

**What we do.** Copy the rate and each instructor's amount onto the payment when it happens.
Never work them out again.

**Example.**

```
January payment:  rate 2000, instructor 3 gets 9,334    ← written down, locked
June:             company switches to 2500
January still says 2000 and 9,334. Always will.
```

**Without it.** A refund on the January payment would use June's rate, so it would not cancel
out the original. The books would never balance again — and you could not tell a bug from a
price change.

---

## 8. The platform takes whatever is left over

**Also called:** residual claimant. Rounding down is called flooring.

**The problem.** 20% of 10,001 is 2000.2. Someone has to win or lose that 0.2.

**What we do.** Work out the **instructors' pot** first and round it down. The platform gets
the rest.

```
pot  = round down (10001 × 8000 ÷ 10000) = 8,000
cut  = 10001 − 8000                      = 2,001
```

**Example.** The platform gets 1 piastre more than exactly 20%. Fine — it is their own money,
and it is the same rule every time.

**Why this way round.** The same rule is used later, when money is handed over bit by bit
(point 12). There too, the platform gets "whatever is left". If we rounded the other way here,
the two halves of the system would disagree about who takes the rounding, and the totals would
slowly drift apart.

**Without it.** Nobody owns the leftover. It either vanishes or gets counted twice.

---

## 9. Fair rounding between instructors

**Also called:** the largest remainder method, or Hamilton's method. It is an apportionment
problem — the same maths as sharing out seats in a parliament.

**The problem.** 28,000 does not divide by 3. Someone gets one piastre more.

**What we do.** Round everyone down, then hand out the spare piastres one at a time, starting
with whoever was closest to rounding up. If it is a tie, the lowest instructor ID wins, so the
answer is always the same.

**Example.**

```
28,000 ÷ 3 = 9,333.33 each
round down:  9,333 × 3 = 27,999     →  1 spare
all tied → lowest ID gets it

instructor  3 → 9,334
instructor  7 → 9,333
instructor 12 → 9,333
               ------
               28,000   ✓ exact
```

**The easier way we said no to.** Round everyone down and dump the whole leftover on the last
person. The total still comes out right, so a lazy test would pass. But with more instructors,
one person eats the entire error. Our way, nobody is ever off by more than one piastre.

---

# Part 4 — Earning money over time

## 10. Instructors earn slowly, not all at once

**Also called:** revenue recognition. The money not yet earned is deferred revenue. Spreading
it evenly is straight-line recognition.

**The problem.** A student pays 350 EGP for three months on day one. If we credit instructors
straight away and the student quits on day 45, we have to go and take money back from people
who did nothing wrong.

**What we do.** Write down the instructor's full share once, at payment. Then hand it over a
little each day as the term passes.

**Example.** Instructor 3's share is 9,334 over 90 days.

| Day | Handed over so far |
|---|---|
| 30 | 3,111 |
| 60 | 6,222 |
| 90 | 9,334 |

**Now the refund.** Student quits on day 45:

```
instructor 3 keeps       4,667    the half they earned
instructor 3 never gets  4,667    the half nobody paid for
taken back:                  0
```

**Without it.** Every mid-term quit creates a debt for an instructor. That is not a rare edge
case — it is how subscriptions normally end. This whole design exists so the normal case takes
nothing back.

---

## 11. One sum with a floor and a ceiling

**Also called:** clamping, or a bounded function.

**The problem.** Two things go wrong with "how much has been earned by now":

- Ask about a date *before* the subscription started → a negative number.
- Ask *after* the student lost access → it keeps growing, handing over refunded money.

**What we do.** Squeeze the "days gone by" number between 0 and "days of access they actually
got". Then one sum does everything.

```
days  = squeeze(days since start, between 0 and days of access)
share = round down (full share × days ÷ term length)
```

**Example.** 9,334 over 90 days, access stopped on day 45.

| Ask about | Raw days | Squeezed | Handed over |
|---|---|---|---|
| 5 days before the start | −5 | 0 | 0 |
| day 30 | 30 | 30 | 3,111 |
| day 60 | 60 | **45** | 4,667 |
| day 90 | 90 | **45** | 4,667 |

**Why this is nice.** The ceiling does two jobs at once. It stops refunded money going out,
**and** when nobody quits it makes the last day land on the exact full share, picking up every
piastre lost to rounding down along the way. One sum, no special cases.

**Without it.** You need a second "maximum" sum kept in step with the first one. The day
someone changes one and not the other, money leaks.

---

## 12. Again, the platform takes what is left

**Also called:** residual claimant (same idea as point 8, applied over time).

**What we do.** We never work out the platform's share directly. We work out what the whole
subscription has earned so far, take away what the instructors got, and the rest is the
platform's.

**Example** (day 45 of 90, access stopped):

```
whole subscription so far      17,500
instructors  4,667 + 4,666 + 4,666 = 13,999
platform     17,500 − 13,999      =  3,501
```

**Why.** It is now impossible for the numbers not to add up. There is no second sum that could
disagree with the first.

---

## 13. "Cancelled", "access ended" and "refunded" are three different things

**Also called:** event modelling. One fact per field.

**The problem.** People use these words loosely. In a money system they mean different things
and must be kept apart.

**What we do.** Three separate facts.

| Fact | What it means | Does it stop earnings? |
|---|---|---|
| Cancelled | Student switched off auto-renew | **No.** They still have access until the term ends |
| Access ended | Student actually lost access | **Yes** |
| Refunded | Money went back to the student | Depends which kind |

**Example.** A student switches off auto-renew on day 20 of 90. They keep watching to day 90.
Instructors keep earning to day 90. Nothing changes.

Now compare: a student is refunded and cut off on day 45. Earnings stop on day 45.

**Without it.** Treating "switched off auto-renew" as "stop earning" would rob instructors of
two thirds of a term the student is still watching.

**Nice bonus.** A **full** refund needs no new code. Set "access ended" to the start date, so
"days of access" becomes zero, and the existing sum takes everything back on its own.

---

## 14. We never change a balance. We add lines.

**Also called:** an append-only ledger, or an immutable audit log. Negative fixing lines are
compensating entries. The idea comes from double-entry bookkeeping.

**The problem.** If a balance is one number you overwrite, and it ever goes wrong, there is no
way to find out why.

**What we do.** Keep a list that is only ever added to. Nothing is edited. Nothing is deleted.
Fixes are new negative lines.

**Example.**

```
+3,111   earned in March
+1,556   earned in April
  −104   fix, a refund turned up late
−4,563   paid out
------
     0   and you can see exactly how it got there
```

**Without it.** "Your balance is 4,563" with no explanation. When someone disagrees, you have
nothing to show them and no way to find the bug.

---

## 15. A fast copy of the balance, checked every night

**Also called:** a read model or materialised view. The nightly check is reconciliation. The
general idea is derived state plus drift detection.

**The problem.** Adding up millions of lines every time a page opens is far too slow.

**What we do.** Keep one small row per instructor with the totals, updated **in the same
breath** as the lines themselves. A nightly job adds up the real lines and shouts if the small
row disagrees.

**Example.**

```
lines added up:  4,667
the quick row:   4,667   ✓ agree
                 4,700   ✗ alert — someone has a bug
```

**One thing to notice.** The nightly check is there to **catch** problems, not fix them.
Fixing is not allowed. A system that quietly repairs itself hides the bug that caused the
problem. It is a smoke alarm, not a window you open to let the smoke out.

**Without it.** Either the site is too slow to use, or the quick copy drifts away from reality
and nobody finds out until an instructor complains.

---

## 16. One line per instructor per month, not one per subscription

**Also called:** aggregation or rollup. What we give up is granularity.

**The problem.** 500,000 subscriptions × several instructors each × every month is tens of
millions of lines a month. Nothing survives that for long.

**What we do.** One line per instructor per month: "in March, instructor 7 earned 12,430."

**Example.** Instructor 7 has 4,000 students this month. That is **one** line, not 4,000.

**What we lose, on purpose.** We can no longer ask "which student's payment earned this exact
piastre?" We can ask "what did instructor 7 earn in March?" The per-student detail still exists
in the split records — just not in the ledger.

That is a real loss and we took it knowingly. Writing it down as a choice is very different
from a reviewer finding it.

---

## 17. Always work out the total, never the difference

**Also called:** a convergent or self-healing job. In systems talk: level-triggered instead of
edge-triggered. Compare the desired state with the actual state, and close the gap.

**The problem.** Monthly jobs get missed. The server was down. The queue jammed. If each month
only adds "this month's bit", a missed month is gone forever and a human has to go find it.

**What we do.** Every run asks two questions and posts the gap.

```
How much SHOULD this instructor have earned by now?   (work it out from scratch)
How much have we already written down?                (add up the lines)
Post the difference.
```

**Example — March's job never ran.**

```
April's run:
  should have by end of April:  200
  already written down:           0
  post:                        +200     ← March and April both fixed, on their own
```

The system heals itself. Nobody has to notice.

**Same idea, late refund.**

```
already written down:  3,111
should have now:       3,007
post:                   −104     ← a fixing line
```

**Without it.** A missed month needs a person to spot it and run something special. Every
manual fix is another chance to make things worse.

---

## 18. A marker that only moves forwards

**Also called:** a watermark, or a high-water mark. The rule is monotonic — it never goes
backwards.

**The problem.** Point 17 creates a new danger. March's job was missed, April caught it up.
Then somebody notices and runs March's job **late**.

```
March's late run asks:  should have by end of MARCH:  100
                        already written down:         200   (April's catch-up)
                        post:                        −100   ✗ WRONG
```

It would undo April's correct work.

**What we do.** Remember a marker per instructor: "worked out up to this date." A scheduled job
asking about a date already covered does nothing at all.

**Example.**

```
marker says: covered to 30 April
March's late run asks about 31 March
31 March is before 30 April  →  do nothing. Correct.
```

**One exception.** Refund fixes are *allowed* to go backwards — that is their whole job. They
do not touch the marker, so they can never be mistaken for a late scheduled run.

**Without it.** Every late or hand-triggered job quietly undoes correct work, and it shows up as
a mysterious drop in someone's balance.

---

## 19. Only one job may touch an instructor's money at a time

**Also called:** a serialisation point, or pessimistic row locking. The one thing everyone locks
first is the aggregate root. Locking in a fixed order also prevents deadlock. The bug it stops
is a race condition caused by snapshot isolation.

**The problem.** This is the trickiest bug in the system, so take it slowly.

Databases let two jobs run at once. To stay fast, each job sees a **frozen picture** of the data
from the moment it started. It cannot see what the other job is doing until that job finishes.

Now March's job and April's job start at the same moment.

```
Both look at the ledger. Both see: nothing written down yet.

March's job:  should have by March = 100, written = 0  →  posts +100
April's job:  should have by April = 200, written = 0  →  posts +200

Ledger total: 300.     Should be: 200.     We invented 100.
```

Neither job did anything wrong. And nothing catches it — the marker from point 18 does not help,
and no uniqueness rule helps, because they are different months so nothing clashes.

**What we do.** Before touching an instructor's money, a job must **claim that instructor**. One
at a time. Everyone else waits.

**Same two jobs, with the claim.**

```
March's job claims instructor 7.
April's job tries to claim instructor 7  →  waits.
March's job posts +100, finishes, lets go.
April's job looks again: should have 200, written 100  →  posts +100
Ledger total: 200.  ✓
```

**Two problems, two fixes, both needed.** Point 18 stops a **late** job. This one stops two jobs
running **at the same time**. They look similar. Neither covers the other.

**Free bonus.** Different jobs used to touch things in different orders, which can leave two jobs
waiting on each other forever. Having one agreed thing everybody claims first removes that too.

**Cost.** Jobs for *different* instructors never wait for each other, so nothing slows down.

---

# Part 5 — Paying instructors

## 20. Claim the actual lines, not just an amount

**Also called:** an atomic claim, or a conditional update. The general pattern is
compare-and-set. We use the affected-row count to decide who won.

**The problem.** Two payout jobs run at once. Both see "this instructor is owed 500". Both send
500. The instructor gets 1,000.

**What we do.** A payout does not reserve an *amount*. It goes through the ledger and stamps its
name on specific unpaid lines. The database tells us how many lines it actually stamped.

**Example.**

```
Job A: "stamp every unpaid line for instructor 7 as payout #500"  →  3 lines stamped
Job B: "stamp every unpaid line for instructor 7 as payout #501"  →  0 lines stamped
Job B stops.
```

**Extra win.** Forever after, you can ask "exactly which earnings did payout #500 cover?" and get
an exact list.

**Without it.** You need careful locking to make two amount-based jobs safe, and you can never
answer the audit question.

---

## 21. Only pay things that are meant to be paid

**Also called:** an allowlist. The principle is fail-closed, or secure by default.

**The problem.** Today every kind of ledger line is payable. But in a year someone adds a line
type for tax, or an accounting note. If the payout query says "take everything unpaid", it will
wire those to instructors.

**What we do.** The payout query **names** the line types it is allowed to pay.

**Example.**

```
pay these types:  earning, fix, refund adjustment
a "tax withheld" type appears later  →  not named  →  not paid
```

**Note.** This does nothing today, because those are the only types that exist. That is exactly
why it has to be written now. It protects against a change made by someone who never read this
file.

---

## 22. A payout batch is a photo, not a live view

**Also called:** a batch snapshot. What it buys you is deterministic replay — run it again, get
the same answer.

**The problem.** We want to re-run a batch and get exactly the same result. But refunds keep
arriving, and some are dated in the past.

**What we do.** When a batch starts, write down two limits: the cut-off date, and the ID of the
newest ledger line that existed right then. The batch only looks at lines inside **both**.

**Example.**

```
10:00  batch starts.  newest line = 5000.  cut-off = 10:00
10:05  a refund arrives.  It is line 5001, but it is DATED 09:00

Re-running that batch later:
  by date alone:   09:00 is before 10:00  →  would be included   ✗
  plus line limit: 5001 is after 5000     →  excluded            ✓
```

Nothing is lost. That refund is inside both limits for the **next** batch.

**Without it.** Re-running last month's batch gives a different answer than it did the first time,
so you can never check what actually happened.

---

## 23. If they are owed nothing, we create nothing

**Also called:** a guard clause, with rollback used to undo the work.

**The problem.** A batch might pick up 100 earned and a 150 refund. That is −50. You cannot send
a negative payment. The company would reject it — or worse, treat it as a reversal.

**What we do.** Stamp the lines, add them up, and if the total is not positive, **undo the whole
thing**. No payout is created.

**Example.**

```
March:  +100 earned, −150 refund  =  −50
        → undo. No payout. Lines stay unpaid.

April:  +200 earned
        → batch picks up all three lines:  100 − 150 + 200 = 150
        → send 150.  ✓
```

**Why this is neat.** The debt cancels itself. There is no "carry the debt forward" machinery,
because the next batch just picks up every unpaid line, negative ones included.

**Without it.** Either you send a negative payment, or you build a whole separate debt system
that can disagree with the ledger.

---

## 24. A "payout" and an "attempt to send it" are two different things

**Also called:** the intent/attempt split. Payment companies do the same thing — a payment intent
holds several payment attempts.

**The problem.** Two rules that both sound right and contradict each other:

```
"One payout per instructor per batch."
"If a payment definitely failed, try again with a fresh reference."
```

If the payout **is** the attempt, you cannot have both.

**What we do.** Split them.

- A **payout** is a claim on money. One per instructor per batch.
- An **attempt** is one try at sending it. A payout can have several attempts.

**Example.**

```
payout #500   instructor 7, 4,563 piastres
    attempt 1  →  "declined"  →  attempt failed
    attempt 2  →  "sent"      →  payout settled

The payout never changed. The money was never claimed twice.
```

**Bonus.** Each attempt keeps what we sent and what came back. When someone asks what happened on
3 March, there is a record.

---

## 25. Only one attempt in the air at a time

**Also called:** mutual exclusion. In the database it is done with a partial unique index, faked
using a generated column because MySQL has no real partial indexes.

**The problem.** Two workers both decide "the last attempt failed, let's do attempt 2". Both send.
The instructor is paid twice.

**What we do.** Two layers.

1. A worker must **claim the payout** before making an attempt. One gets it, the other waits, sees
   an attempt already exists, and stops.
2. Separately, the database refuses to hold two live attempts for the same payout. So even if the
   code is wrong, the money is safe.

**Example.**

```
Worker A claims payout #500, makes attempt 2, starts sending.
Worker B tries to claim #500  →  waits  →  sees attempt 2 is live  →  stops.
                                           It does not even burn attempt number 3.
```

**The subtle bit.** It matters **which** layer does the real work. If the code checks nothing and
leans on the database to reject the second attempt, you get half-finished work and wasted attempt
numbers. The check should come first. The database rule is the net underneath.

---

## 26. Never hold up the database while waiting on an outside company

**Also called:** keep transactions short. Never hold a lock across I/O.

**The problem.** Claiming an instructor makes other jobs wait. The payment company can take ten
seconds to answer. If we hold the claim while waiting, everything queues up behind someone else's
slow server.

**What we do.** Strictly this order:

```
1. Claim. Stamp the lines. Work out the amount. Let go.   ← fast
2. THEN call the payment company.                          ← slow
```

**Example.** Claiming takes 5 milliseconds. The call takes 8 seconds. Only the 5 milliseconds
blocks anyone.

**Without it.** Your payout speed is decided by the payment company's worst day.

---

## 27. Money moves between three boxes, and each move is all-or-nothing

**Also called:** atomicity — the A in ACID. Combined with the states, it is a transactional state
machine.

**The problem.** Money sits in one of three boxes. Moving between them means changing several
things at once. If the server dies halfway, money ends up in no box at all.

```
available  →  reserved    a payout claimed it
reserved   →  paid        the company sent it
reserved   →  available   the payout failed; give it back
```

**What we do.** Each move is one **transaction** — a group of changes where either all of them
happen or none do. No halfway.

**The bug this stops.**

```
Step 1: mark the payout failed      ✓ saved
        *** server dies ***
Step 2: give the lines back         ✗ never happened

Result: lines still stamped with a dead payout.
        Not available. Not reserved. Not paid.
        The money has vanished from every total.
```

In one transaction, step 1 gets undone too, and nothing is lost.

**Easy to miss.** If a settlement is processed twice by accident, the payout is already marked
settled, so that part does nothing — but the **balance** update has no such protection of its own.
So it is only allowed to run if the payout actually changed. Three careful steps are not
automatically safe together.

---

# Part 6 — When things go wrong

## 28. "No answer" is not "no"

**Also called:** the in-doubt state. In distributed systems this is the two generals problem. The
fix is resolving by status query rather than by retrying.

**The problem.** The single most important idea in the payout system.

We tell the company to send 4,563. The connection times out. Three things could have happened:

```
A. They never got the request.            No money moved.
B. They got it and it failed.             No money moved.
C. They sent it, and the reply got lost.  MONEY MOVED.
```

We cannot tell which. If we assume "failed" and try again, and it was really C, the instructor is
paid twice.

**What we do.** Give "I don't know" its own name — **unknown** — separate from "failed". The money
stays held. The only way out is to **ask**, using the same reference we sent.

```
"What happened to abc123?"
    → "it worked"    →  done, no second payment
    → "it failed"    →  now we may try again
    → "still unsure" →  keep asking
```

**Example.**

```
09:00  send 4,563 as abc123  →  timeout  →  status: UNKNOWN
09:05  ask about abc123  →  "still processing"
09:20  ask about abc123  →  "it worked"   →  marked paid. One transfer. ✓
```

**Without it.** Every timeout becomes a double payment. This is *the* classic way payment systems
lose money.

---

## 29. If a worker dies mid-send, we find out

**Also called:** a lease, or a lock with a time limit. Recovering by lease expiry.

**The problem.** Marking an attempt "sending" stops two workers sending at once. But it creates a
new problem: if that worker dies right after, the attempt says "sending" forever and nobody checks
on it.

**What we do.** Every attempt carries a deadline. A background job looks for attempts past their
deadline and moves them to **unknown** — never to "failed", and never re-sending. From there,
point 28 takes over.

**Example.**

```
09:00  attempt marked sending, deadline 09:02
09:00  worker sends... and the machine dies
09:03  sweeper sees it is past the deadline  →  marks UNKNOWN
09:04  ask the company  →  "it worked"  →  marked paid. Correct.
```

**Careful on purpose.** If the worker died in the split second **before** it sent anything, nothing
went out — but we cannot know that, so we still ask instead of assuming. It costs a few minutes on
a rare path. Guessing would cost money.

---

## 30. Giving up asking is not the same as deciding it failed

**Also called:** handling a late-arriving resolution.

**The problem.** Sometimes the company never gives a clear answer. Eventually we stop asking and
flag it for a person. But that is **us** giving up, not them saying no.

**What we do.** Mark it as unresolved, flag it, keep the money held. And if a clear answer ever
turns up later — a slow background check, a monthly statement, a person reading the company's
dashboard — we still accept and record it.

**Example.**

```
Tuesday:   asked 12 times, no clear answer  →  flagged for a person
Thursday:  the monthly statement shows it went through
           →  recorded as sent. Not thrown away.
```

**Without it.** A confirmed transfer turns up two days late and gets ignored because the system
stopped listening. Then you pay it again.

---

## 31. You cannot declare a payout failed while an attempt is still alive

**Also called:** a state machine precondition, or a guard on a transition.

**The problem.** A real money bug we found by walking through the states.

```
An attempt is still UNKNOWN and still being asked about.
Meanwhile someone marks the payout failed and gives the lines back.
The next batch picks up those lines and pays them.
Then the original answer arrives:  "it worked."

The instructor has been paid twice. The lines now belong to a different payout,
so nothing can be undone automatically.
```

**What we do.** A payout can only be marked failed if **no attempt is still alive** and **no
attempt worked**. Checked at the moment of marking, while holding the claim.

**Example.**

```
Try to fail payout #500
   →  attempt 2 is still UNKNOWN
   →  refuse. Wait for a clear answer first.
```

**Honest limit.** This closes every case the **system** can cause. It cannot close the case where a
person looks at an unclear payment, decides in good faith that it failed, and is wrong. If a success
turns up afterwards, the money cannot be recovered automatically. So that case raises a loud alert
and a record for a person.

We could close it fully — by never releasing money until the company gives a clear answer — but
that means holding an instructor's money forever every time a company goes quiet. We judged that
worse. The point is that the choice is written down, not accidental.

---

## 32. Once a person is involved, machines stop touching it

**Also called:** listing states explicitly instead of writing conditions as "not finished".

**The problem.** A payout waiting for a person still has its money held, so as far as the system is
concerned it is "not finished". Any background job written as "retry anything unfinished" would grab
it and retry.

**What we do.** Background jobs name the exact states they act on. The waiting-for-a-person state is
never one of them.

**Example.**

```
sweeper looks at:  waiting to send, in progress
sweeper ignores:   waiting for a person
```

**Worth keeping.** A state can be **unfinished for money** and **finished for machines** at the same
time. Writing job conditions as "anything unfinished" quietly destroys that difference.

---

## 33. Let the database enforce the rules, not the code

**Also called:** constraints over conventions. Pushing invariants into the database.

**The problem.** Rules that live only in code get forgotten by the next person, skipped by a script,
or missed in an unusual path.

**What we do.** Wherever we can, the rule lives in the database as a **constraint** — something the
database itself refuses to break, no matter who is asking.

**Example.**

```
Rule: one payout per instructor per batch.

In code:      if (a payout already exists) { stop; }
              ← two jobs can check at the same moment, both see "no", both create one

In database:  "this pair must be unique"
              ← the second one is rejected. Always. Even from a manual script.
```

**Where locks fit.** We also use locks (point 19) so jobs take turns. Locks make things orderly and
fast. Constraints make things **correct**. If you have to pick, correct wins — so no rule depends on
a lock alone.

---

# Part 7 — Refunds, all together

## 34. The two kinds of refund

**Also called:** prospective adjustment (stop future earnings) versus retrospective adjustment (take
back what was already credited).

| Situation | What we do | Do we take money back? |
|---|---|---|
| The student leaves, and the instructor **has not earned the rest yet** | Stop future earnings | **No** |
| The instructor **was already credited** more than they should have | Add a negative line | Yes, out of future earnings |

**Example — the normal case, day 45 of 90.**

```
Instructor 3 keeps 4,667. The other 4,667 simply never arrives.
Nothing is taken back. Nothing to explain to anyone.
```

**Example — the awkward case.** A refund lands after we already paid.

```
already paid:      3,111
refund fix:         −104
balance now:        −104
next month earns: +1,556  →  balance 1,452  →  paid as normal
```

It sorts itself out. Nobody chases anybody.

**The case with no good answer.** If that instructor never earns again, the −104 has to be written
off by a person. Every system has this problem. We name it instead of pretending.

---

## 35. One refund can cost the platform more than it costs instructors

**Also called:** nothing new — this is just the residual claimant rule (points 8 and 12)
showing up in a place that surprises people.

Worth knowing, because it looks like a bug and is not.

**Example.** A student is refunded 200 as a goodwill gesture, but only 175 was unearned.

```
Instructors:  unaffected — their earnings are capped by access, not by the refund amount
Platform:     takes the extra 25
```

This falls straight out of "the platform gets what is left" (points 8 and 12). It is correct and
intended.

---

# Part 8 — How we know it works

## 36. Tests that check rules are not enough

**Also called:** you need a test oracle. Hand-written cases are example-based testing; checking one
version against another is differential testing; checking general rules is property-based testing.

**The problem.** Our nightly check compares the ledger against a calculation. But the job that
**writes** the ledger uses the same calculation. If that calculation is wrong, both sides are wrong
together and agree perfectly.

**A bug that passes everything.**

```
Suppose the earning sum accidentally halves everything.

"never negative"?           ✓ passes
"never above the maximum"?  ✓ passes
"ledger matches the sum"?   ✓ passes — it is the SAME wrong sum

Every instructor is paid half. Nothing fails.
```

**What we do.** Two things that do not share the suspect sum.

1. **Hand-written examples.** "A 9,000 share over 90 days must be exactly 3,000 on day 30." Written
   by a person from the requirements, not copied from the code.
2. **A second, deliberately stupid version.** Walk through the term one day at a time and add up.
   Far too slow for real use, but obviously right. Feed both versions thousands of random inputs and
   demand identical answers.

**The difference.** A rule says "the answer must be **sensible**." An example says "the answer must
be **this**." Only the second kind catches a sum that is wrong in a consistent way.

---

## 37. Testing "twice" is not testing "at the same time"

**Also called:** interleaving tests, or deterministic concurrency testing.

**The problem.** "We tested it by running it twice" is a very common claim and it proves much less
than it sounds.

**Example.**

```
Run the job, let it finish, run it again.
  →  the second run sees the first run's work and correctly does nothing.
      Passes. Proves almost nothing.

Start both, hold the first one open before it finishes, then start the second.
  →  this is where the bug in point 19 actually lives.
```

**What we do.** Concurrency tests use two separate database connections driven in a set order: start
A, pause it before it finishes, run B, check B waits or refuses, then let A finish.

---

## 38. A pretend payment company that actually remembers things

**Also called:** a fake, not a stub or a mock. The difference matters — a fake has a working
implementation inside it.

**The problem.** The hardest case is "the money moved, but the reply got lost" (case C in point 28).
A pretend company that just throws an error without recording anything does not test that. It only
tests our error handling.

**What we do.** Our pretend company keeps a record of every transfer, like a real one.

```
send(abc123)
    seen this reference before?  →  return the same answer, send nothing new
    otherwise  →  record the transfer  →  THEN, if the scenario says so, throw a timeout

ask(abc123)
    →  return what was recorded
```

**Example.** The timeout scenario records the success **before** throwing, so the money really has
moved as far as the pretend company is concerned. Our system then has to find that out by asking —
which is exactly the behaviour we want to prove.

**Two versions.** The test one is fully scripted, so results never change between runs. The demo one
is random but uses a fixed starting seed, so a recording can be re-taken and produce the same
sequence of failures.

---

# Name cheat sheet

Everything in one place.

| Point | Plain version | The name |
|---|---|---|
| 1 | Whole numbers only | Minor units, integer money, fixed-point |
| 2 | Percentages as whole numbers | Basis points (bps) |
| 3 | Some columns may go negative | Type-level invariants |
| 4 | Two keys on a payment | Idempotency key + external reference dedup |
| 5 | Each step guarded separately | Per-step idempotency, natural key constraint |
| 6 | Swappable split rule | Strategy pattern, revenue sharing |
| 7 | Freeze the numbers | Snapshotting, point-in-time data |
| 8, 12 | Platform takes what's left | Residual claimant |
| 9 | Fair rounding | Largest remainder / Hamilton's method, apportionment |
| 10 | Earn over time | Revenue recognition, deferred revenue, straight-line |
| 11 | Floor and ceiling | Clamping |
| 13 | Three separate facts | Event modelling |
| 14 | Add lines, never edit | Append-only ledger, compensating entries |
| 15 | Fast copy, nightly check | Read model / materialised view + reconciliation |
| 16 | One line per month | Aggregation, rollup; loses granularity |
| 17 | Work out the total, post the gap | Convergent job, level-triggered, desired vs actual state |
| 18 | Marker only moves forward | Watermark, high-water mark, monotonic |
| 19 | One job per instructor at a time | Serialisation point, pessimistic lock, aggregate root |
| 20 | Stamp the lines | Atomic claim, conditional update, compare-and-set |
| 21 | Name what may be paid | Allowlist, fail-closed |
| 22 | Batch is a photo | Batch snapshot, deterministic replay |
| 23 | Nothing owed, nothing made | Guard clause + rollback |
| 24 | Payout vs attempt | Intent/attempt split |
| 25 | One attempt at a time | Mutual exclusion, partial unique index |
| 26 | Let go before the slow call | Short transactions, no lock across I/O |
| 27 | All-or-nothing moves | Atomicity (ACID), transactional state machine |
| 28 | "No answer" ≠ "no" | In-doubt state, two generals problem, status reconciliation |
| 29 | Deadline on every attempt | Lease, lock with TTL |
| 30 | Late answers still count | Late-arriving resolution |
| 31 | Cannot fail a live attempt | State machine precondition / guard |
| 32 | Machines leave human cases alone | Explicit state lists, not "not finished" |
| 33 | Database enforces rules | Constraints over conventions |
| 34 | Two kinds of refund | Prospective vs retrospective adjustment |
| 35 | Big refunds hit the platform | Residual claimant again |
| 36 | Need a second opinion | Test oracle, differential + example-based testing |
| 37 | Two at once, not twice | Interleaving / deterministic concurrency tests |
| 38 | Pretend company with a memory | Fake (not a stub or mock) |

---

# Glossary

| Word | In plain terms |
|---|---|
| **Piastre** | 1/100 of an Egyptian pound. All money is whole numbers of these |
| **Basis point** | 1/100 of a percent. 20% = 2000 |
| **Ledger** | A list of money movements that is only ever added to |
| **Row / line** | One entry in a database table |
| **Transaction** | A group of changes that either all happen or all get undone |
| **Lock / claim** | Saying "I'm working on this" so others wait |
| **Constraint** | A rule the database itself will not break |
| **Race condition** | A bug that only shows up when two things happen at once |
| **Snapshot** | The frozen view a job sees while it runs; it cannot see others' unfinished work |
| **Idempotent** | Doing it twice has the same result as doing it once |
| **Worker / queue** | A background job. It can crash, and it can be run again |
| **Reconciliation** | Checking one set of numbers against another to catch mistakes |
| **Watermark** | A marker saying "worked out up to here" |
| **Allocation** | An instructor's full share of one payment, decided once, never changed |
| **Release** | Handing that share over gradually as time passes |
| **Claim** | Stamping ledger lines as belonging to a payout |
| **Settle** | Confirming the money actually reached the instructor |
| **Deadlock** | Two jobs each waiting for the other. Neither ever moves |

---

# Where each point lives in `ARCHITECTURE.md`

| Topic | Here | There |
|---|---|---|
| Storing money | 1–3 | §3 |
| Money coming in | 4–5 | §5.1 |
| Sharing it out | 6–9 | §5.2–5.5 |
| Earning over time | 10–13 | §6 |
| Ledger and balances | 14–16 | §6.4, §10.5, §12 |
| Self-healing and ordering | 17–19 | §6.2, §9.4 |
| Payouts | 20–27 | §10 |
| When things go wrong | 28–33 | §11 |
| Refunds | 34–35 | §7 |
| Testing | 36–38 | §16 |

---

# The seven failure scenarios, and what handles each

Useful for walking through the system out loud.

| Scenario | Points | What you should see |
|---|---|---|
| Payouts run twice | 20, 33 | Second run stamps zero lines; one payout exists |
| Two workers at once | 19, 25, 33 | One wins; the other stops cleanly |
| Worker crashes and retries | 27, 29 | Attempt goes to unknown; we ask, we do not re-send |
| Company times out | 28 | Status unknown, money held, no retry |
| Company succeeded, told us late | 28, 30 | Ends up paid; exactly one transfer |
| Refund after a payout | 23, 34 | Negative line; next batch nets it off by itself |
| Rounding | 8, 9, 12 | Every split adds back to the exact original |
