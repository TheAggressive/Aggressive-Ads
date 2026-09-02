# Platform P13 event and analytics schema

P13 answers "what happened, and can we still say so next quarter?". P10 gave the
lifecycle its vocabulary — `request`, `fill`, `no_fill`, `served`, `viewable`,
`click`, `conversion` — and P11 and P12 built two of those signals. P13 is where
the *storage* catches up with the vocabulary, so P14 has bounded projections to
read and P6 has counters it can pace against.

This document defines the work and its exit criteria. It does not claim that P13
has started or that any item below is implemented.

## Status

- Phase: **P13 — Event and analytics schema**
- Roadmap state: `[x]`
- Closed out: 2026-09-02
- Authoritative environments: the Docker CI lanes. CI pins MySQL 8.4; schema and
  `dbDelta` behaviour there decides disagreements, and this phase is almost
  entirely schema.

## Outcome

A publisher can ask why a slot is empty and get an answer. An advertiser's
report reruns to the same numbers next quarter as it did today. Both hold while
the ledger grows, because reports read bounded projections rather than scanning
history.

Concretely, three things become true that are not true now:

- **`request`, `fill` and `no_fill` are recorded**, with the structured reason
  the decision engine already computes and currently discards.
- **Rates have denominators.** Fill rate is `fills / requests`; today only
  successes are stored, so the plugin can say how often it succeeded and never
  how often it was asked.
- **History keeps its meaning.** A campaign renamed, or moved between
  organizations, does not change what last quarter's report says.

## Entry criteria

- P10 is `[x]`: the event vocabulary and lineage are stable.
- P11 and P12 are `[x]`: the event shapes P13 versions are no longer moving.
  P12 closed 2026-09-01; view-through remains deferred behind P27 and is not a
  shape this phase must accommodate.
- The complete P0 regression baseline is green before implementation starts.

## The defect this phase inherits

`Workflow\Decision_Metrics` is the current answer to "why is nothing serving",
and it is not one that survives contact with scale. Every decision that excludes
anything does `get_option()`, mutates a nested array and `update_option()`s the
whole thing back — on the public, unauthenticated fill path.

Measured on the integration suite, seeding six reasons per placement:

| placements | serialized option |
|---|---|
| 10 | 2.5 KB |
| 100 | 23 KB |
| 500 | 114 KB |
| 1,000 | 228 KB |

Growth is linear in placements and unbounded in time — nothing prunes it. At a
thousand placements every ad decision unserializes 228 KB, edits it, and writes
228 KB back. The full reason taxonomy is 26 codes rather than the six seeded
here, so the realistic figure is several times that.

It is also lossy in exactly the way the conversion refusal counters were found
to be: read-modify-write means concurrent decisions overwrite each other's
increments, so the numbers are already approximate and silently low under the
load that makes them interesting.

**P13 replaces it.** The counters move to a bounded, indexed, contention-safe
table, and the option is dropped by the migration.

## Scope boundary

This phase owns:

- schema and projector versioning on the ledger and its projections;
- the fact/dimension split, and freezing the dimensions history depends on;
- durable storage for `request`, `fill` and `no_fill` with structured reasons;
- indexes derived from the query shapes P14 and P6 actually issue;
- the staged migration onto the highest-volume tables in the schema; and
- retirement of `Decision_Metrics`' option-backed counters.

This phase does not own:

- **report semantics, date ranges, comparison periods or CSV (P14).** P13
  produces projections; it does not decide what a report means.
- **pacing behaviour (P6).** P13 exposes the counters; P6 decides what to do
  when a goal is behind.
- **traffic classification (P28).** P13 must preserve the reason metadata and
  the raw-versus-valid separation P28 will need, and must not present any
  heuristic here as fraud detection.
- **new identifiers of any kind.** No cross-visit identifier is introduced; that
  remains P27's gate.

## Canonical model and ownership

### The fact/dimension split

The question this phase turns on. Every column is one of two things, and the
distinction decides whether history is stable:

**Durable fact** — resolved once, at write, and never re-derived. The event's
own identity: what was decided, for whom, when, and why.

- `placement_id`, `campaign_id`, `line_item_id`, `creative_id`
- **`org_id`** — see below
- event type, reason code, occurrence and receipt timestamps
- `schema_version`

**Re-resolvable dimension** — held by id and looked up for display: campaign
name, placement name, organization name, creative alt text. These are expected
to change and a report should show today's name. Storing the id and resolving
the label is correct; storing the label is not.

