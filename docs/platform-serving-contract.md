# Platform serving group contract

This contract governs P3 through P9. Together they replace equal Campaign
rotation with an explainable, bounded and privacy-aware decision system. It
defines shared boundaries and group-level evidence; each phase still needs a
detailed definition based on
[platform-phase-definition-template.md](platform-phase-definition-template.md)
before implementation begins.

Nothing in this document marks P3–P9 as started or complete.

## Group outcome

For each placement request, the platform can determine which line item and
creative may serve, explain every exclusion to authorized staff, respect exact
time, priority, contractual delivery, targeting and frequency rules, and make
coordinated decisions for all placements on a page within an explicit latency
and query budget.

The browser receives only the winning delivery payload and opaque signed
identifiers. Decision traces, losing candidates and internal commercial facts
remain server-side and staff-only.

## Entry and ordering

- P1 and P2 must be complete before P3 cuts serving over to line items and
  creative assignments.
- P3 establishes the request, context, candidate, result and trace contracts
  consumed by P4–P9.
- P4–P6 refine competition and delivery behavior without bypassing P3 stages.
- P7 coordinates several P3 decisions; it does not invent another selector.
- P8 supplies schema-validated targeting predicates to P3 eligibility.
- P27 gates any P9 cap that requires an identifier beyond a privacy-safe
  anonymous session.
- P10 and P13 must provide the counters P6 needs before goal-based pacing is
  considered complete.

No later serving phase may patch the legacy `Fill_Rotation` path and call that
the new behavior. There must be one decision pipeline.

## Shared decision model

The group must converge on these stable concepts:

- **Decision request** — placement or page slots plus an authenticated,
  normalized request identity.
- **Decision context** — site, request, page, user/consent, time, device and
  optional geo facts produced by trusted resolvers.
- **Candidate** — a P2 line-item and creative-assignment projection with the
  immutable facts needed by decision stages.
- **Exclusion** — a stable machine reason and safe staff explanation naming the
  stage that refused a candidate.
- **Decision result** — winner or explicit no-fill reason, signed decision id,
  creative payload and cache directives.
- **Decision trace** — bounded staff-only evidence of inputs, exclusions,
  competition and winner, with secrets and unnecessary personal data removed.
- **Page decision** — an ordered set of slot results sharing one page context
  and coordination state.

These objects must be versionable and independent of REST serialization so
native PHP callers and future provider adapters use the same semantics.

## Pipeline contract

P3 must define separable stages with explicit inputs and outputs:

1. Load a bounded candidate set through the P2 repository contract.
2. Enforce tenant, status, approval, placement, creative and exact-time
   eligibility.
3. Evaluate targeting predicates.
4. Evaluate frequency policy under the active consent state.
5. Evaluate goal, cap and pacing availability from bounded counters.
6. Select the highest eligible priority tier.
7. Apply share-of-voice and weight competition within the permitted tier.
8. Select or confirm the creative assignment.
9. Produce a winner or explicit no-fill reason and a bounded trace.

Stages must not mutate Campaign or line-item business state during a fill.
Delivery counters and frequency observations use purpose-built interfaces and
durable/event flows defined by their owning phases.

## Phase boundaries

### P3 — Decision engine

Owns the pipeline, stable objects, exclusion taxonomy, trace authorization,
candidate query and compatibility cutover from Campaign rotation. It must
produce a correct winner or no-fill result before later policies become rich.

### P4 — Exact scheduling

Owns serve-time UTC timestamp and timezone/daypart evaluation. Cron may
reconcile lifecycle labels but is never the authority for whether the current
instant is eligible.

### P5 — Priority, weight and share of voice

Owns data-driven tiers and competition within an eligible tier. Product names
must not be hard-coded as priority logic. Statistical tests must distinguish a
correct weighted distribution from a superficially random sequence without
making unit tests flaky.

### P6 — Delivery goals and pacing

Owns goal/cap interpretation, EVEN and ASAP pacing, elapsed-time calculations
and counter interfaces. A fill may not issue `COUNT(*)` against the event
ledger. Counter consistency and overshoot tolerance must be stated under
concurrency.

### P7 — Page-level batch decisions

Owns one authenticated page request for several slots, deterministic response
mapping, roadblocks, competitive separation and page-scoped coordination.
Single-slot fill remains a documented compatibility surface.

### P8 — Targeting rule engine

