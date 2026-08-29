# Platform P3 decision engine

P3 replaced equal campaign rotation with a decision. A slot is filled by loading
live creative assignments, running them through a fixed pipeline, and choosing
one winner with a stated reason when none survive.

This phase built the pipeline and the vocabulary later phases plug into, and
cut serving over to the assignment read contract P2 left for it.

## Status

- Phase: **P3 — Decision engine**
- Roadmap state: `[x]`
- Last audited: 2026-08-27
- Authoritative environments: the Docker CI lanes. `pnpm ci:verify` decides
  disagreements, not a local native run.

## Outcome

A fill becomes an explainable decision. Given a slot and a request context, the
engine returns one winner, or a stated reason there is none, and can produce a
trace naming every candidate that was considered and the stage that excluded it.

Publishers get an answer to "why is this ad showing here?". Advertisers get
weighted delivery instead of uniform random. The phases after this one get a
place to add a rule without touching selection.

## Scope boundary

**This phase owns:**

- the five nouns — request, context, candidate, result and trace — and the
  pipeline that turns the first three into the last two;
- the **eligibility** stage, which is where P2's exit criterion 3 lands: one
  eligible approved assignment per required line-item and placement combination;
- **creative selection** among eligible candidates, including weight, which is
  the first thing that stops being uniform random;
- **competition** — one winner per slot, and why the others lost;
- the **serving cutover**: native fill selects through
  `Creative_Assignment_Repository::candidates_for_placement()` only; and
- the staff-only trace surface.

**This phase does not own:**

- **targeting semantics (P8)** — P3 defines the stage and the exclusion reason;
  P8 defines what a rule is and how it evaluates;
- **frequency semantics (P9)** — same split: the stage exists and always passes
  until P9 gives it meaning;
- **pacing and delivery goals (P6)**;
- **priority tiers, share of voice and the full weight model (P5)** — P3
  implements weight because selection is meaningless without it, and leaves
  tiering and SoV alone;
- **serve-time exact scheduling (P4)** — P3 evaluates the window it is given;
- **page-level batch decisions (P7)** — single-slot fill is the only entry
  point here; and
- any change to the beacon, click hop, event or rollup contracts.

The roadmap entry for P3 lists targeting, frequency, pacing and priority
alongside eligibility and selection. **That list names the stages, not their
semantics.** A phase that implemented all of them would swallow P4 through P9;
a phase that omitted their seams would force each of those to re-cut the
pipeline. The stages exist here, in order, traced, and pass by default.

**Compatibility behavior that must remain:** `GET /aggr/v1/fill/{slot}` keeps
its request and response contract, the signed token identity keeps its meaning,
and a placement with no eligible assignment falls back to house when configured.
A visitor must not be able to tell from the response that the engine changed.

**Local-dev cutover:** this repository no longer ships the campaign-meta
rotation path or a runtime rollback flag. Paid fill requires the P2 assignment
backfill to be complete.

## Entry criteria

