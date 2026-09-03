# Platform P14 reporting

P14 answers "what did this do, and can I hand the number to somebody?". P10
gave the lifecycle its vocabulary, P11 and P12 built two of its signals, and P13
made the storage bounded, versioned and reconcilable. P14 is where a person
finally reads it.

This document defines the work and its exit criteria. Where something has
shipped it is marked; everything unmarked is still a definition, not a claim.

## Status

- Phase: **P14 — Reporting**
- Roadmap state: `[x]`
- Closed out: 2026-09-02
- Authoritative environments: the Docker CI lanes, MySQL 8.4. Query plans and
  read budgets are decided there; the browser lanes decide the screens.

**What shipped:** `Report_Period` and `Report_Request`,
`Rollup_Report_Repository`, a ranged and freshness-aware `Reporting_Read`, the
dashboard reading a window the reader chooses and saying which one, conversions
visible everywhere impressions already were, a comparison against the preceding
window on every tile, and the publisher's fill report with its export — the
first reader P13's decision counters have ever had.

## Outcome

Three readers get an answer they cannot get today.

- **A publisher can see why a slot is empty.** *(Done.)* P13 stores it —
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
- **Anybody can see conversions.** *(Done.)* P12 shipped ingestion,
  deduplication, attribution and a definitions screen. `aggr_rollups.conversions`
  was written, reconciled and retained — and appeared in no tile, no table, no
  CSV column and no REST field. A feature was built, was running, and was
  invisible. It now surfaces on the dashboard tiles, the campaign list, campaign
  detail, `GET /campaigns` and the export.

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

**A count has two of these, not three**, and conflating that with the rate case
is the mistake available here. A rate needs a denominator, so "nothing to divide
by" is a distinct state; a count does not, so a measured zero is simply zero and
is a real, useful answer. Only "nobody was counting" has to be said in words.
`Delivery_View_Data::format_count()` is where that lives, beside
`format_viewability()`, which has all three.

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

**A rate's change is in percentage points; a count's is in percent.** *(Built.)*
CTR moving from 1.0% to 1.5% is a 50% increase and half a percentage point, and
"CTR up 50%" is read by nearly everybody as the wrong one of those. Counts take
`Reporting_Rules::change()`, rates take `point_change()`, and the two are
separate functions rather than one with a flag so a call site cannot pick the
wrong unit by omission.

**Three things have no comparison at all**, and each renders as nothing rather
than as a number:

- the previous window is unmeasured — a range starting before the metric
  shipped, where `-100%` would invent a collapse out of a release date;
- the current window is unmeasured, for the same reason in the other direction;
- the previous window was zero, because every change from nothing is infinite
  and the count itself already says what is worth knowing.

The rule lives in the pure function, not at the call sites, so a caller that
coalesced a null into a zero on the way in cannot reintroduce it.

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
  **`between()` is the only place a period is constructed.** `ending()`
  delegates to it and `previous()` delegates to `ending()`, so the bound is
  enforced once and two factories cannot end up disagreeing about whether a
  range is inclusive.
- **`Domain\Report_Request`** *(built, and smaller than planned)* — reads a
  range off a request and records whether one was refused.

  **It carries no scope and no metric set**, which the original sketch gave it.
  Scope never comes from a request: the advertiser's organization comes from the
  session and the publisher's screen is site-wide, so a scope field would have
  been a tenancy boundary reimplemented in a place nobody thinks to test. A
  metric set had no reader either — both screens show every metric they have.
  What was actually needed was one answer to "what range did they ask for, and
  was it usable", shared so two screens cannot disagree about it.
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

  **The CSV grew a column on the end.** `Conversions` follows `CTR %`; the six
  existing columns keep their positions and their meanings, because somebody has
  a workbook pointed at column D. An unmeasured day writes an empty cell rather
  than a `0` — a distinction every spreadsheet that opens the file will act on.

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
4. **A publisher exports that.** *(Built.)* Same rules as the advertiser export,
   and **long format** — one row per day and outcome — because a column per
   reason would change the column set the first time a new reason occurred.
   Each row carries the sentence for a reader and the code beside it for
   anything joining the file to something else.
5. **A report's parameters round-trip** — the range in the URL, so a staff
   member can send a colleague a link to the same report and get the same
   numbers, subject to that colleague's own capability check on arrival.

**The new capability is `aggr_view_reports`, and it is new rather than borrowed.** *(Built.)*
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
a session or a screen to produce its bytes. *(Proven.)* Both exports build their
document through a public, pure `document()` method taking rows and returning
bytes; `PublisherReportTest` calls one with no signed-in user and asserts the
same rows produce the same bytes twice. A later phase inherits a delivery
problem, not a report-generation one.

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
   numbers for it, with a comparison period, in the portal and in CSV. *(Done.)*
   Two date inputs and three preset links; the tiles, the chart, the caption and
   the export all resolve one request. A range that cannot be used is refused
   and said to be refused, never clamped into a report nobody asked for.
3. Conversions are visible wherever impressions and clicks are, including
   `GET /campaigns`.
