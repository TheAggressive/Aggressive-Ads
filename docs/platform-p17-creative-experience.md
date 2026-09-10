# P17 — Creative experience

## Status

- Phase: **P17 — Creative experience**
- Roadmap state: `[ ]` — slices 1 and 2 of 4 built
- Last audited: 2026-09-06
- Authoritative environments: CI's pinned MySQL 8.4 / PHP 8.4 lanes

This document records planned work. It does not claim completion.

## Outcome

An advertiser can run more than one version of an advertisement and find out
which one worked. A reviewer can see what was approved, what was rejected and
why, without reading an audit log. Neither can change what was already reviewed.

## What already exists, and why this phase is smaller than its name

**Variants are built.** An assignment carries a weight between
`Assignment_Rules::MIN_WEIGHT` and `MAX_WEIGHT`, a schedule window and a status;
`candidates_for_placement()` returns up to a hundred of them for one placement;
and `Domain\Weighted_Selection` picks among the survivors deterministically from
a seed. Two creatives on one placement with weights 3 and 1 already rotate three
to one, today, with no work from this phase.

So P17 does not build variant delivery. It builds the two things missing around
it: **the ability to tell which variant worked**, and **a surface to manage and
review them**. Naming that honestly at the start is what stops the phase
rebuilding a mechanism it already has.

### The measurement gap, precisely

**There are two rollup tables, and only one of them is the right one.** Getting
this wrong sends the migration to the wrong place, so it is written out here
rather than left to be rediscovered:

| Table | Grain | Holds |
|---|---|---|
| `aggr_decision_rollups` | day, placement, outcome, opportunity | request, fill, and every no-fill reason |
| `aggr_rollups` | day, placement, campaign, line item, org | impressions, clicks, viewables, conversions |

Variant *performance* is impressions, clicks and conversions, so **`aggr_rollups`
is the table this phase changes**. `aggr_decision_rollups` stays as it is: a
request has no creative — nothing has been chosen yet — so a creative dimension
there could only ever describe fills, and a per-creative fill *rate* has no
denominator to be a rate against.

`aggr_events` already records `creative_id` on every impression and click, so
the facts exist; they are simply not projected. Comparing two variants by
querying that ledger directly is a scan whose cost grows with history, which is
exactly what the rollup exists to avoid. **The creative dimension has to reach
`aggr_rollups` before any comparison surface is honest.**

Two consequences the slice must carry rather than discover:

- **Row growth.** `UNIQUE KEY slot_line_day (placement_id, campaign_id,
  line_item_id, day_utc)` gains a creative, multiplying rows by the number of
  creatives serving a placement. Measured on a seeded fixture, not assumed.
- **The old unique key must be dropped explicitly.** `dbDelta` adds an index and
  never drops one, so `slot_line_day` survives the upgrade and goes on enforcing
  one row per line item per day — silently collapsing every variant back into
  one. The drop belongs in the migration *and* in `install_table()`, and the
  test asserting it is gone has to recreate it first, because a fresh table
  never had it and the assertion would pass over a migration that does nothing.

## Scope boundary

This phase owns:

- per-creative measurement, from the projector through to a comparison surface;
- variant management — creating, weighting, scheduling and retiring assignments
  through a screen rather than through the REST route by hand;
- approval and rejection history over revisions, readable without the audit log;
- device preview of a revision.

This phase does not own:

- new creative formats — responsive images, HTML5, third-party tags, video are
  P18, behind the handler boundary P2 defined;
- forecasting which variant will win (P16), or pricing them differently (P19);
- changing what a revision is. See the invariants.

## Canonical model and ownership

No new entity. This phase changes one projection and adds no post type.

| Fact | Authoritative writer | Mutable |
|---|---|---|
| Revision bytes, click URL, alternative text | `Creative_Manager` at upload | **No.** Approval applies to exact bytes. |
| Assignment weight, window, status | `Creative_Assignment_Repository` | Yes — this is all an assignment owns. |
| Per-creative counters | the P13 projector | Append-only, by increment |
| Review decision and reason | `Creative_Change_Manager` | Append-only |

