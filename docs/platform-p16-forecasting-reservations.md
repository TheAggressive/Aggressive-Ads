# P16 — Forecasting and reservations

## Status

- Phase: **P16 — Forecasting and reservations**
- Roadmap state: `[ ]`
- Last audited: 2026-09-06
- Authoritative environments: CI's pinned MySQL 8.4 / PHP 8.4 lanes

This document records in-progress work. It does not claim completion.

## Outcome

A publisher can find out how much inventory a placement is likely to produce
over a window, hold some of it against a campaign, and be told — not silently
prevented — when they are about to sell more than they have. Forecasts advise.
They do not block authorized staff, and they do not present uncertainty as
capacity.

## What the contract fixes before this phase starts

[platform-inventory-commerce-contract.md](platform-inventory-commerce-contract.md)
settles two things that are therefore not open here.

**The audience.** A forecast is staff-only; an advertiser sees availability,
never capacity. The three reasons are recorded in that document so this is not
re-litigated as a UI preference — a forecast is the publisher's negotiating
position, a number shown to a buyer stops being advisory, and override, actor
and oversell have no advertiser-facing meaning.

**The declarations.** The contract requires this phase to state its source
history, exclusions, seasonality assumptions, minimum data, confidence
representation and conservative fallback. Those are not recorded here in prose
that can drift from the code. They are in the class docblock of
`Domain\Supply_Forecast`, beside the arithmetic they govern, and asserted in
`SupplyForecastTest` — because a declaration in a document is a claim, and a
declaration a test kills a mutant for is a property.

## Slice 1 — the supply estimate *(built)*

`Domain\Supply_Forecast` answers one question: given a placement's observed
daily opportunity history, how much will it produce over a window?

**It reads the grain P15 defined.** Page opportunities and refresh
opportunities are separate series and are never summed. Refresh supply is
forecast from refreshes that were actually recorded, never from `rotateSeconds`
— which is the contract's "avoid forecasting infinite supply from a timer"
obligation, discharged by construction rather than by a check. A timer
describes an upper bound on a page nobody has visited.

**A quantile, not a mean.** This is the decision the phase turns on. A mean is
missed on about half of days, so a publisher who sells to it under-delivers
half of what they sell — which is the failure P16 exists to prevent. The
estimate is the 20th percentile of observed days: on four days in five, this
placement produced at least this much. It is also robust in the direction that
matters, because one viral day lifts a mean and does not move a low quantile.

**Nearest rank, not interpolation.** Every figure returned is a day that
actually happened, which is what lets an estimate be described to a buyer as a
day the publisher has already had.

**No data is not no inventory.** An unobserved placement returns `null`, not
zero. Zero would tell a publisher they have nothing to sell and refuse a
booking on the strength of no evidence at all. The same distinction
`Domain\Fill_Figures` draws for a rate with no denominator.

**Two fields, not three.** The result carries `estimate` and `optimistic`. An
earlier shape had `estimate`, `low` and `high`, where `estimate` and `low` were
always the same number — one value in two fields, and an invitation to read the
pair as a range with the forecast in the middle. It is not in the middle. The
conservative bound *is* the answer.

**The spread and the confidence answer different questions.** Confidence names
how much history backs the figure — and, one for one, which method produced it.
Volatility is in the gap up to `optimistic`. Thirty days of wildly swinging
traffic is well observed and therefore high confidence; the wide spread is the
honest way to say the swing is real, and the estimate is already protected from
it because volatility drags a low quantile down unaided.

### The timezone is load-bearing, and only one test proved it

`Supply_Forecast` pins every date to UTC explicitly. The first test written for
this swapped the ambient zone between two Pacific zones, asserted the forecast
was unchanged, and passed — over an implementation that read the ambient zone.
Both zones have every calendar date, so parsing `2026-02-03` in either lands on
`2026-02-03`, and the assertion was true of the mutant too.

