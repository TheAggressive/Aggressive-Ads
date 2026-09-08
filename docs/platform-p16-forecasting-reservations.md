# P16 — Forecasting and reservations

## Status

- Phase: **P16 — Forecasting and reservations**
- Roadmap state: `[x]`
- Last audited: 2026-09-08
- Authoritative environments: CI's pinned MySQL 8.4 / PHP 8.4 lanes

**Closed.** Every slice below is built, and the exit criteria at the end of this
document record where each one is proven rather than asserted.

This block said `[ ]` and "does not claim completion" while slices 5, 6 and 7
were already marked *(built)* above, and a "Not built yet" list at the end named
exactly those three. The status was stale in three places at once, in a document
whose job is to say what is true. That is recorded rather than quietly corrected
because it is the failure mode this file exists to prevent: a phase document
that disagrees with itself is worse than none, since a reader has no way to tell
which half is current and will usually believe the pessimistic one.

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

## Slice 6 — the oversell warning and its audited override *(built)*

`Domain\Availability` compares a forecast against what is claimed;
`Workflow\Booking_Service` is the one place a booking decision is made.

**Three verdicts, not two.** A window has room, is short of it, or was never
forecast — and the third is a different fact from the second. Treating
"unmeasured" as unlimited is how an oversell starts; treating it as zero
refuses every booking on a placement nobody has measured, which is every new
placement. So `unknown` is bookable and says so, and no number reaches an
advertiser either way.

**Overselling warns, it does not block.** The contract is explicit, and the
reason is that the estimate is a twentieth percentile: a publisher who knows
their inventory better than the model is often right to sell past it. What must
not happen is selling past it unrecorded — so an oversell without a reason is
refused, and with one it proceeds and writes an audit row naming the actor, the
reason, the forecast version overridden and the shortfall accepted. The
forecast *value* goes in too, because a version number stops meaning anything
once retention purges the snapshot.

**Gated on `MANAGE_PLACEMENTS`, not on a capability of its own.** A separate
oversell primitive was the obvious design and would today have exactly the same
holders — only administrators hold `MANAGE_PLACEMENTS` — so it would be a
distinction with no difference, and a permission nobody can hold separately
reads as protection it does not provide. P21 is the change that makes a second
capability mean something.

### The ceiling moved into the domain because a mutant survived

`Booking_Service` hands the ledger a capacity for each claim, since the ledger
re-checks inside its lock where the reading above does not run. Making that
figure `PHP_INT_MAX` survived every test: its value is observable only under
concurrency, and the PHP suites are single-connection.

That is a rule, not plumbing, so it became `Availability::ceiling()` —
`committed + requested`, asserted directly rather than inferred from a race
nobody can stage. Fourteen mutants across the slice, all killed.

## Slice 7 — the staff surface *(built)*

`Admin\Forecast_Data` assembles one window's outlook; `Admin\Forecast_Screen`
prints it under **Advertising → Outlook**, gated on `MANAGE_PLACEMENTS` — the
same capability `Booking_Service` requires, so anyone who can see the outlook
could act on it.

Until this existed, `Forecast_Recorder` and `Booking_Service` were reachable
only from tests. That is the gap it closes.

**Two queries for the catalogue.** The forecast and the committed total are
each read in batch, because the screen lists every active placement and the
ceiling is two hundred.

**The window starts tomorrow.** Today is half elapsed and cannot be sold
whole, so including it would offer inventory that has partly gone — and mix a
part-measured day into a forecast of unmeasured ones. The first version
anchored on the reconciler's sealed day, which put today in the window; the
reconciler dependency went with the fix, because a forward-looking window has
no need to know where the counters stop.

**Unmeasured is not sold out**, on the screen as in the domain: a placement
nobody has forecast shows a sentence rather than a nought, and `unforecast` is
counted in the totals so partial coverage is not presented as complete. The
only colour on the screen marks `oversold`, and only when it is above zero —
colouring a zero green would make an ordinary state look like an achievement.

Twelve tests; eight mutants, all killed. One survived first: every test asked
for the page view, so forcing the kind changed nothing — a refresh-view case
fixed that.

## Closeout — what was finished last

Three things were genuinely incomplete when this phase was audited on
2026-09-08. Two were defects; one was the documentation.

### The screen's own rows could not report an oversell

`Forecast_Data::row()` asked `Availability::decide( $capacity, $committed, 0 )`.
That is a different question — *does one more claim of nothing fit* — and
nothing always fits, so the verdict came back `available` for every forecast
placement including the ones already sold past their estimate.

The summary above the table counted those same placements as oversold, using an
inline `committed > forecast` of its own. **Two definitions of oversold on one
screen, disagreeing about the same placement in the same render**, and neither
checkable against the other.

`Availability::holdings()` now answers the question the screen actually asks,
built on `decide()` so the rule has one definition, and the summary counts the
rows' own verdicts. A test asserts the tile equals the set of rows claiming to
be oversold, over a catalogue holding an oversold, a healthy and an unmeasured
placement, so the two cannot drift apart again.