**The projection is the only denormalization this phase adds.** `aggr_rollups`
— the delivery-metric table, not `aggr_decision_rollups` — gains a creative
dimension; `aggr_events` already carries `creative_id` and remains the record
the projection is derived from. What synchronizes them is the
existing projector, and the reconciliation evidence below is what proves it
stayed synchronized.

A variant is not a new kind of thing. It is a second assignment pointing at a
second revision — which is why nothing here may edit a revision to create one.

## Invariants

- **An experiment never mutates a reviewed revision.** Approval applies to exact
  bytes; a variant is another assignment pointing at another revision, never an
  edit to one that was approved.
- **Preview is untrusted rendering** and uses the same isolation as delivery or
  stricter. A reviewer's browser is not a safer place to run a creative than a
  visitor's.
- Assignment weight, window and status stay the only delivery scheduling an
  assignment owns. Bytes, click URL and alternative text remain revision facts.
- Variant selection stays deterministic: the same seed, candidates and weights
  choose the same winner. An experiment that cannot be replayed cannot be
  audited.
- A rejection is as durable as an approval. Deleting the record of why something
  was refused is how the same creative gets resubmitted forever.
- Per-creative counters reconcile with per-placement counters: a placement's
  totals equal the sum of the creatives that served it, plus whatever served
  under no creative.

## Migration and compatibility contract

Durable data changes: `aggr_rollups` gains a creative column and a changed
unique key, so this needs a migration and the rules that go with one.

- **Source and destination.** Existing rows are per-placement totals with no
  creative attribution. They must survive as they are; history recorded before
  the dimension existed cannot be attributed retroactively and must not be
  guessed at.
- **Identity and history preserved.** No existing row is rewritten. A row with
  no creative attribution means "recorded before this shipped", and every reader
  must render that as unattributed rather than as zero — the same distinction
  the utilisation view already draws for deleted placements.
- **Partial or failed migration.** Reads fall back to the placement grain, which
  is what every current screen already uses, so a half-applied migration
  degrades to today's behaviour rather than to a broken screen.
- **`slot_line_day` must be dropped explicitly.** `dbDelta` adds an index and
  never drops one, so the old unique goes on enforcing one row per line item per
  day and collapses every variant into one. Dropped in the migration *and* in
  `install_table()`, so a repair install heals a site the upgrade missed.
- **Retirement condition.** The compatibility read goes when no row predating
  the dimension remains inside the longest reporting window offered.

No user recreates valid existing data. Old counters keep counting.

## Workflows and API

| Operation | Capability | Ownership check |
|---|---|---|
| Add a variant to a line item | `aggr_upload_creative` | organization owns the campaign |
| Change weight, window or status | `aggr_upload_creative` | organization owns the assignment's campaign |
| Compare variants over a window | `aggr_view_reports` | site-wide figures only |
| Read approval and rejection history | `aggr_review_campaigns` | reviewer sees every tenant; an advertiser sees their own |
| Preview a revision | `aggr_review_campaigns` **or** owner | parent revision verified before render |

Every write verifies the parent id, then organization ownership, then
capability. A variant belonging to another tenant is a 404, never a 403 — the
distinction leaks whether the id exists.

## Security, privacy and abuse cases

- **Tenant crossing.** A variant is addressed by assignment id; the id alone
  must never authorize. Same object check as every other assignment write.
- **Preview is untrusted rendering.** A reviewer's browser is not a safer place
  to execute a creative than a visitor's, so preview uses delivery's isolation
  or stricter. This is a contract requirement, not a preference.
- **Experiment results are the publisher's and the tenant's.** An advertiser
  sees their own variants' figures. Cross-tenant comparison is staff-only, on
  the same reasoning P16's forecast audience was decided.
- **Resource exhaustion.** `candidates_for_placement()` already bounds to a
  hundred; a comparison screen must bound its own window and creative count
  rather than inheriting an unbounded scan of the event ledger.
- **Never logged.** Revision bytes, private storage paths, reviewer identity in
  advertiser-facing responses.

## Failure, recovery and rollback

- **Durable first.** A counter increment is durable before any surface reads it;
  the projector already works this way and gains no new ordering.