**`org_id` moves from the second group to the first, and that is the change.**
Today an org total is produced by joining `_aggr_org_id` off the campaign at
read time. That makes tenancy a *current* fact about the campaign rather than a
historical fact about the delivery: move a campaign between organizations and
last quarter's totals move with it, in both directions, with nothing recording
that they did. Tenancy decides who may read a number, so it is the one label
that cannot be re-resolved.

### Ownership

- `aggr_events` remains the append-first durable truth. Nothing updates a row.
- `aggr_rollups` and the new decision counters are **reproducible projections**.
  Their authoritative writer is the projector; the reconciler may rebuild any
  closed day from the ledger and must arrive at the same numbers.
- A projection carries the `projector_version` that produced it, so a rebuilt
  day and a live-projected day are distinguishable without guessing.

## Decision: how `request`, `fill` and `no_fill` are stored

**Not as ledger rows.** These are per *opportunity*, not per fill. A page with
six slots produces six requests on every pageview, filled or not, which is
several times the current write volume on the busiest path in the plugin. The
contract requires write amplification be measured rather than assumed, and the
measurement says a row per opportunity is the wrong trade for a number nobody
reads per-row.

**As a per-placement, per-day, per-outcome counter**, in one table:

```
aggr_decision_rollups
  day_utc       date
  placement_id  bigint unsigned
  outcome       varchar(32)   -- 'request', 'fill', or a No_Fill_Reason code
  events        bigint unsigned
  UNIQUE KEY (placement_id, day_utc, outcome)
```

`outcome` carries either a lifecycle name or a structured reason, because the
reader's questions — "how often was this slot asked for", "how often did it
fill", "when it did not, why" — are one grouped read over one table rather than
a join across three. The longest reason code is 19 characters; `varchar(32)`
leaves room without approaching the truncation trap that `wp_posts.post_status`
sets at 20.

Three properties follow from the shape, and each is a thing the option cannot do:

- **Contention-safe.** `INSERT … ON DUPLICATE KEY UPDATE events = events + ?`
  is atomic at the database, so concurrent decisions cannot lose each other's
  increments the way a read-modify-write does.
- **Bounded.** Rows are placements × days × outcomes, and retention prunes days
  the way it already prunes events. The option is bounded by nothing.
- **Reproducible.** Per-day rows can be rebuilt for a closed day; a single
  running total cannot be rebuilt at all.

**Buffered per request, flushed once on `shutdown`.** A six-slot page performs
one write, not eighteen — the pattern `Workflow\Conversion_Metrics` already
established here, for the same reason: the cheapest path is the one an
anonymous caller can repeat, so it must not grow with what the page asks for.

## Invariants

The implementation must enforce:

- **Tenancy is historical.** An org total is computed from the `org_id` frozen
  on the row, never from a join to current campaign metadata. A campaign that
  changes organizations does not move historical numbers.
- **Append-only means append-only.** No code path updates or deletes an
  `aggr_events` row outside retention, and retention never crosses the
  reconciliation watermark.
- **A projection is reproducible.** Rebuilding a closed day from the ledger
  produces byte-identical counters, and doing it twice changes nothing.
- **Version fields are written, never inferred, and read by something.** A row
  without a `schema_version` is a row from before the migration and reads as 0,
  which is the honest answer rather than a guess. `projector_version` is
  surfaced by the delivery Site Health check, which names every version present
  in the projection — that reader is what keeps the column from becoming
  decorative, the way `allow_s2s` was stored and validated for weeks while
  nothing set it.

  **What this phase deliberately does not do is branch on a version at
  runtime.** The original invariant said an unknown future version must "fail
  explicitly rather than being guessed at", and that was written before there
  was anything to fail against. With one version in existence the correct
  behaviour is undecidable: a reconciler that refuses rows from a newer
  projector would, after a rollback, stop rebuilding closed days silently and
  nightly — plausibly worse than the drift it prevents. This contract requires
  a *stated* failure behaviour, and choosing one with no real second version to
  test against would be a guess wearing a guard's clothes.

  **It belongs to the phase that first bumps a version**, which will know what
  changed and therefore what recovery means. Until then, mixed versions are
  visible in Site Health and reprojection is the operator's answer.
- **No new identifier.** Nothing here stores an IP, a cross-visit id, or any
  visitor-identifying value. `ip_hash` remains a salted daily HMAC.
- **A decision never fails because measurement did.** Counter writes are
  best-effort and happen after the payload is decided; a failed counter write is
  recorded and never propagated to the caller.