The verdict had also never been rendered: it was in the payload and in
`types.ts`, with `available` / `oversell` / `unknown` labels shipped to every
staff member, and no component read any of it. The table now has a status
column, which is what gives those labels a reader.

**One equivalent mutant, recorded rather than tested around.** Restoring the
summary's inline `committed > forecast` still passes, because with `holdings()`
correct the two agree on every reachable input — they diverge only for a
negative commitment, which the ledger cannot produce. The consolidation is for
the next edit, not for this one.

### Nothing proved the daily job was reachable

Every test called `Forecast_Scheduler::run()` directly. Remove the scheduler
from `Plugin::service_init_order()`, or the `add_action` from `init()`, and all
of them still passed while no site ever took another snapshot — the read half
and the write half not meeting.

A test now arms it the way `init` does, asserts the recurring event is booked
with the right recurrence, and fires `Forecast_Scheduler::HOOK` the way WP Cron
does, touching `run()` at no point. Three mutants confirm it: never registering
the hook, never arming on `init`, and never booking the event all die.

The `init` listener is asserted rather than fired, because running the whole of
`init` inside a test re-registers the block and trips core's own doing-it-wrong
guard.

### The concurrency guarantee was implied

The advisory lock around `claim()` had no test. Every case ran one claim at a
time, which proves the arithmetic and nothing about serialisation: deleting
`acquire()` left the suite green.

A second `wpdb` connection now holds the lock while a claim is attempted —
`GET_LOCK` is re-entrant within one session, so taking it on `$wpdb` would have
asserted the opposite of the truth. The claim is refused as `busy`, consumes
nothing, and succeeds once the lock is free. A second test asserts
`IS_USED_LOCK` is null afterwards, because a leaked lock does not fail the
session holding it — it fails whichever *other* request wants that window next,
three seconds later, as a refusal nobody can reproduce.

## Exit criteria

| Criterion | Where it is proven |
|---|---|
| Forecasts come from recorded supply, and missing history is never zero capacity | `SupplyForecastTest::test_no_history_is_not_no_inventory`, `test_an_omitted_day_is_unknown_rather_than_zero`, `ForecastScreenTest::test_an_unforecast_placement_is_not_sold_out` |
| Reservations consume and release capacity | `ReservationTest::test_a_hold_consumes_capacity_against_the_next_booking`, `test_releasing_returns_the_inventory_to_the_pool` |
| Concurrency is serialised, not assumed | `ReservationTest::test_a_window_another_request_is_booking_is_refused_rather_than_double_sold`, `test_the_lock_is_released_when_the_claim_is_done` |
| Oversell warns rather than blocks | `OversellOverrideTest::test_an_oversell_with_a_reason_proceeds`, `test_an_oversell_without_a_reason_is_refused` |
| An override names actor, reason, forecast version, value and shortfall | `OversellOverrideTest::test_the_override_names_everything_an_investigation_needs` |
| The ledger guard survives an acknowledged oversell | `OversellOverrideTest::test_an_acknowledged_oversell_does_not_disable_the_ledger_guard` |
| Snapshots and maturation run through the production path | `ForecastScreenTest::test_the_cron_hook_is_armed_and_produces_a_snapshot`, `test_the_job_records_what_a_finished_window_supplied` |
| The schedule is removed on uninstall | `ForecastScreenTest::test_the_schedule_is_removed_on_uninstall` |
| The screen uses the domain rules rather than its own | `ForecastScreenTest::test_the_oversold_tile_equals_the_rows_that_say_oversold`, `AvailabilityTest::test_a_window_sold_past_its_forecast_is_oversold` |
| Capability gating | `ForecastScreenTest::test_a_reader_without_the_capability_is_refused`, `OversellOverrideTest::test_a_user_who_may_not_manage_inventory_is_refused_and_recorded`, `test_a_reviewer_may_not_book_either` |
| Tenant isolation | `ReservationTest::test_reservations_are_listed_by_organization`, `test_another_placement_is_not_this_ones_inventory` |
| Migration | `ReservationTest::test_a_migration_exists_to_create_the_table`, `PlanningSchemaTest` (indexes asserted on the live table, including that the version key is genuinely unique) |
| Performance | `Forecast_Data::view()` reads the forecast and the committed total in two batched queries for the whole catalogue, not per placement |
| No number reaches an advertiser | `AvailabilityTest::test_an_advertiser_is_told_yes_or_no_and_nothing_else` |

### What this phase deliberately did not do

- **No advertiser-facing booking flow.** `Booking_Service` is staff-only behind
  `MANAGE_PLACEMENTS`. The advertiser-facing answer exists in the domain
  (`Availability::bookable()`) and exposes no number, but nothing in the portal
  calls it yet — there is no screen where an advertiser asks for inventory.
  Recorded here rather than left as an assumption: the domain half is finished
  and untested against a caller because it has none.
- **No spend or price.** Reservations hold opportunities, not money. Billing
  has no source, so a reservation cannot be costed.