- **A projector outage fails closed for comparison and open for delivery.**
  Variants keep rotating on weights, which need no counters. The comparison
  surface says figures are stale rather than rendering a number it cannot
  stand behind — the same choice the freshness note already makes.
- **Rollback.** The added column is additive; reverting the readers leaves the
  data unread rather than unreadable.

## Performance and scale contract

- **Expected cardinality.** `aggr_rollups` rows multiply by the number of
  creatives serving a placement. A placement with six live variants produces six
  times the rows it does today. **This figure is the slice's headline risk and
  must be measured on a seeded multi-creative fixture, not assumed.**
- **Hot-path budget.** Unchanged. Delivery reads assignments, not counters, so
  the fill path takes no new query.
- **Write budget.** One additional grain per buffered flush; the flush is
  already one batched insert.
- **Cache.** Comparison figures are windowed reads, cached per window and
  placement, invalidated on projection.
- **Large fixture.** A placement with six variants across ninety days, which is
  the shape that makes the row multiplication visible.

Indexes follow the query shape: comparison reads one placement over a window, so
the key leads on placement and day exactly as the current unique key does.

## Observability and operations

- Migration state visible in Site Health, as the earlier backfills are.
- The reconciliation between per-creative and per-placement totals is a health
  signal, not only a test: a drift means the projector lost a dimension.
- Runbook gains one action — how to read a comparison whose figures predate the
  dimension.
- Never logged: bytes, storage paths, tokens.

## Accessibility and internationalization

A comparison of two things is a table, and a table needs headers that name what
they compare. Figures carry their unit; a rate with no denominator on screen
stays an em dash rather than a zero. Every string reaches the browser through
PHP, because Script Modules carry no translations below WordPress 7.0 and this
plugin's floor is 6.7 — see `known-issues.md`.

## Delivery slices

Ordered by dependency, not by size.

1. **Per-creative measurement.** *(built)* The projector gains a creative
   dimension, with the row-count cost measured rather than assumed. Nothing else
   in this phase can be judged before this exists, which is the same reason
   P15's grain came first. See the closeout note below.
2. **Variant management.** *(built)* A screen for the assignments that already
   deliver. An advertiser sets each variant's share of its placement, its own
   dates inside the campaign's, and whether it is paused — all from the creative
   step. The share control appears only where a placement holds a second
   creative, because a share beside a lone advertisement claims a choice the
   selector never makes; the window and pause controls appear wherever there is
   an assignment, because both are meaningful for one creative alone. See the
   slice 2 closeout below.
3. **Comparison.** Two variants side by side over a window, using the counters
   from slice 1. An experiment is this plus a hypothesis; it is not a separate
   mechanism.
4. **Review history and preview.** What was approved or rejected, when and why,
   and a device preview of the exact revision reviewed.

## Slice 1 closeout — per-creative measurement

**The measured row growth.** A seeded placement serving four creatives over
seven days produces **28 rollup rows where the old grain produced 7** — one row
per creative per day. The multiplier is exactly the number of creatives serving
a placement and nothing else, asserted in
`RollupCreativeGrainTest::test_the_creative_grain_multiplies_rows_by_the_creatives_serving`
rather than described here. A placement serving one creative is unchanged.

**`slot_line_day` is dropped explicitly**, in `install_table()` as well as in
migration 30, exactly as this document warned. Left in place it does not error:
it goes on enforcing one row per line item per day, silently merging every
variant back into one row so two creatives report identical counters and no
query looks wrong. The test recreates the key before asserting it is gone,
because a fresh table never had it.

### Two things the slice found that were not in the plan

**A pre-dimension row doubles the day it belongs to.** Reconciliation repairs a
row through `ON DUPLICATE KEY UPDATE`, and once the unique key carries a
creative, a counter written at `creative_id = 0` is no longer the row the
projection lands on. It survives untouched beside the newly attributed rows and
the day is counted twice — once unattributed, once per creative. The reconciler
now removes the rows a day's ledger supersedes before rebuilding it.

