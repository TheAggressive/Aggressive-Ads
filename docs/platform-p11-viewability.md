# Platform P11 viewability

P11 answers "was this ad actually seen?" rather than "was it served?". The
measurement model already has the word: P10 defined `viewable` and placed it
after `served` in the lifecycle. Nothing writes it.

This document defines the work and exit criteria. It does not claim that P11 has
started or that any item below is implemented.

## Status

- Phase: **P11 — Viewability**
- Roadmap state: `[ ]`
- Last audited: 2026-08-28 (definition only; no implementation)
- Authoritative environments: the Docker CI lanes, plus the Playwright lane —
  this is the first phase whose correctness lives in the browser.

## Outcome

A served ad that a person had a real chance to see records one `viewable` event.
A served ad scrolled past, rendered in a background tab, or painted below the
fold and never reached records none — and still serves normally.

Publishers get the number buyers ask for first. P12 gets the view-through window
it attributes against.

## Scope boundary

**This phase owns:**

- the client measurement — `IntersectionObserver` plus the Page Visibility API;
- the threshold: **50% of the creative's pixels for one continuous second**,
  configurable;
- the `viewable` beacon, once per fill, replay-protected;
- server-side enforcement that `viewable` may only follow a `served` for the
  same token; and
- the reporting column and its rollup.

**This phase does not own:**

- **conversions and view-through attribution (P12)** — P11 records that a fill
  was seen; P12 decides what that entitles;
- **the analytics schema (P13)** — no new dimensions, no schema versioning;
- **viewability-based pacing or pricing** — a vCPM goal is P6 vocabulary and a
  later decision, and nothing in the decision engine may read viewability yet;
  and
- **cross-domain or in-iframe measurement.** Native fill paints into the host
  page, which is why this is measurable at all.

**Compatibility:** `POST /aggr/v1/i` keeps its current meaning. A request that
sends no event type still records `served`, so a cached page carrying the
previous script keeps reporting impressions.

## Entry criteria

- P10's lifecycle is in place: `viewable` exists, follows `served`, and the
  event table is unique on `(token_hash, event)`.
- The complete P0 regression baseline is green.

## Canonical model and ownership

**No new table and no new column on `aggr_events`.** A viewable is another row
with a different `event`, against the same `token_hash` — which is what makes
"once per fill" a database guarantee rather than client discipline.

| Fact | Owner |
|---|---|
| Was this fill seen? | the `viewable` event row |
| How many were seen? | `aggr_rollups.viewables`, a projection |
| What counts as seen? | `Domain\Viewability_Rules`, pure |

`aggr_rollups` gains `viewables`, projected the same way `impressions` is: an
upsert on the write path, and the daily reconcile rebuilds it exactly from the
ledger.

## Invariants

- **A viewable requires a served.** Enforced server-side against the same token,
  through `Measurement_Rules::can_follow()`. A client that beacons `viewable`
  for a fill it never rendered is refused.
- **Once per fill.** The `(token_hash, event)` unique key, not a client flag.
- **The threshold is server-owned.** The client is told the percentage and the
  duration; it does not choose them.
- **Measurement never blocks delivery.** No `IntersectionObserver`, no Page
  Visibility, a thrown observer — the ad still paints and simply reports
  nothing. An unmeasured impression is not a lost one.
- **A hidden tab is not a view.** Time accrues only while the document is
  visible, and the dwell timer resets when it is not.
- **Tenancy is unchanged.** The token already binds `blog_id`, placement,
  campaign and creative; viewability adds no identifier.

## Migration and compatibility contract

- One additive column, `aggr_rollups.viewables`, with its own schema version.
  `dbDelta` adds it; nothing needs dropping, so there is no index to retire.
- **Historical rows are not backfilled and must not be.** A day before this
  shipped has no viewability data, and projecting zero would be indistinguishable
  from "nothing was seen". Reporting shows viewability as unavailable before the
  first day it was measured, rather than as `0%`.
- Old cached pages keep working: no event type means `served`.
- Rollback is the ordinary forward-only path. A build predating the column
  ignores it, exactly as the line-item and creative-model migrations do.

## Workflows and API

`POST /aggr/v1/i` gains an optional `event` parameter, allowlisted to `served`
and `viewable`. Everything else about the route is unchanged: public when the
module is on, cross-origin refused, rate limited per client, `no-store`.