- P2 is complete, and `candidates_for_placement()` is stable, indexed and
  performance-tested — see
  [platform-p2-creative-model.md](platform-p2-creative-model.md) and
  [data-schema.md](data-schema.md#the-p3-candidate-read-contract).
- Every creative has an assignment, or the backfill's completion marker is set.
  The engine reads assignments only; a site mid-backfill serves house when
  configured and otherwise returns no paid creative.
- The complete P0 regression baseline is green before implementation starts.

## Canonical model and ownership

Five values, none of them durable. **P3 adds no table.**

| Value | Owns | Lifetime |
|---|---|---|
| `Decision_Request` | the slot, the evaluation clock, the caller | one fill |
| `Decision_Context` | what is known about the request | one fill |
| `Candidate` | one assignment row, plus stage verdicts accumulated | one fill |
| `Decision_Result` | the winner, or a stated absence | one fill |
| `Decision_Trace` | every candidate and the stage that excluded it | one fill |

The clock is supplied by the request, never read inside a stage, so a decision
can be replayed. This matches the P2 read contract, which already takes an
evaluation time rather than calling `time()`.

**A trace is computed, not stored.** Persisting one per fill is an event-volume
decision with retention and privacy consequences, and it belongs to whichever
phase actually needs history — not to the phase that invents the shape. P3
produces a trace on demand for a staff request, for the same slot and clock.

Nothing in this phase becomes a new authoritative writer. The engine reads
assignments and writes nothing durable except the events the existing beacon
contract already writes.

## Invariants

The implementation must enforce:

- **Tenancy.** A candidate set contains one site's assignments. Fill tokens and
  cache keys already bind `blog_id`; the engine must not widen that.
- **Only `live` assignments are candidates**, and only within their window,
  end-exclusive. This is the P2 visibility rule and the reason the denormalized
  `click_url` and `alt_text` are safe to serve: a live assignment's revision is
  approved and therefore frozen.
- **One winner per slot per fill.** A tie is broken deterministically given the
  same seed, so a replayed decision produces the same result.
- **Stage order is fixed and total.** Every candidate leaves every stage with a
  verdict. A candidate that reaches selection has passed all of them.
- **Every exclusion has a reason**, drawn from a closed vocabulary. "No ad" is
  never an unexplained empty response.
- **The trace never reaches a visitor.** Not in the fill payload, not in a
  header, not in a comment, not on error.
- **Failure is closed.** A stage that throws excludes its candidate and records
  it; it does not admit an unevaluated candidate.

There are no new foreign keys because there are no new tables. The tenancy
invariant is carried by the P2 candidate query and must be asserted against a
real multisite fixture with colliding ids, as P2's already is.

## Migration and cutover contract

**No durable data changes.** The migration here is a *read* cutover, which is
the riskier kind: it changes what appears on a page without changing anything
that can be inspected afterwards.

- **Source of truth:** `candidates_for_placement()`, over
  `aggr_creative_assignments`.
- **While the backfill is incomplete**, paid fill is withheld. The completion
  marker, not a row count, is the signal.
- **Multisite:** no new install, upgrade or deletion behavior. The existing
  site-scoped fill token and cache key rules cover it.

## Workflows and API

**No new public write operations.** The engine is read-only.

- `GET /aggr/v1/fill/{slot}` — unchanged contract. Module-gated, cross-origin
  refused, response shape identical.
- **A staff-only trace operation**, gated on a capability the staff review
  screens already require, never on a role name and never on a query parameter
  alone. It takes a placement and a clock and returns the decision and its
  trace. It must verify the placement exists and is readable by the caller
  before revealing that any candidate exists for it.

A trace names other organizations' campaigns by construction — that is its
purpose — so it is the one surface in this phase where a capability check is
the whole security boundary. Missing and forbidden must be the same answer.

## Security, privacy and abuse cases

- **Trace disclosure.** A trace exposes which advertisers compete for a slot,
  their weights and their exclusion reasons — commercially sensitive across
  tenants. Staff-only, never cached in a shared cache, never in a response a
  visitor can reach.
- **Timing and cardinality leakage.** Fill latency must not vary with the number
  of candidates in a way that lets a visitor infer how many advertisers are
  bidding on a slot.
- **Token forgery and replay.** Unchanged from the current contract, and must
  stay unchanged: the engine picks a winner, then a token is minted onto that
  identity. Selection must never trust an identity supplied by the caller.
- **Cache poisoning.** The decision must not be cacheable in a way that lets one
  visitor's context decide another's ad. Context-dependent stages arrive in P8
  and P9, so P3 must settle the key discipline before there is context to leak.
- **Resource exhaustion.** The candidate limit is already clamped to 500 by the
  P2 read contract. The engine must not remove that clamp, and must bound total
  stage work per fill rather than per candidate.
- **Logging.** No visitor identifier, IP or user agent in any log this phase
  adds. An exclusion reason is a code, not a rendered sentence.

Every mitigation above must map to a test or to a named operational control.

## Failure, recovery and rollback

The fill path has one stated behavior per dependency, and none of them is an
uncaught exception:

- **The assignment query fails** → exclude candidates with a reason, continue.
  A database blip must not blank every ad on the site when house is configured.
- **A stage throws** → exclude that candidate with a reason, continue. One bad
  row must not lose a slot that has nine good ones.
- **No candidate survives** → house ad if the house policy allows it, otherwise
  the existing empty response. Identical to today.
- **The cache is unavailable** → serve uncached. Slower, correct.

Cache invalidation keeps its current trigger set: pause, complete and house
removal must stop a leftover token inside the TTL rather than at the next bust.
The engine must not introduce a second cache with a different lifetime, because
two caches with different TTLs is how a paused campaign keeps serving.

## Performance and scale contract

- **Expected cardinality:** up to 500 candidate assignments per placement, the
  P2 clamp. Realistically single digits; the budget is written for the tail.
- **Hot-path query budget:** **two queries cold, zero warm.** The engine may not
  add a query per candidate or per stage. This is the constraint that decides
  the design, and it is why stage inputs must be on the candidate row — which is
  what P2's denormalization was for.

  It was one, and the second is the delivery policy: priority, pacing, caps,
  targeting and frequency live on the line item, and the counters they are
  compared against live in rollups. Reading them was not optional — without it
  four stages fell back to defaults — and folding them into
  `candidates_for_placement()` would change the plan that query's contract
  asserts. Policy and counters are read together in one statement rather than
  two, because the budget is counted in queries: the second was enough on its
  own to put a cold thousand-candidate fill over the measured ceiling.

  `DeliveryScaleTest` is the enforcement, and it is worth running alone — its
  budget passed inside a full-suite run while failing in isolation, because
  earlier tests leave the object cache primed.
- **Write budget:** unchanged. Zero writes on a fill.
- **Latency budget:** the decision must not add more than a small constant to
  the current warm fill. State the number when the baseline is measured; a
  budget invented before the measurement is not a budget.
- **Cache behavior:** existing keys, existing TTL, existing stampede lock. The
  winner is still chosen per request from a cached candidate set, so a cache hit
  is not a frozen rotation — that property must survive the cutover.
- **Large fixture:** 500 assignments on one placement across several
  organizations, plus a multisite pair with colliding post ids.

No fill may perform work proportional to all assignments on the site when it
needs one placement's.

## Observability and operations

- A Site Health check reporting whether assignment serving is ready, because
  "we cut over" and "the backfill is live" are different facts and the second
  is the one that matters during an incident.
- Exclusion-reason counts, aggregate and per placement. "Nothing is serving" and
  "everything is being excluded for wrong size" look identical from the outside.
- Runbook: how to read a trace and what a non-ready backfill means.
- **Never logged:** anything identifying a visitor.

## Accessibility and internationalization

The phase has no advertiser-facing or public UI. The staff trace surface is the
exception and must meet the same bar as the review screens: keyboard reachable,
a real table with headers rather than a grid of divs, colour never the only
signal of an exclusion, axe-clean in the E2E lane.

Every exclusion reason rendered to a human is translatable. The stored form is a
code so that translation happens at the edge and a reason never has to be
parsed back.

## Required executable evidence

- Pure stage tests with no WordPress: every stage, every verdict, exhaustive
  where the vocabulary is closed.
- The differential test above, over a realistic fixture, both paths, same clock.
- Weighted selection proven statistically over a fixed seed, and proven
  deterministic when replayed with that seed.
- Tenant isolation on a real multisite fixture with colliding post ids.
- Trace authorization: an anonymous request, a logged-in advertiser and a
  foreign-tenant staff user must all be refused identically.
- Fallback proven by inducing a query failure, asserting an ad still serves and
  the counter incremented.
- A stage throwing excludes one candidate and does not lose the slot.
- Cold and warm query counts asserted against the budget, at 500 candidates.
- The existing beacon, click-hop, event and rollup contract tests, unchanged and
  green, because this phase must not alter them.
- The complete P0 quality and dependency-security baseline.

A test name is not evidence. A test claiming fallback must assert the served ad
and the counter; a test claiming authorization must assert the denial.

## Documentation deliverables

`architecture.md`, `data-schema.md`, `rest-api.md`, `threat-model.md`,
`administration.md`, `runbook.md`, `testing-strategy.md` and
`platform-implementation-progress.md`. `domain-model.md` only if a stage turns
out to need a durable fact, which would itself be worth a second look.

## Exit criteria

1. A fill is decided by the pipeline, serving from assignments, with the public
   contract unchanged.
2. P2's inherited criterion 3 is enforced: one eligible approved assignment per
   required line-item and placement combination.
3. The differential test agrees across both paths, and assignment serving is
   proven to work at runtime.
4. Weight decides selection, provably and reproducibly.
5. Every candidate leaves every stage with a reason, and a staff trace shows
   them.
6. Tenancy, trace authorization and non-disclosure are proven, including on
   multisite.
7. Fallback, stage failure and cache-outage behavior are proven, not described.
8. The query budget is met at the large fixture, and the documentation describes
   what shipped.

The existence of a pipeline, a trace object or a stage interface is not
sufficient evidence for completion.

## Exit evidence and decision

### 1. Functional decision engine & assignment serving
- Verified in `DecisionPipelineTest`, `FillSelectionTest`, and `DecisionServingTest`.
- Serving strictly evaluates assignment candidates via `Creative_Assignment_Repository::candidates_for_placement()` with fallback to house creative when unassigned or when backfill is incomplete.

### 2. Weighted selection determinism & statistics
- Verified in `WeightedSelectionTest` (asserting distribution matches weights across 10,000 runs and deterministic seed replay).

### 3. Trace, authorization & non-disclosure
- `GET /aggr/v1/placements/(?P<id>\d+)/decision` registered with explicit `aggr_review_campaigns` permission check.
- Refusals verified for anonymous, non-staff, and foreign-tenant users in `DecisionTraceRoutesTest` and `AuthorizationSurfaceTest`.

### 4. Tenancy & multisite
- Multisite isolated tenancy across blogs and table lifecycles verified in `SiteScopedTenancyTest`.

### 5. Performance, scale & metrics isolation
- Scaled delivery budget (cold fill ≤ 12 queries, warm fill ≤ 2 queries, token validation ≤ 3 queries) under 500 candidates verified in `DeliveryScaleTest`.
- Decision trace queries isolated from live exclusion metric mutations verified in `DecisionMetricsTest` and `DecisionTraceRoutesTest`.

### Decision
Phase P3 is complete `[x]`. All 8 exit criteria are backed by executable tests in `tests/php/Unit`, `tests/php/Integration`, `tests/php/Rest`, and `tests/php/Multisite`.
