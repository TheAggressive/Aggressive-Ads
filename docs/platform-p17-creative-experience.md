# P17 — Creative experience

## Status

- Phase: **P17 — Creative experience**
- Roadmap state: `[ ]`
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

`aggr_events` records `creative_id` on every impression and click. `aggr_rollups`
does not — its grain is day, placement, outcome and opportunity. So per-creative
performance exists as raw events and nowhere else.

That gap is the whole ordering constraint of this phase. Comparing two variants
by querying the event ledger directly is a scan whose cost grows with history,
which is exactly what the rollup exists to avoid. **A creative dimension has to
reach the projection before any comparison surface is honest**, and P13's
projector is where that belongs.

Adding a dimension to a counter table is not free: it multiplies rows by the
number of creatives serving a placement. The slice that does it must state the
row-count consequence and measure it, rather than discover it on a busy site.

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
gains a creative dimension; `aggr_events` already carries `creative_id` and
remains the record the projection is derived from. What synchronizes them is the
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

Durable data changes: `aggr_rollups` gains a creative column, so this needs a
migration and the rules that go with one.

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
- **`dbDelta` adds an index and never drops one.** A key whose definition
  changes leaves the old one enforcing the old rule, so any changed unique key
  is dropped explicitly in both the migration and `install_table()`, and the
  test asserting it recreates the old key first.
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

- **Expected cardinality.** Rows per placement per day multiply by the number of
  creatives serving it. A placement with six live variants produces six times
  the rollup rows it does today. **This figure is the slice's headline risk and
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

1. **Per-creative measurement.** The projector gains a creative dimension, with
   the row-count cost measured rather than assumed. Nothing else in this phase
   can be judged before this exists, which is the same reason P15's grain came
   first.
2. **Variant management.** A screen for the assignments that already deliver:
   weight, window, status, and the revision each points at. Today this is a REST
   route and no UI.
3. **Comparison.** Two variants side by side over a window, using the counters
   from slice 1. An experiment is this plus a hypothesis; it is not a separate
   mechanism.
4. **Review history and preview.** What was approved or rejected, when and why,
   and a device preview of the exact revision reviewed.

## Required executable evidence

- **A weighted pair converges on its weights.** Two assignments at 3 and 1 over
  a large seeded run land near three to one, through the production decision
  path rather than by calling the selector directly.
- **The same seed replays the same winner**, so an experiment's outcome can be
  audited after the fact.
- **Per-creative counters sum to the placement's counters.** The same
  reconciliation the utilisation view now asserts, one dimension lower — a
  breakdown that does not add up to its total is the defect P15 shipped and
  caught.
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