- **The migration is restartable.** Interrupted at any point it resumes from its
  cursor, and no row is counted twice.

## Migration and compatibility contract

Two migrations, deliberately separate, because they carry different risk.

**Migration A — additive.** Creates `aggr_decision_rollups`, adds
`schema_version` to `aggr_events`, `projector_version` and `org_id` to
`aggr_rollups`. New columns are nullable or defaulted; no existing row changes
meaning. This is a schema change with no data rewrite and can ship on its own.

**Migration B — the backfill.** *Reversed during implementation, and the
reasoning is worth keeping because the original plan was wrong twice over.*

This was specified as a staged migration: bounded batches, a stored cursor,
resume after interruption, Site Health progress, a partial-migration read
fallback and rollback. It shipped as **one idempotent `UPDATE`** with none of
that. Two things changed the answer.

**A backfill cannot recover history, so there is less to protect than it
looks.** Nothing ever recorded which organization owned a campaign last month.
The statement can only write *today's* answer onto older rows — which is
exactly what the read-time join it replaces already returned. The value of the
column is that tenancy stops moving from here, not that the past becomes
knowable, and elaborate staging to protect a value that is not history is
ceremony.

**Emptying and reprojecting was considered and rejected.** It looked clean —
`aggr_rollups` is a projection and `reconcile_day()` rebuilds any closed day
exactly from the ledger — and it is wrong, because this table is also the
**pacing and frequency counter**. Clearing it resets every live cap, and a
campaign whose counter restarts from nothing overdelivers for the rest of the
day. `ReleaseUpgradePathTest` already named that consequence for the same table
one migration earlier; the plan here would have reintroduced it.

What remains is `Rollup_Repository::backfill_org_ids()`: one `UPDATE ... JOIN`
predicated on `org_id = 0`. The predicate *is* the cursor — an interrupted run
resumes by being run again, a second run does nothing, and a site with no
unattributed rows does no work. There is no partial state to read around,
because a row is either attributed or waiting and reads of an unattributed row
were already impossible.

Rollback drops the added columns; the ledger is untouched throughout.

**`dbDelta` adds an index and never drops one.** Any key whose definition
changes must be dropped explicitly in `install_table()` as well as in the
migration, so a repair install heals a site the upgrade missed — and a test
asserting an old key is gone must recreate it first, or it passes over a
migration that does nothing.

The `Decision_Metrics` option is deleted by Migration B rather than left behind.
Its data is not backfilled: it is an unbounded running total with no time
dimension, so there is no day to attribute it to. That loss is stated here
rather than discovered later.

## Workflows and API

No new public surface. The decision path gains a buffered counter write; the
reconciler gains a second projection to rebuild; retention gains a second table
to prune. Site Health gains migration progress.

P14 will read these tables. P13 exposes them through repositories only —
`Repository\Decision_Rollup_Repository` — and adds no REST route, because a
route without a report to serve is a surface to secure for no reader.

## Security, privacy and abuse cases

- **The counter write is on an unauthenticated path.** It must be bounded per
  request regardless of how many slots a page declares, which is what the
  shutdown buffer guarantees. A page asking for a hundred slots writes once.
- **Reason codes are a closed set.** `No_Fill_Reason::all()` bounds what may be
  written, so no caller can grow the table's cardinality by inventing an
  outcome, and no reader can print a reason the interface has no label for.
- **No identifier is added.** The counters are per placement and per day and
  carry nothing about who saw what.
- **Tenant isolation is applied in SQL before aggregation**, against the frozen
  `org_id`. A screen that filtered after aggregating would have already read
  another tenant's rows.
- **Never logged:** nothing here may log a token, a raw IP or a visitor value;
  placement, decision and event ids are correlatable without any of them.

## Failure, recovery and rollback

- **Counter writes are best-effort and last.** The decision is made and the
  payload returned regardless. A write failure increments a failure count and is
  visible in Site Health; it never reaches the caller and never rolls back a
  fill.
- **Projection failure is retryable.** The reconciler rebuilds closed days from
  the ledger, so a lost projection window is recoverable as long as the ledger
  covers it — which is why retention must not delete past the watermark.
- **Migration failure is resumable**, from the stored cursor, with the failed
  batch recorded rather than skipped silently.
- **Rollback** drops the added columns and the new table. The ledger is
  untouched by design, so rollback cannot lose history.

## Performance and scale contract