It is the same route rather than a second one because it carries the same token,
needs the same bound, and a separate endpoint would be a second thing to
authorize and forget to rate limit.

## Security, privacy and abuse cases

- **Viewability is client-attested, and cannot be otherwise.** The browser is
  the only thing that knows what was on screen. State it plainly rather than
  implying the number is trustworthy in an adversarial setting.

  What the server does control: a `viewable` must carry a signed token bound to
  a real fill, that token must already have recorded a `served`, and it can be
  spent exactly once. A dishonest client can therefore inflate viewability only
  up to the number of ads actually served to it — which the same client could
  already inflate by requesting fills. This adds no new leverage.
- **No new identifier.** No cookie, no fingerprint, no dwell-time histogram —
  one boolean per fill.
- **Never logged:** anything identifying a visitor, and no per-event timing.
- Rate limiting is the existing beacon bucket, shared with `served`, so a client
  cannot double its budget by alternating event types.

## Failure, recovery and rollback

- **No `IntersectionObserver`** → no viewability, delivery unaffected. Stated,
  not incidental: this is the majority behaviour on old browsers and must be a
  silent no-op.
- **The beacon fails** → the ad stays on screen. Viewability is reported, never
  awaited.
- **The projection write fails** → the event row is already durable and the
  daily reconcile repairs the counter, exactly as impressions do.
- **The observer throws** → caught, disconnected, delivery continues.

## Performance and scale contract

- **One observer per slot**, disconnected the moment the threshold is met. A
  page with twenty slots holds at most twenty, and zero once they have all
  reported.
- **No timers per frame.** The dwell clock is a single timeout armed on entry
  and cleared on exit, not a `requestAnimationFrame` loop.
- **At most one extra request per fill**, and only for fills that become
  viewable.
- **Hot-path query budget unchanged.** Viewability adds nothing to a decision:
  the engine may not read it, this phase or later, without amending P6.
- Write budget: one event insert and one rollup upsert, the same shape as a
  served.

## Observability and operations

- Site Health reports the viewable-to-served ratio for the last closed day, and
  says *not measured* rather than `0%` for days before the column existed.
- A ratio of zero on a day with impressions is the signal worth investigating:
  it usually means the script is not running, not that nothing was seen.
- The runbook gains how to tell those two apart.

## Accessibility and internationalization

No new UI beyond a reporting column, which inherits the existing table's
semantics. The measurement must not alter focus, announce anything, or change
layout — an ad that moves when it is measured is a worse ad.

`prefers-reduced-motion` is irrelevant here and is named so nobody wonders:
observation causes no motion.

## Required executable evidence

- Pure threshold tests: below, at, and above 50%; the dwell boundary at
  999ms, 1000ms and 1001ms; visibility lost mid-dwell.
- A `viewable` without a prior `served` for that token is refused.
- A second `viewable` on one token is a replay, not a second count.
- An absent `IntersectionObserver` serves the ad and reports nothing.
- Rollup projection and exact reconcile from the ledger.
- Browser evidence in the Playwright lane: an ad scrolled into view reports;
  one left below the fold does not.
- The complete P0 baseline.

A test that asserts the client *called* the beacon is not evidence that the
event was recorded. Assert the row.

## Documentation deliverables

`data-schema.md`, `rest-api.md`, `administration.md`, `runbook.md`,
`delivery-performance.md`, `threat-model.md` — the client-attested caveat
belongs there in as many words — and
`platform-implementation-progress.md`.

## Exit criteria

The phase may move to `[x]` only when:

1. A served ad meeting the threshold records exactly one `viewable`, end to end
   in a real browser.
2. An ad that does not meet it records none, and still serves.
3. The threshold is configurable and server-owned.
4. `viewable` cannot be recorded without a served, or twice, and both are
   proven by test rather than by construction.
5. Missing browser support is a silent no-op, proven with the API removed.
6. The rollup projects and reconciles exactly.
7. Reporting distinguishes *not measured* from *zero*.
8. Documentation describes what shipped, including that the signal is
   client-attested.

The existence of an observer, an event type or a column is not sufficient
evidence for completion.

## Exit evidence and decision

To be completed at closeout.