Owns a versioned, schema-validated declarative rule language with nested AND/OR
and exclusions. Stored rules are data, never executable input. Dimension
resolvers are allowlisted, typed and independently testable; geo remains behind
an interface.

### P9 — Frequency capping

Owns campaign, line-item and creative caps across the declared windows. Storage
is expiring and replaceable by an object-cache implementation. No fingerprint
may be assembled to recover identity that consent or privacy policy withholds.

## Cross-phase invariants

- Eligibility and competition are distinct: an ineligible candidate never
  reaches weight or priority selection.
- One authoritative clock and timezone conversion governs all serving stages.
- A no-fill is an explicit result with one stable primary reason, not an empty
  success response.
- The same normalized context value cannot mean different things to targeting,
  frequency and tracing.
- Priority, pacing, targeting and frequency never mutate commercial ownership
  or bypass Campaign/line-item lifecycle rules.
- External provider failure never silently outranks or consumes a guaranteed
  native delivery opportunity.
- Cached decisions include every policy revision needed to make invalidation
  correct and are scoped by WordPress site.
- Staff explanations may reveal decision logic but never secrets, raw IPs,
  private user data or another organization's candidates.
- Randomized selection is injectable and reproducible in tests without making
  production outcomes predictable to an attacker.
- A stage failure has a declared result: exclude the affected candidate, return
  a safe no-fill, or use a documented fallback. Exceptions do not choose ads.

## Security and privacy contract

Request context is derived server-side wherever possible. Client-supplied ids
are authenticated or treated only as lookup hints and revalidated. Targeting
rules have depth, node-count and value-size limits. Decision and frequency ids
are opaque, scoped, expiring and replay-aware.

P9 must document which cap modes work without consent, which require consent,
their retention windows and deletion behavior. Turning consent off must not
leave an alternate cache or trace that continues the same identification.

Public responses expose neither exclusion traces nor the losing set. Staff
trace access requires an explicit capability, organization scope where
applicable, audit evidence and rate limiting.

## Performance and availability contract

Each detailed phase definition must set authoritative cold and warm budgets for
queries, cache operations and p95/p99 latency. The final group must demonstrate
thousand-Campaign and many-creative fixtures with selective indexes and no
per-candidate database query.

Candidate loading, counters, targeting resolvers and frequency storage must be
bounded. Cache misses may cost more than hits but may not turn a fill into an
unbounded scan. Stampede protection must fail safely: a lost lock cannot wedge a
placement, and a cache outage cannot take WordPress down.

Batch decisions must avoid repeating candidate and context work for every slot.
The response must map every requested slot exactly once even when individual
slots no-fill or fail.

## Observability contract

Structured diagnostics correlate request id, page-decision id, decision id and
later event ids. Metrics include cold/warm latency, candidate count at each
stage, exclusion/no-fill reasons, cache behavior, counter lag, frequency-store
health and provider fallback. Values with dangerous cardinality remain logs or
sampled traces rather than metric labels.

Trace retention, sampling and redaction are explicit. Delivery never waits for
a diagnostic sink.

## Required group evidence

Across P3–P9, detailed phase tests must collectively prove:

- exhaustive stage ordering, exclusion reasons and no-fill taxonomy;
- exact boundary timestamps, timezones, DST and dayparts;
- deterministic priority and statistically sound weighted/SoV behavior;
- goal and cap behavior under elapsed time, delayed counters and concurrency;
- batch mapping, roadblocks and competitive separation;
- targeting schema limits, nested truth tables, exclusions and hostile input;
- frequency windows, expiry, consent changes and storage failure;
- trace authorization, tenant isolation, redaction and audit;
- cold/warm query and latency budgets at realistic cardinality;
- cache invalidation, stampede, dependency outage and fallback behavior;
- compatibility of single-slot fill throughout rollout;
- browser delivery with JavaScript unavailable or partially failing; and
- the complete P0 baseline after every phase.

## Group exit criteria

The Serving group is complete only when P3–P9 are individually `[x]` and:

1. Every native fill uses the single documented decision pipeline.
2. Every candidate exclusion and no-fill has stable, authorized explanatory
   evidence.
3. Exact time, priority, weight, goals, pacing, targeting and permitted
   frequency rules compose without conflicting authorities.
4. Single-slot and page-level decisions meet their performance and availability
   budgets.
5. Privacy and consent behavior is explicit and tested for every identifier.
6. Operations can diagnose failures without exposing secrets or personal data.
7. The complete authoritative quality baseline is green and the documentation
   describes the shipped pipeline.
