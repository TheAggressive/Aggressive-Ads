# Platform P14 reporting

P14 answers "what did this do, and can I hand the number to somebody?". P10
gave the lifecycle its vocabulary, P11 and P12 built two of its signals, and P13
made the storage bounded, versioned and reconcilable. P14 is where a person
finally reads it.

This document defines the work and its exit criteria. Where something has
shipped it is marked; everything unmarked is still a definition, not a claim.

## Status

- Phase: **P14 — Reporting**
- Roadmap state: `[ ]` — in progress
- Authoritative environments: the Docker CI lanes, MySQL 8.4. Query plans and
  read budgets are decided there; the browser lanes decide the screens.

**Landed so far:** `Report_Period`, `Rollup_Report_Repository`, the ranged and
freshness-aware `Reporting_Read`, and the dashboard tiles reading a stated
window instead of all of history. Everything else below is still ahead.

## Outcome

Three readers get an answer they cannot get today.

- **A publisher can see why a slot is empty.** P13 stores it —
  `aggr_decision_rollups` holds a request count, a fill count and a reason for
  every opportunity that did not fill — and **nothing reads it.** The read
  methods exist (`Decision_Metrics::totals()` and `totals_for_placement()`,
  `Decision_Rollup_Repository` beneath them) and no production code calls
  either; `Decision_Engine` is the only caller of that class and it only
  writes. P13 narrowed its first exit criterion to say so explicitly and handed
  the screen here.
- **An advertiser can see a period.** Every number the portal shows today is
  either all-time (`Reporting_Read::totals_for_org()`) or a fixed seven days
  (`series_for_org()`). There is no date range, no comparison, and no way to ask
  what last month looked like.
- **Anybody can see conversions.** P12 shipped ingestion, deduplication,
  attribution and a definitions screen. `aggr_rollups.conversions` is written,
  reconciled and retained — and it appears in no tile, no table, no CSV column
  and no REST field. A feature was built, is running, and is invisible.

And one property applies to all three: **a report says how fresh it is.** The
reconciliation watermark exists (`aggr_rollups_reconciled_through`) and no
surface reports it, so a partial day and a reconciled one look identical.

## The defect this phase inherits

`Rollup_Repository::totals_for_org()` is the first tile on the advertiser
dashboard and it is unbounded:

```sql
SELECT SUM(impressions), SUM(clicks), SUM(viewables)
FROM {prefix}aggr_rollups
WHERE org_id = %d AND campaign_id > 0
```

There is no date predicate. `org_day (org_id, day_utc)` leads on the right
column, but the aggregate needs `impressions`, `clicks` and `viewables`, so
every matching row is a primary-key lookup — and every row the organization has
ever produced matches. Rows per organization are placements × campaigns × line
items × days, and days are bounded only by retention, which defaults to keeping
everything.

This is the clause in the phase template that says no ordinary screen may
perform work proportional to all records when it needs one bounded scope, and
the dashboard is doing it on every page load.

**Measured before it was fixed**, at one year of a modest advertiser's history —
10 campaigns × 5 placements × 365 days = 18,250 rows for the organization inside
a 25,550-row table:

| read | rows examined |
|---|---|
| all-time org total (before) | 12,775 |
| 30-day org total (after) | 1,500 |
| 30-day org series (after) | 1,500 |

The ratio is not the point; the shape is. The ranged read examines 50 rows per
day in range and nothing else, so it is flat as the site ages. The unbounded one
is proportional to everything the organization has ever delivered, and at a
default retention of *keep forever* nothing ever brings it back down. A year in,
it costs 8.5×; three years in, it costs 25×, for the same tile.

Recorded by `ReportReadScaleTest`, which EXPLAINs `$wpdb->last_query` after
calling the repository, so the plan it judges is the one production issued
rather than a copy that can drift out of step with it.

## Scope boundary

This phase owns:

- report semantics: what a metric means, what a date range means, what a
  comparison means, and which absences are which;