That delete is scoped twice over, and both bounds are load-bearing. It matches
only `(placement, campaign)` pairs the day's ledger actually has an opinion
about, so a day purged by retention produces no pairs and its counters stand
rather than being rebuilt as zero. And it skips any row whose creative the
ledger still has, so `ON DUPLICATE KEY UPDATE` reaches it — which is what
preserves the frozen `org_id`. A delete one notch wider re-derived tenancy every
night, undoing the freeze by way of the machinery meant to guarantee accuracy;
`FrozenTenancyTest` caught it immediately.

**Existing counters are not backfilled.** Rows written before this keep
`creative_id = 0`, which is the honest reading: they were never attributed and
cannot be, because for days outside the ledger's retention the rollup is the
only surviving record. Backfilling would attribute the days still in the ledger
and leave the rest unattributed, producing a comparison that silently changes
meaning partway along its own x-axis.

**Index order is part of the schema contract.** `dbDelta` appends a new index
rather than placing it where the DDL says, so `creative_day` is declared last to
keep a fresh install and an upgrade agreeing — the schema assertion compares
that order. The reason lives in the PHP docblock and not inside the DDL string:
`dbDelta` parses that statement a line at a time and treats anything in the
column list as a definition, so a comment there produced 382 errors across the
suite rather than a warning.

## Slice 2 note — the mechanism is real, the surface is blocked

Before building a screen that sets weights, the weighting was checked through
the path a visitor takes rather than through the selector. **It works.** Two
assignments at 3 and 1 converge on roughly three to one over 600 fills through
`Fill_Service::for_slug()` — the real candidate query, the real pipeline, the
real payload — both variants serve, and a placement with a single assignment is
unaffected. `WeightedVariantDeliveryTest` holds it, and a mutant that ignores
weight and always returns the first candidate dies there.

That check was worth doing first for a specific reason: the page coordinator was
also complete, tested and reachable, and had no caller for a visitor. A weights
screen over a mechanism in that state would be a control over nothing. This one
is not.

**What blocked slice 2 was one wizard rule, and it was wrong — corrected.**

The first version of this note said an advertiser could not create a variant and
that relaxing the rule would change what submission means for everybody. Both
halves were wrong, and the correction is recorded rather than quietly applied
because the mistake was a claim made without reading the two rules that decide
the question.

- `Creative_Manager::MAX_CREATIVES_PER_PLACEMENT` is **10**. Uploading a second
  creative to a placement was always permitted.
- `Campaign_Validator` gates submission through `Coverage_Service`, which counts
  a placement as covered **once, however many creatives cover it** — and
  `covers_for_submission()` accepts a creative still awaiting review.

So a campaign with two variants was already uploadable and already submittable.
The only rule anywhere that wanted exactly one lived in the campaign wizard's
creative step, which told an advertiser their placement needed exactly one
creative and refused to continue. Upload permitted, submission accepted,
progress blocked — by the one opinion nothing else shared.

That is why weighted variants were unreachable outside the REST route, and it is
a template condition rather than a semantic change: `1 !== count(...)` became
`array() === ...`, with the copy corrected to match. `Coverage_Service` remains
the single definition of usable coverage; the wizard now agrees with it instead
of contradicting it.

**The two questions this note previously raised as open were already answered in
code.** May a campaign be submitted with two variants awaiting review — yes,
`covers_for_submission()` says so. Does one approved variant make the placement
servable — yes, delivery serves whatever assignments are ready. Neither needed a
decision; both needed reading.

## Slice 2 closeout — variant management

The share control shipped first and is described in the note above. Window and
pause followed, and the shape of that work is worth recording because most of it
was deciding what *not* to write.

**Nothing new decides anything.** `Workflow\Assignment_Editor` already owned
every rule these controls need — capability, edit window, status transitions,
`window_fits()`, optimistic concurrency and the audit row — because the REST
route had been driving it since P2. The portal handlers validate nothing of
their own; they map a form to that call. A second validation path would have
been a second definition of when a creative may run, and the two would have
disagreed the first time either changed.