The case that reaches it is a zone that skipped a day. Samoa crossed the date
line at the end of 2011 and `2011-12-30` never happened in `Pacific/Apia`:
parsing it there yields the 31st, so a window loop silently drops a day and a
history key silently fails its round-trip check. A publisher in such a zone
would get a window a day shorter than they asked for and history a day thinner,
with nothing reporting either.

The general shape is in
[testing-strategy.md](testing-strategy.md): the mutation run is what caught it,
and the mutation *harness* nearly hid it — see below.

### A mutation harness that reported every mutant killed

The first run reported 14 of 14 killed. It was matching PHPUnit's summary with
`grep -E "^OK"`, and PHPUnit colours that line, so `OK` is never at the start of
it. Nothing matched, every mutant looked killed, and the two that actually
survived were invisible.

Re-run against PHPUnit's exit code with a no-op control mutant that must be
reported as surviving, it found both. **A mutation harness needs a control**,
for the same reason a `bin/ci/` guard needs to print a count: an instrument that
reports success over code it is not reading is worse than no instrument.

21 real mutants, all killed.

## Slice 3 — the snapshot, and the outcome it is judged against *(built)*

`aggr_forecasts` (db version 27) stores what a placement was forecast to
supply and, later, what it actually did. `Repository\Forecast_Repository`
owns the table; `Workflow\Forecast_Recorder` composes it with
`Supply_History`.

**A forecast is a claim made at a moment.** Re-forecasting the same window
writes a new version rather than editing the old one, so a figure quoted in
March survives being told something else in April — which is the only thing
that makes the error recorded against the March number mean anything. The
unique key is what makes a version a version: two staff re-forecasting at once
cannot both claim the same one, so the loser fails its insert instead of
silently overwriting a snapshot somebody has already been given.

**`actual` is the single mutable column, and it is write-once.** The `IS NULL`
predicate in the `UPDATE` is what enforces it, not a read-then-write, because
a matured figure that can be rewritten is a figure an inconvenient forecast
error can be edited out of. It is applied to *every* version of a window, since
each one forecast the same days and each is wrong or right about the same
outcome.

**A window that has not closed is never matured.** `Supply_History::supplied()`
answers null while a window is open, and the recorder skips rather than
recording it. A partial window summed as though complete produces an actual
below the truth, and the resulting error would report every placement as
over-forecast — an error that says the model is pessimistic when what happened
is that nobody waited.

**A forecast with no estimate is not stored at all.** There is nothing to be
wrong about later, so the row would only add a version whose error can never be
computed, and a history of them would make an unmeasurable placement look
re-forecast.

Recording is deliberately not automatic. Nothing forecasts on a schedule: a
snapshot is a claim somebody made, so it is written when staff ask for a
figure. Maturing needs no judgement, only a closed window, so that half is a
bounded batch a job can drive — and a missed run costs nothing, because the
window stays in `awaiting_actuals()` and nothing tracks a watermark that could
be wrong.

### Two mutants survived a filter that hid them

`awaiting_actuals()` already excludes windows that have not ended, so the
guards beneath it — the open-window check and the null-supply skip — were never
reached by a test, and both survived. They are reached by a caller passing a
day the clock has not got to, which is a wrong argument or a skewed clock, and
is precisely when a partial window would be written down as an outcome. Asserted
through that path now; ten mutants, all killed.

## Slice 4 — how wrong it turned out to be *(built)*

`Domain\Forecast_Error` compares an estimate with the outcome, and
`Forecast_Recorder::accuracy()` summarises a placement's run of them.

**Direction matters more than magnitude, and not symmetrically.** The estimate
is the twentieth percentile of observed days, so on roughly four windows in
five the placement is *expected* to supply more than forecast. Under-forecasting
is the design working, and a summary reporting it as error would have staff
correcting a model behaving exactly as commissioned.