- the timezone and freshness contract every report states;
- a publisher-facing report over the P13 decision counters and the delivery
  rollups;
- an advertiser-facing report with ranges and comparison, replacing the fixed
  windows the portal has now;
- conversions becoming visible wherever impressions and clicks already are;
- CSV export for both readers, bounded and gated; and
- the architecture for scheduled delivery — the seam, not the scheduler.

This phase does not own:

- **new events, columns or projections (P13, closed).** If a report wants a
  number the projections cannot produce, the answer is to narrow the report or
  open a schema phase — not to add a column here.
- **billing, spend or invoices (P19).** Spend stays absent. Currency fields
  exist; nothing in a report may imply money has been counted.
- **forecasting (P16).** A report describes what happened. It does not project.
- **traffic classification (P28).** No report may present a heuristic as
  invalid-traffic detection.
- **org-scoped analyst roles (P21).** P14 adds one capability; P21 scopes it.
- **actually sending scheduled email.** Named below as a non-goal with the
  reason.

## Entry criteria

- P13 is `[x]`, closed 2026-09-02: the projections are versioned, bounded,
  reconcilable and indexed, and their read shapes are asserted against `EXPLAIN`
  at the measured size.
- P10, P11 and P12 are `[x]`: the metrics a report names are no longer moving.
- The complete P0 regression baseline is green before implementation starts.

## Decision: the reporting day is UTC, and it says so

Both projections are keyed by `day_utc`, a `date`. There is no hour dimension
anywhere in the schema. A report cannot therefore be re-bucketed into the site's
timezone without either a schema change P13 did not make or an approximation
that shifts counts between days.

**So reports are in UTC, and every date, every range control and every export
header says UTC in words.** Not a tooltip, not a footnote on one screen.

This is a real limitation and it is written down rather than hidden: a publisher
in `America/Los_Angeles` asking for "yesterday" gets a UTC day that began at
17:00 local the day before. The alternative — hourly buckets, 24× the projection
rows, re-bucketed at read time — is a storage decision with a write-amplification
cost, and storage decisions belong to a schema phase that measures them. It is
not something a reporting phase should smuggle in to make a date picker read
better.

`Reporting_Rules::utc_day_keys()` already refuses anything outside 1–31 days and
already names UTC in its contract. The range work extends that; it does not
replace it with local dates.

## Decision: three absences, never conflated

The portal already distinguishes two of these and the rule has to hold across
every new surface, because the new surfaces are where it will be broken.

- **Zero** — measured, and the answer was none. Worth investigating.
- **Em dash** — nothing to divide by. No impressions, so no rate.
- **Not measured** — nobody was counting. Every day before P11 for `viewables`,
  every day before P12 for `conversions`, both `NULL` in the projection.

`Delivery_View_Data::format_viewability()` is the existing statement of this and
the new reports must not re-derive it somewhere else. **A comparison period
makes this worse and the rule stricter:** a range that starts before a metric
existed compares a number against `NULL`, and the honest output is "not
measured", never `-100%` and never `+∞`. A comparison that spans the boundary
must say which side is unmeasured.

## Decision: comparison is the immediately preceding window of equal length

Aligned to UTC day boundaries, same length, ending the day before the primary
range starts. No calendar-month special case, no "same period last year".

One rule and one reason: a comparison whose length differs from the primary
window produces a percentage that looks like performance and is arithmetic about
the calendar. Where a comparison window falls partly outside retention, the
report says the comparison is incomplete rather than comparing against a
truncated sum.

## Decision: freshness is part of every report, not a diagnostic

Three states, derived from `aggr_rollups_reconciled_through` and the clock:

- **Reconciled** — the day is at or before the watermark. Rebuilt from the
  ledger; rerunning the report next quarter gives the same number.
- **Provisional** — after the watermark. Live projection, correct in ordinary
  operation, not yet proven against the ledger.
- **Partial** — today, in UTC. Still accumulating.