- **Measured cardinality.** `DecisionRollupScaleTest` builds 1,000 placements ×
  30 days and counts what a realistic outcome spread produces:

  | | |
  |---|---|
  | rows at 1,000 placements × 30 days | 79,980 |
  | rows per placement-day | 2.67 |
  | projected at 400-day retention | ~1.07M |

  The pessimistic ceiling — a row for every storable outcome — would be about
  11M. The realistic figure is an order of magnitude below it because **only
  outcomes that actually occur are written**: a placement that always fills
  costs two rows a day, not twenty-eight. The test asserts that ratio stays
  under 4.0, so a change that began recording outcomes which did not happen
  fails rather than quietly multiplying the table.

  Bytes are deliberately not recorded. `SHOW TABLE STATUS` reports an InnoDB
  estimate, and the suite runs inside a rolled-back transaction, so any figure
  taken there would be wrong in a document somebody later plans capacity from.
  Row counts are exact because they are counted.
- **Write budget:** **one** upsert statement per request for the decision
  counters, independent of the number of slots on the page. Not "one per slot".
- **Hot-path budget:** the existing cold and warm fill budgets in
  `DeliveryScaleTest` may not increase. The option read and write this phase
  removes should make them go *down*; the test must be updated to the new
  numbers deliberately, and never loosened to accommodate a regression.
- **Report read budget:** bounded by date range and placement. Both shapes are
  asserted against `EXPLAIN` at the measured size: the per-placement read (the
  publisher asking why a slot is empty) uses `slot_day_outcome`, and the
  site-wide day read uses `day_outcome`.

  **Naming the index in the plan is not the assertion.** Rebuilding
  `day_outcome` as `(outcome)` alone — useless for a day range — left the plan
  still naming it, because MySQL will use an index for the `GROUP BY` while
  scanning every row for the `WHERE`. The guard reported success over an index
  it was no longer reading. Both assertions therefore check **rows examined**,
  which fails at 79,996 of 79,980 when either key stops leading on the column
  its query filters by.
- **Large fixture:** 1,000 placements and 400 days of counters, which is the
  shape that made the option's cost visible in the first place.

## Observability and operations

- Site Health reports migration state: not started, running with a cursor
  position, complete, or failed with the failing batch named.
- Counter write failures are counted and surfaced in words, the way conversion
  refusals are — approximate counts inform a description, never a status.
- The reconciliation watermark and projector version are readable, so "is this
  day final" is answerable without inspecting rows.
- Table sizes are reported, because this phase's whole risk is growth.

## Accessibility and internationalization

No new UI. The Site Health additions must state their condition in words rather
than a bare number, carry no colour-only meaning, and be translatable — reason
codes are never rendered raw, exactly as `Conversion_Health` renders refusal
reasons through a label map.

## Required executable evidence

- **Pure domain tests** for the outcome vocabulary and the reason mapping, with
  no bootstrap.
- **Schema and index tests against real MySQL**, asserting the declared columns
  and keys, and asserting the unique key is `UNIQUE` rather than merely present.
- **Concurrency:** two increments of the same `(placement, day, outcome)`
  arriving from state this process did not write still total two. This is the
  assertion that fails for the option and passes for the table, and it is the
  reason the table exists.
- **Write budget:** a page declaring many slots performs one counter write.
  Asserted as a **query count**, because the number is the thing that regresses.
- **Hot path:** the fill query budget after this phase, asserted as a number.
- **Migration:** fresh install, real-version upgrade, interrupted-and-resumed
  backfill, rollback, and a partial migration serving correct reads.
- **Tenancy:** an org total computed from frozen `org_id` does **not** change
  when the campaign is moved to another organization. This is the assertion that
  proves the fact/dimension decision actually shipped.
- **Reproducibility:** reconciling a closed day twice changes nothing, and
  rebuilding from the ledger matches the live projection exactly.
- **Retention:** bounded deletes that never cross the watermark, asserted as a
  count and with the negatives — what retention must *not* touch.
- Multisite, new-site and site-deletion behaviour for the new table.
- The complete P0 baseline.

A test that asserts a counter incremented in the same process that wrote it
proves arithmetic. The counter tests must go through the production decision
path, for the reason `DecisionPolicyInputsTest` exists: a stage that reads a key
nothing puts there passes every hand-built fixture.

## Documentation deliverables