So `oversold` is the headline rather than the mean. A large average miss says
little; a single window a publisher sold against and could not fill is the
failure this phase exists to prevent, and averaging the two together produces a
comfortable number with the failures hidden inside it. The sign follows the
same logic: `actual - estimate`, so positive means the placement beat its
forecast — the safe miss. The other order would put the alarming case in
positive numbers and invite a screen to show the reassuring one in red.

**Null, not zero, for anything unjudged.** A window nobody has measured has not
been forecast accurately; it has not been judged at all, and a perfect score
would make an unmeasured placement the best performing one on the screen. A
window that supplied nothing has no percentage either, because every miss
against a zero denominator is infinite — but it still counts as judged and as
oversold, since it is the worst possible outcome and must not drop out of the
number that names it.

**One measurement per window, not per version.** A window forecast four times
would otherwise contribute four opinions about one outcome, weighting a
much-revised window four times as heavily — and revision usually means somebody
was uncertain, which is the opposite of the weighting anybody would choose. The
newest *judged* version is the one measured, so re-forecasting a closed window
does not quietly erase what the model was scored on.

### One equivalent mutant, recorded rather than tested around

Adding `actual` to the `GROUP BY` in `matured()` survives. It is genuinely
equivalent: the subquery already filters `actual IS NOT NULL`, so the column
cannot vary within it, and `record_actual()` writes one figure to every version
of a window. There is no line to delete — the mutation adds redundancy rather
than removing a guard — so this is written down instead of chased with a test
that would only assert the grouping's shape. Every other mutant across the two
slices is killed.

## Slice 5 — the reservation ledger *(built)*

`aggr_reservations` (db version 28) holds time-bounded claims against a
placement's forecast supply. `Domain\Reservation_Rules` owns the vocabulary and
the lifecycle; `Repository\Reservation_Repository` owns the table.

**Which statuses consume capacity is the decision everything rests on.** Held
and confirmed do; released and expired do not. A hold that consumed nothing
would let the same inventory be promised to every advertiser who asked — every
check would pass and the shortfall would appear only when the window ran. And
capacity that never came back would ratchet downward with every cancellation
until the placement refused everything.

**The declared consistency model is an advisory lock.** The contract requires
reservation checks to be atomic and the tolerated oversell bound to be measured
rather than assumed; a lock makes that bound zero by construction, which is a
stronger claim than reasoning about isolation levels and is checkable by
reading one method. The obvious alternative — `INSERT … SELECT` with the
capacity sum in a `WHERE` — reads as atomic and is not: under `REPEATABLE READ`
two concurrent bookings see the same snapshot, both find room, and both insert.
The cost is serialising bookings per placement and window, which is nothing at
the rate people negotiate campaigns, and would be the wrong trade on the fill
path. `Rate_Limit_Repository` and `Campaign_Repository` already use the same
primitive.

Status changes carry the current status in the `WHERE`, so a transition is
checked and applied in one statement. Reading, deciding, then writing would let
two requests both see `held` and both act — releasing a reservation somebody
else had just confirmed.

Expiry takes abandoned holds and leaves commitments alone: a confirmed
reservation whose window ended is a delivered booking, and expiring it would
rewrite history into a claim nobody honoured.

`forecast_version` is stored on every claim because the contract requires an
oversell override to name one. A reservation that could not say which figure it
was checked against would leave a publisher knowing somebody overrode a warning
and not what the warning said.

### The mutation harness ran the wrong suite

The rules are unit-tested and the ledger is an integration test, and the first
run filtered both through the integration config — which printed "No tests
executed!" for the unit filter and carried on. Every domain mutant was
therefore judged only by what the integration tests happened to reach, and the
one mutation none of them could reach was reported as surviving. Run across
both suites: nineteen mutants, all killed, control survives.

## Not built yet
- **Reservations.** Table, lifecycle, concurrency-safe quantity and status
  changes, audit.
- **Oversell warning and audited override** naming actor, reason, forecast
  version and expected impact.
- **The staff surface**, behind its own capability, and the derived
  advertiser-facing availability answer that exposes no number.