A range spanning all three states reports the most cautious one that applies and
names the boundary day. A report that showed a partial day beside reconciled
days with no marking is the one way this phase can produce a number somebody
plans against and is wrong about, which is why it is a decision here rather than
a nicety later.

The watermark is also what bounds re-running: a closed day rebuilds exactly, so
"same numbers next quarter" is a property of the reconciler and this phase's job
is to *report which days have it*, not to re-assert it.

## Canonical model and ownership

No new durable data. Every number a report shows is already stored.

- **`Domain\Report_Period`** *(built)* — a bounded UTC range: its day keys, its
  equal-length comparison window, and its freshness against a supplied
  watermark. Pure, no WordPress, testable in milliseconds, which is the point —
  date arithmetic across boundaries is where reporting bugs live.

  **A period cannot exist unbounded.** `between()` and `ending()` refuse a
  malformed or over-long range rather than clamping it, because clamping
  answers a question nobody asked: the report renders, looks authoritative and
  covers a different period than the one requested. `trailing()` clamps, and
  the difference is who supplied the number — a request parameter is refused, a
  constant chosen in this codebase cannot usefully be refused at runtime.
- **`Domain\Report_Request`** *(later slice)* — scope, metric set and comparison
  flag around a period, for the surfaces that take a range from a request. It is
  the seam scheduled delivery would render through; it is not needed until
  something offers a range picker.
- **`Repository\Rollup_Report_Repository`** *(built)* — the org-scoped reads,
  split from `Rollup_Repository`, which keeps the schema, the live counter
  increment, the reconciler's rebuild and the pacing reads. Same table, two
  review standards: one half is judged on contention and hot-path query budget,
  the other on tenant isolation and range bounds.
- **`Workflow\Reporting_Read`** — stays the single gate. It already exists so
  that View_Data and REST cannot disagree about when zeros would be a lie; the
  range-aware reads go through it for the same reason, and it owns the default
  window so every surface on a page covers the same days.
- **`Repository\Rollup_Repository` and `Repository\Decision_Rollup_Repository`**
  — remain the only places the SQL lives. New read shapes are added there and
  nowhere else.

Reports are derived. Nothing here is authoritative over anything, no report
result is cached to a table, and no report writes.

## Invariants

The implementation must enforce:

- **Tenant isolation happens in SQL, before aggregation.** Against the frozen
  `org_id` P13 wrote, never a join to current campaign metadata, and never a
  filter applied to rows already summed.
- **The organization comes from the session, never the request.** `Report_Actions`
  already refuses an `org_id` parameter and states why; every new advertiser
  surface follows it. Staff scope by explicit capability, not by the absence of
  a check.
- **A report is read-only.** No report path writes, updates or deletes a row in
  any table, including "helpfully" reconciling a day it found provisional.
- **Every range is bounded** before it reaches SQL, and the bound is enforced in
  the domain object rather than by each caller clamping its own.
- **The reporting gate applies everywhere identically.** Reporting off means no
  tile, no field, no CSV row, no publisher screen — for the reason the export
  already documents: the bulk path is exactly where a site owner's decision to
  hide numbers would be walked around.
- **A metric that was never measured reports as unmeasured**, in every surface
  including CSV, where an empty cell and a `0` are read very differently by a
  spreadsheet.
- **House inventory is never attributed to an advertiser.** `campaign_id = 0`
  and `org_id = 0` stay out of org-scoped totals; a publisher report may show
  them and must label them as house.
- **Rerunning a report over reconciled days returns identical numbers.**

## Migration and compatibility contract

**No durable data changes.** No table is created, altered or dropped, no option
is added, and no migration runs. Rollback is reverting the code.

Two compatibility obligations instead:

- **The existing fixed-window reads keep working while ranges land**, or are
  replaced in the same change as their callers. `totals_for_org()`,
  `series_for_org()` and `daily_rows_for_org()` moved to
  `Rollup_Report_Repository` and took a `Report_Period` in the same change as
  `Delivery_View_Data` and `Report_Actions`; a partially converted portal that
  shows an all-time tile beside a ranged table is a reporting defect, not an
  intermediate state.

  **The dashboard tiles changed meaning, and say so.** They were all-time and
  are now the last 30 UTC days, which is a different number for the same label.
  The window and its timezone are printed beside them rather than left to be
  inferred — an unannounced change from lifetime to trailing-30 would read as a
  collapse in delivery.

  Campaign row totals in `attach()` stay lifetime, deliberately: that row is
  about a campaign rather than a period, and the read is bounded by that
  campaign's own schedule rather than by the organization's history.
- **The CSV column set may grow but existing columns may not change meaning or
  order.** Somebody has a spreadsheet pointed at column D. Adding conversions on
  the end is safe; inserting it after clicks is not.

## Workflows and API

Business operations, not controllers:

1. **An advertiser reads their own delivery over a range**, with comparison —
   portal dashboard and campaign detail. Session-scoped org, capability
   `ACCESS_PORTAL`, gate on Reporting, bounded range, no `org_id` input.
2. **An advertiser exports that range as CSV.** The existing `admin_post`
   handler extended: nonce, capability, gate, bounded window, sanitized
   filename, `nosniff`, formula neutralization through `Csv_Writer` — all of
   which it already does and none of which loosens for a longer column list.
3. **A publisher reads fill and no-fill for a placement or the site**, over a
   range, with reasons rendered through a label map and never raw. New admin
   screen, new capability.
4. **A publisher exports that.** Same rules as the advertiser export.
5. **A report's parameters round-trip** — the range in the URL, so a staff
   member can send a colleague a link to the same report and get the same
   numbers, subject to that colleague's own capability check on arrival.

**The new capability is `aggr_view_reports`, and it is new rather than borrowed.**
Reusing `REVIEW_CAMPAIGNS` would mean the first person who should see numbers
without approving creatives forces the split later, under pressure, in a
capability map that is already granted. P21 introduces an analyst role and will
need exactly one thing to scope; this is it.

REST: `GET /campaigns` and campaign detail gain conversion counts alongside the
impression and click fields they already carry, under the same gate. Whether the
publisher report gets a REST route at all is decided by whether anything reads
it — P13's rule, that a route without a reader is a surface to secure for
nobody, holds here.

Missing and foreign-tenant objects remain non-enumerating: a report for a
campaign in another organization is the same response as a report for a campaign
that does not exist.

## Scheduled delivery: the seam, and why nothing sends

The group contract asks for "the architecture for scheduled delivery". That is
satisfied by `Report_Request` being renderable without a browser and by the CSV
document builder already being a pure function of its rows — `Report_Actions::document()`
is public for exactly that reason.

**No scheduled email ships in this phase**, and the reason is
`notifications.md`: the existing mail path is per-recipient, duplicate-suppressed
and bounded-retry because a failed send must never reverse a workflow. A
recurring attachment-bearing report to a list of advertisers is a different
delivery problem — bounce handling, unsubscribe, attachment size, a schedule
that must not double-send after a missed cron — and building it as an appendix
to a reporting phase is how it would arrive without any of that. It is named
here as deliberately deferred so the next phase to want it knows it is picking
up a whole problem and not a checkbox.

What P14 must prove is only that nothing in the report path requires a request,
a session or a screen to produce its bytes.

## Security, privacy and abuse cases

- **Cross-tenant reads.** The threat is a report parameter that widens scope:
  an `org_id`, a campaign id belonging to somebody else, a placement filter that
  crosses organizations. Every scope input is validated against what the caller
  may already read, and the check happens before aggregation.
- **Export as the bulk path.** The CSV is the one surface that hands over
  everything at once. It keeps the nonce, the capability, the Reporting gate, the
  bounded window and the 404-not-403 behaviour for an absent surface.
- **Spreadsheet formula injection.** `Csv_Writer` neutralizes it and any new
  column goes through the same writer. A publisher export adds placement and
  reason labels, which are site-controlled rather than advertiser-controlled —
  lower risk, same treatment, because the writer is not the place to start making
  exceptions.