`data-schema.md`, `delivery-performance.md`, `architecture.md` if a boundary
moves, `administration.md` and `runbook.md` for the migration and the health
check, `testing-strategy.md`, `threat-model.md` if the counter path changes what
is stored, and `platform-implementation-progress.md`.

## Exit criteria

The phase may move to `[x]` only when:

1. `request`, `fill` and `no_fill` with structured reasons are recorded through
   the production decision path, and the reason a slot stayed empty is stored
   and queryable.

   *Narrowed at closeout.* This originally said "a publisher can **see** why a
   slot is empty", which contradicts this phase's own scope boundary: report
   semantics are P14's and this phase deliberately adds no screen and no REST
   route, on the grounds that a route without a report to serve is a surface to
   secure for no reader. The data exists, is bounded, indexed and reconcilable;
   showing it is the next phase's job.
2. Fill rate is computable from stored counters, with its denominator defined.
3. `Decision_Metrics`' option is gone, its replacement is proven
   contention-safe, and the fill query budget has not increased.
4. Historical tenancy is frozen, proven by a campaign that changes organization
   without changing history.
5. Projections are versioned, readable and reproducible; a closed day rebuilds
   exactly, and which projector wrote a day is answerable without opening the
   database. Runtime behaviour on an unknown *future* version is explicitly out
   of scope — see the invariant above.
6. The migration is proven idempotent, resumable and reversible.

   *Narrowed at closeout, because the staged design was reversed during
   implementation.* Batches, a stored cursor, pause/resume and Site Health
   progress were specified for a long-running backfill that no longer exists:
   the attribution collapsed to one `UPDATE` predicated on `org_id = 0`, where
   the predicate is the cursor. Resumability is therefore *re-running it*, which
   `RollupTenancySchemaTest` asserts, and there is no progress for Site Health
   to report because there are no batches. Reversibility is the additive schema:
   dropping the columns loses nothing, since the ledger is untouched throughout
   and every projection value is rebuildable from it.
7. Growth is measured at the large fixture and bounded by retention.
8. Required tests and the P0 baseline are green authoritatively.
9. Documentation describes what shipped.

The existence of a table or a migration is not evidence for any of the above.

## Exit evidence and decision

Closed 2026-09-02. Every criterion was walked against tests rather than against
the code, and two were narrowed rather than ticked — both are marked above with
the reasoning, because a criterion quietly reinterpreted to fit what shipped is
worse than one that was never met.

**Three defects were found by building this, none of them by a failing test.**
All three had green suites over them:

- **`decide_page()` recorded nothing at all.** The batch path runs through a
  pure-domain coordinator that may not call a WordPress function, so counters
  existed on the single-slot path and not the batch one. "Why is this slot
  empty" had an answer that depended on which code path the request took.
- **House fills were credited to an advertiser.** Resolving the organization
  through the *assignment's* campaign meant a house fill — `campaign_id = 0`,
  still matching an assignment owned by somebody — landed in that advertiser's
  totals.
- **Conversion-only rollup rows were unattributed.** A conversion counts on the
  day the outcome happened, so it can create a row for a day this site served
  nothing; that row had no delivery behind it to freeze tenancy, and no
  org-scoped report could see the conversion it counted.

**Two guards this phase wrote did not work until they were attacked.** Both are
recorded in `testing-strategy.md`:

- An `EXPLAIN` assertion checked which index the plan *named*. Rebuilding
  `day_outcome` as `(outcome)` alone — useless for a day range — left the plan
  still naming it, because MySQL will use an index for the `GROUP BY` while
  scanning every row for the `WHERE`. It now asserts rows examined, and fails at
  79,996 of 79,980.
- A projector-version test read `(0, 1)` for a single seeded row, because the
  suite's tables are shared and other classes seed rows predating the column.
  The fixture is now emptied and asserted empty first.

**Measured, not assumed:** 79,980 rows at 1,000 placements × 30 days, 2.67 per
placement-day, ~1.07M projected at 400-day retention — an order of magnitude
under the 11M ceiling, because only outcomes that occur are written. The
counters cost **no query** on the fill path, asserted in `DeliveryScaleTest`
because the budgets there are ceilings with headroom an inline write could hide
under.

**Deliberately not built**, and owned by later phases: any screen reading these
counters (P14), and runtime behaviour on an unknown future schema or projector
version — undecidable with one version in existence, and owned by whichever
phase first bumps one.

Green at closeout: 1,328 PHP and 7 multisite locally, all CI lanes on pinned
MySQL 8.4 and PHP 8.4 across #159, #162, #163, #165 and #166.