4. Every report states its timezone and its freshness, and a partial day is
   never presented as a settled one.

   *Narrowed at closeout, for the exports only.* Both CSVs state the timezone
   in the column header and neither states freshness. A prose row in a data
   file breaks every parser pointed at it, and a freshness column would repeat
   one fact on every row — so the statement stays on the screen that offers the
   download, immediately beside the button. That is sufficient while a person
   downloads what they are looking at, and it stops being sufficient the moment
   a report is mailed to somebody who never saw the screen. Whichever phase
   builds scheduled delivery owns saying it in the message body; it is recorded
   here rather than discovered there.
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

Closed 2026-09-02. Every criterion was walked against tests rather than against
the code. One was narrowed and is marked above with its reasoning, because a
criterion quietly reinterpreted to fit what shipped is worse than one that was
never met.

**The inherited defect, measured before and after.** The advertiser dashboard's
first tile aggregated `aggr_rollups` for an organization with no date predicate
at all — every row it had ever produced, on every page load, bounded only by a
retention setting that defaults to keeping everything. At one year of a modest
advertiser's history the plan examined 12,775 rows where the ranged replacement
examines 1,500, and the ranged one is flat as the site ages while the other is
not. `ReportReadScaleTest` holds the line by explaining `$wpdb->last_query`
after calling the repository, so the plan it judges is the one production
issued.

**Three features existed and could not be seen**, which is the shape of defect
this phase was really about:

- P13's decision counters had no reader outside their own repository. The read
  methods existed and nothing called them.
- P12's attributed conversions were written, reconciled and retained, and
  appeared in no tile, table, CSV column or REST field.
- The reconciliation watermark had existed since P11 and no surface read it, so
  a partial day and a settled one looked identical.

**Five tests were written, watched to pass, and found to be worthless.** Four
were caught by attacking them; the fifth was caught by CI, which is the weakest
of the five ways to find one. All are recorded in `testing-strategy.md` or in
the code that carries them:

- A roles-upgrade test wound the stored version back to `Roles::VERSION - 1`,
  which always leaves the upgrader with work to do — so it proved the upgrader
  runs and never that this release asks it to, and it passed with the version
  bump reverted. It now pins the literal previous version.
- An administrator-capability test asserted `user_can()` against whatever the
  suite had already installed. Role definitions live in an option and a cached
  `WP_Roles`, so it passed with the capability removed from `primitives()`
  entirely. It now strips the capability, reinstalls and asserts it came back.
- A query-plan guard claimed both new export reads were bounded by the day
  range. Sabotage disagreed: filtered by placement, the unique key's leading
  column does the bounding and the day predicate barely matters. Only the
  site-wide read depends on the range, and that is where the assertion now has
  teeth — it fails at 79,980 of 79,980.
- A browser spec would have run against a screen showing "Reporting is switched
  off", because the module ships off. `seed-reporting.php` turns it on and
  seeds a spread that renders the reason table rather than the "every request
  was filled" path.
- A keyboard assertion pressed `Tab` once from a fresh document and checked that
  focus had landed inside the screen. wp-admin puts a skip link, the admin bar
  and the whole admin menu ahead of the content, so it never does — and the
  assertion would have said little even where it passed, because "something is
  focused" is true of almost any page. It now walks the tab order and names each
  stop, which fails on a reordered DOM or an introduced `tabindex`.

**And the second of those was covering a real defect.** `aggr_view_reports` was
granted to the reviewer role and listed in the staff menu map but left out of
`Capabilities::primitives()`, which is how an administrator is granted anything.
The site owner could not have opened the screen. Every reviewer-based test
stayed green; the pinned primitive list in `CapabilitiesTest` is what caught it.

**Deliberately smaller than specified**, and marked above: `Report_Request`
carries no scope and no metric set, because scope never comes from a request
and no surface shows a subset of its metrics; the publisher report gets no REST
route, because a route without a reader is a surface to secure for nobody; and
no scheduled email ships, because the seam is what this phase owed and delivery
— bounces, unsubscribes, attachment size, a schedule that must not double-send
— is a whole problem rather than an appendix.

**The browser specs were written without ever being run**, because the suite
needs Docker or a Studio site it mutates irreversibly and neither was available.
CI executed them first: twenty-seven of twenty-eight passed, and the one that
failed was the keyboard assertion above rather than anything about the screen.
That is the acceptable version of this trade and not a repeatable one — the
specs that matter most are the ones nobody can run locally.

**Environment caveat.** Docker was unavailable on the authoring host throughout,
so the WordPress suites ran natively against MySQL 8.0.46 and PHP 8.5.6 rather
than CI's pinned 8.4/8.4, and the browser lanes never ran locally at all. CI is
the authority for every figure above that came from a query plan. One local-only
flake was diagnosed and is worth knowing: this plugin's custom tables are not
rolled back between tests, so rows accumulate across runs on a developer machine
and an unrelated upgrade test began failing on leftovers. CI starts from a fresh
database and is unaffected.

Green at closeout across #169, #170, #171, #172, #173 and #174, every lane, on
pinned MySQL 8.4 and PHP 8.4.