- **Resource exhaustion.** Range bounds are the defence: an unbounded range is a
  full-table aggregate that any logged-in advertiser could request repeatedly.
  The bound is enforced in the domain object, and the export builds in memory, so
  its bound stays the tighter of the two.
- **No new identifier.** Reports aggregate; nothing per-visitor is read, stored
  or rendered. `ip_hash` is never selected by a report query.
- **Never logged:** report parameters are safe, report *results* for another
  tenant are not; nothing may log a row set. No token, no raw IP.

## Failure, recovery and rollback

- **A projection behind its watermark is reported, not hidden.** The stated
  behaviour on a stale reconciler is: serve the provisional numbers, mark them
  provisional, name the watermark date. Not an error, not silence.
- **A missing table or a database error returns an explicit unavailable state**,
  distinct from zero. `Reporting_Read` already separates "off" from "zero"; this
  adds "could not be read", and the three stay distinct through to the template.
- **An export that cannot be assembled fails before any byte is sent**, so a
  browser never receives a truncated CSV that looks complete. Content-Length is
  already sent from the assembled string, which is what makes this possible.
- **Rollback is reverting the code.** There is no migration to reverse and no
  derived state to clean up.

## Performance and scale contract

- **Expected cardinality:** the P13 fixture — 1,000 placements × 400 days for
  decision counters — plus delivery rollups at placements × campaigns × line
  items × days.
- **Read budget, and this is the one that matters:** every report read is
  bounded by its range, and both new read shapes are asserted against `EXPLAIN`
  **by rows examined, not by the index the plan names.** P13 recorded why: an
  index rebuilt uselessly still appeared in the plan, because MySQL will use it
  for the `GROUP BY` while scanning every row for the `WHERE`. That guard
  reported success over an index it was no longer reading, and any assertion this
  phase writes inherits the lesson.
- **The dashboard's read must go down, and by how much must be recorded.** The
  unbounded `totals_for_org()` is the before-figure; the ranged replacement is
  the after. Both measured at the large fixture.
- **No new index without a measured query shape.** If a report needs one, it
  needs a schema change, which needs the measurement first — and P13's
  `dbDelta` rule applies: a key whose definition changes must be dropped
  explicitly in `install_table()` as well as in the migration, and a test
  asserting an old key is gone must recreate it first.
- **The fill path is untouched.** `DeliveryScaleTest`'s cold and warm budgets may
  not move. Reporting has no business on the serving path, and a report that
  warmed a cache the fill path reads would put it there.
- **Export budget:** bounded rows, one query per range, assembled once.

## Observability and operations

- The watermark, the projector versions present, and the freshness of the most
  recent day are readable by an operator without opening the database — Site
  Health already names projector versions; freshness joins it.
- A report that returned an unavailable state increments a counter surfaced in
  words, the way conversion refusals are. Approximate counts inform a
  description, never a status.
- `administration.md` gains what each report means and what UTC implies for a
  site not on UTC. `runbook.md` gains "the numbers look wrong": check the
  watermark, check the projector version, reconcile the day.

## Accessibility and internationalization

Two new screens' worth, and this is a table-and-chart phase, which is where
accessibility is usually lost:

- **The sparkline precedent holds:** any chart carries the same numbers in text
  or in an accessible table. A canvas or an SVG with no text equivalent is not
  shippable here.
- **No colour-only meaning.** Freshness, comparison direction and "not measured"
  each carry a word or a shape, not only a hue. A comparison arrow is an arrow
  *and* a sign.
- Range controls are labelled, keyboard-operable, and announce the resulting
  range; changing a range moves focus predictably and announces the update in a
  live region rather than silently swapping a table.
- Tables have real headers, a caption naming the range and its timezone, and
  reflow without horizontal scrolling at 320 CSS pixels.
- Every reason code renders through a translated label map — never the raw
  `No_Fill_Reason` string, exactly as `Conversion_Health` renders refusals.