**One thing the portal does decide, and it is a boundary.** The pause form posts
`intent=pause|resume`, never a status. `live → cancelled` is a legal transition
that `Assignment_Editor` permits for the routes meant to offer withdrawal, so a
pause button posting a status field would have been a cancel button for anyone
willing to edit a form — terminally, since the same control could not resume it.
Recorded in [threat-model.md](threat-model.md) with the test that proves it.

**An empty date is a value.** Both ends are submitted every time, because zero
means *inherit this end from the campaign* rather than *leave it alone*.
`Assignment_Rules::window_fits()` treats each end independently, and refuses a
window that widens rather than clamping it — a campaign sold for June must not
carry a creative running into July, and silently moving somebody's date is worse
than refusing it.

**Two duplicate rules were removed rather than added to.** Reading a typed date
into the model's UTC integer lived only in `Portal\Campaign_Actions`, and
rendering one back out lived only in `Portal\View_Data`. The variant window
needed both. They are now `Portal\Date_Input::parse()` and `::format()`, one
class holding both halves of the round trip — a formatter reading one timezone
and a parser reading another loses a day, and the loss is invisible in either
file alone.

**What the pause control is not.** It does not withdraw a creative from its
placement; `Assignment_Editor::unassign()` does that and keeps the artwork. It
does not reject a creative; that is review's. It is the operator pause, which
sets `operator_paused` so that `Assignment_Rules::project_status()` keeps a
person's pause distinct from a campaign transition — a pause that set the status
and not the flag would be undone the next time the campaign moved, which
`CreativeVariantControlsTest` asserts directly rather than trusting.

## Required executable evidence

- **A weighted pair converges on its weights.** *(done — slice 2 note above.)*
  Two assignments at 3 and 1 over a large seeded run land near three to one,
  through the production decision path rather than by calling the selector
  directly.
- **The same seed replays the same winner**, so an experiment's outcome can be
  audited after the fact. *(Held at unit level by
  `WeightedSelectionTest::test_the_same_seed_replays_the_same_winner`. Not yet
  asserted through the fill path, which passes no seed — every request draws its
  own. Auditing a past decision needs the seed recorded with the decision, and
  nothing records one; that is a slice 3 problem, named here rather than assumed
  covered by the unit test.)*
- **Per-creative counters sum to the placement's counters.** The same
  reconciliation the utilisation view now asserts, one dimension lower — a
  breakdown that does not add up to its total is the defect P15 shipped and
  caught.
- **A variant pauses, resumes, and re-dates from the portal**, through the form
  handlers rather than the REST route, with the row read back each time.
  `CreativeVariantControlsTest`. *(done — slice 2.)* The pause control is also
  posted `cancelled`, `completed` and a raw status string, and the row asserted
  not to move; the guard was verified by mutating its map to forward whatever it
  was given and watching that test fail.
- **A stale form loses to the write it did not see.** The same revision
  submitted twice is refused with `aggr_assignment_conflict` rather than
  overwriting, and a stranger reaches neither control — refused as `aggr_not_found`,
  because a refusal that distinguishes "forbidden" from "missing" enumerates.
  *(done — slice 2.)*
- Approving a variant does not alter any other variant's revision, asserted by
  checksum rather than by absence of an error.
- A rejected revision keeps its reason after the campaign moves on.
- Preview renders under the same isolation as delivery, asserted on the headers
  and the sandbox rather than on the rendered output.
- The projector's added dimension is measured for row growth on a seeded
  multi-creative placement, and the figure is recorded here.

## Entry criteria

P17 needs P2's revision and assignment model, which exists, and P14's reporting
facts, which exist. **It does not need forecast history**, so it is not blocked
behind P16 — the two are independent and P17 is the one that can start.

## Documentation deliverables

- `domain-model.md` gains the creative dimension beside the rollup grain.
- `rest-api.md` gains the variant operations and their capabilities.
- `runbook.md` gains the pre-dimension reading note.
- This document's exit criteria are completed at closeout, with the measured
  row-growth figure recorded rather than described.

## Exit criteria

To be completed at closeout.

## Exit evidence and decision

Completed at closeout. The measured row-growth figure from slice 1 belongs here
as a number, not a description — it is the one cost this phase takes on that a
reader cannot infer from the code.