- Numbers go through `number_format_i18n()`; dates through `wp_date()` where they
  are displayed and raw `Y-m-d` where they are machine-read, and the CSV uses the
  machine form.
- axe coverage on both new screens, in the existing suites.

## Required executable evidence

- **Pure domain tests** for `Report_Period`: range validation, comparison
  alignment, month and year boundaries, a range partly outside retention, and
  freshness classification against a watermark on each side of every boundary.
  No bootstrap.
- **Tenancy:** an advertiser's report cannot be widened to another organization
  by any parameter, asserted per surface including the export; and an org total
  computed over a range still comes from the frozen `org_id`.
- **The gate:** Reporting off leaves no tile, no REST field, no CSV row and no
  publisher screen. Asserted as absence *and* as a count, per surface.
- **Absence semantics:** a range before P11 reports viewability unmeasured, not
  0%; the same for conversions before P12; and a comparison spanning the boundary
  says so. This is the assertion that fails if somebody coalesces `NULL` to zero
  in a `SUM`.
- **Read budgets** by rows examined at the large fixture, for both the ranged
  delivery read and the decision-counter read, plus the recorded before/after for
  the dashboard.
- **The fill path budget is unchanged**, asserted as a number.
- **Reproducibility:** a report over reconciled days returns identical numbers on
  a rerun, and the same numbers after the day is reconciled again.
- **Export:** the bytes an advertiser receives, asserted through the public
  document seam; formula neutralization on a new publisher-controlled column;
  column order stable against the existing header.
- **Publisher report correctness:** requests = fills + reasons over a range, read
  through the production path, because that invariant is what makes every rate on
  the screen meaningful and it is asserted in P13 only at write time.
- **Browser, keyboard, reflow and axe** on both new screens.
- **REST contract** for the added conversion fields, including authorization and
  the gate.
- Multisite and site-deletion behaviour for any new option.
- The complete P0 baseline.

A test name is not evidence. A report test that builds its own rollup rows and
asserts them back tests SQL against a fixture; the ones that matter must reach
the projections through the production write path, for the reason
`DecisionPolicyInputsTest` exists.

## Documentation deliverables

`delivery-performance.md`, `rest-api.md`, `administration.md`, `runbook.md`,
`roles-and-capabilities.md` for the new capability, `accessibility.md` for the
chart and table patterns, `threat-model.md` if the export's threat surface
changes, `testing-strategy.md`, `portal-routing-and-ui.md`, and
`platform-implementation-progress.md`. `data-schema.md` only if this phase is
wrong about needing no schema change — in which case say so there and here.

## Exit criteria

The phase may move to `[x]` only when:

1. A publisher can see, for a placement and for the site over a chosen range,
   how often a slot was asked for, how often it filled, and the reasons it did
   not — read from `aggr_decision_rollups` through a production screen.
2. An advertiser can choose a range and get delivery, viewability and conversion
   numbers for it, with a comparison period, in the portal and in CSV.
3. Conversions are visible wherever impressions and clicks are, including
   `GET /campaigns`.
4. Every report states its timezone and its freshness, and a partial day is
   never presented as a settled one.
5. Zero, em dash and not-measured stay distinct on every surface, including
   comparisons that span a metric's introduction.
6. Tenant isolation is proven per surface, the export included, and the
   organization still cannot be chosen by the request.
7. Every report read is bounded by its range, measured by rows examined at the
   large fixture, and the dashboard's unbounded aggregate is gone with the
   before-and-after recorded.
8. The fill path's query budget has not increased.
9. Both new screens pass keyboard, reflow and axe, and no chart carries meaning
   only in pixels.
10. Required tests and the P0 baseline are green authoritatively.
11. Documentation describes what shipped.

The existence of a screen or a CSV column is not evidence for any of the above.

## Exit evidence and decision

[Complete at closeout. Record commands, counts, environment caveats, accepted
advisories and links to executable evidence. State the decision and why no
required behaviour remains unproven.]
