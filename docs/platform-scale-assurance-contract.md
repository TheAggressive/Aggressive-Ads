# Platform scale and assurance group contract

This contract governs P29 through P34. Together they introduce scale only where
measured load requires it, make event ingestion and system health operationally
safe, enforce performance and accessibility as release contracts, and constrain
the later intelligence layer to reversible suggestions. Each phase still needs
a detailed definition from
[platform-phase-definition-template.md](platform-phase-definition-template.md)
before implementation begins.

Nothing in this document marks P29–P34 as started or complete.

## Group outcome

The complete platform can grow beyond one-process assumptions without requiring
new infrastructure for ordinary WordPress sites. Operators can observe and
recover every critical asynchronous path, releases have explicit capacity and
accessibility evidence, and automated intelligence may assist humans without
becoming an authority over money, publication, privacy or delivery guarantees.

## Entry and ordering

- P29 follows measured bottlenecks in the preceding phases; it may not create
  abstractions solely because distributed infrastructure could exist.
- P30 builds on the stable P10/P13 event contract and P29 durability interfaces.
- P31 observes real migrations, decisions, events, providers and caches from the
  implemented platform rather than predicting placeholder metrics.
- P32 codifies capacity and concurrency limits for every prior hot path.
- P33 applies throughout all earlier phases and must be extended as each UI
  ships; its phase closeout audits the completed platform rather than postponing
  accessibility until the end.
- P34 starts only after the domain, measurement, privacy and audit models are
  mature enough to constrain and evaluate suggestions.

## Phase boundaries

### P29 — Scalability abstractions

Owns interfaces for counters, locks, queues, expiring storage and other measured
scale seams. MySQL, WP-Cron and the object cache remain working defaults. No
external queue, cache or analytics service becomes required for basic plugin
operation.

### P30 — Event ingestion scale

Owns authenticated batching, buffering, backpressure and replay-safe server
aggregation without weakening event durability. Clients submit observations;
the server validates identity, dimensions and totals.

### P31 — System health and observability

Owns structured correlation, metrics, health checks, operational status and
runbook signals for migration, cron, rollups, decisions, providers, caches,
queues, table growth and failure recovery.

### P32 — Testing and performance

Owns representative large fixtures, concurrency suites, query/latency budgets,
capacity thresholds, regression gates and repeatable load methodology across
fill, event write, report read and background work.

### P33 — Accessibility

Owns the cross-platform audit and release evidence that every new surface is
keyboard navigable, labelled, focus-managed, reflow-safe and
colour-independent. Automated checks supplement rather than replace manual
keyboard and assistive-technology review.

### P34 — Intelligence layer

Owns bounded, explainable suggestions and their evaluation. It never gains
authority to charge, publish, change billing, alter consent, expose data,
override policy or modify a guarantee without a separately authorized human
workflow.

## Scale architecture invariants

- The default installation works with supported WordPress, MySQL, WP-Cron and
  the object-cache API alone.
- Optional infrastructure is selected through configuration and capability
  discovery; absence or outage has a documented local fallback or explicit
  degraded mode.
- Interfaces follow a demonstrated contention, latency, durability or capacity
  need and preserve the core domain semantics across implementations.
- Locks have ownership tokens, bounded lifetime and safe stale-lock recovery.
- Queued work has a stable id, idempotency key, attempt count, next attempt and
  terminal/failure state.
- Buffering may delay projection but cannot acknowledge an event that has no
  durable recovery path.
- Backpressure is explicit. The platform sheds or defers optional work before
  exhausting resources required for WordPress or delivery.
- Batches have item, byte and execution-time limits and report partial item
  failures without replaying successful items as new facts.
- Cache keys and queue identities include WordPress site scope and schema/policy
  versions needed for safe invalidation.
- Scale components do not weaken tenant isolation, consent, replay protection,
  audit or retention.

## Event-ingestion contract

P30 defines batch authentication, item schema version, maximum count/bytes,
ordering assumptions, per-item idempotency and response semantics. A batch
transport success does not make every invalid item successful; results are
specific enough for a client to retry only retryable failures.

Durable local storage precedes acknowledgement unless an explicitly configured
external durable system provides equivalent evidence. Aggregation remains
server-side. A client cannot submit pre-aggregated billable totals or choose
trusted dimensions to reduce server work.

Buffer draining, retry, crash recovery and poison-item handling are observable
and bounded. Retention cannot delete buffered or ledger data required by an
unfinished projection.

## Observability contract

P31 standardizes request, page-decision, decision, event, job, webhook and
provider-attempt ids so one operation can be traced across synchronous and
background boundaries. Structured logs have a versioned field allowlist and
central redaction rules.

Health signals distinguish configuration, availability, lag, correctness and
capacity. At minimum they cover:

- database/schema version and incomplete or failed migrations;
- WP-Cron scheduling, last run, duration and missed work;
- decision/fill latency, no-fill reasons and candidate volume;
- event ingestion durability, duplicates, buffer depth and projection lag;
- provider latency, errors, circuits and fallbacks;
- object-cache availability, hit rate and stampede behavior;
- webhook and notification backlog;
- table size, growth, retention and forecasted exhaustion; and
- accessibility/performance release-gate results.

Metrics avoid unbounded labels. Logs and traces never contain credentials,
signing tokens, private creative bytes, payment data, raw IPs or unnecessary
personal data. Telemetry failure never blocks fill or durable business writes.

## Performance and capacity contract

P32 records an authoritative environment and reproducible dataset for every
budget. Minimum fixtures include thousand-Campaign placement competition,
many-creatives-per-line-item, nested targeting, concurrent caps/counters, batch
page decisions, sustained event ingestion, large rollups and multi-organization
reports.

Every critical path has:

- cold and warm query budgets;
- p50, p95 and p99 latency targets where statistically meaningful;
- memory and payload limits;
- lock/contention and concurrency expectations;
- cache-hit, cache-miss and dependency-outage measurements;
- capacity or saturation threshold and expected backpressure; and
- a regression threshold that fails CI or an authoritative scheduled lane.

Benchmarks that only print numbers are diagnostics, not gates. Gates account for
environment variance without widening until regressions become invisible.

## Accessibility contract

Accessibility is a design and implementation invariant, not a final audit fix.
Every earlier phase adds semantic labels, keyboard behavior, focus management,
error association, status announcements, reflow and colour-independent state as
its UI ships.

P33 audits the combined advertiser, publisher and staff journeys against the
documented WCAG target. It includes keyboard-only operation, 200% zoom,
320-CSS-pixel reflow, text-spacing overrides, reduced motion, high contrast,
screen-reader semantics and axe coverage. Charts and decision/reporting visuals
provide equivalent text or table access.

External provider UI, hosted payment return states and rich creative preview
must have a documented accessibility boundary and accessible fallback; a vendor
surface is not silently claimed as plugin conformance.

## Intelligence safety contract

P34 consumes versioned, permission-filtered domain and reporting data. Training,
retrieval or provider disclosure follows P27 consent and data-governance rules.
Organization data is not used to improve another tenant's suggestions without a
separate explicit policy and authority.

Every suggestion records input scope, model/rule version, generated time,
confidence or uncertainty where meaningful, explanation, actor who accepted or
rejected it, and the ordinary workflow that applied it. Suggestions are
reviewable, reversible and never presented as guaranteed outcomes.

The intelligence layer may not directly:

- publish or approve a Campaign or Creative;
- charge, refund, credit, invoice or alter a ledger;
- change consent, retention or data-sharing policy;
- create/revoke credentials or grant roles;
- override targeting, frequency, traffic-quality or supply-chain safeguards;
- alter a reservation or delivery guarantee; or
- send external messages or webhooks as a human without confirmation.

Prompt or retrieved content is untrusted input. Tool access uses narrow scopes,
output is schema-validated, sensitive fields are minimized/redacted, and
provider outage leaves core workflows fully usable.

## Failure, recovery and rollback

Every optional scale or intelligence component has a tested disable path.
Queues and buffers can be drained, replayed and inspected without arbitrary SQL.
Stale locks, poison items, unavailable cache/provider, telemetry failure and
partial batches each have explicit recovery.

Rolling back code preserves unknown queued jobs, durable events and newer
configuration. Operators can pause producers before incompatible consumers are
deployed. No rollback instruction begins by deleting a queue, event ledger or
financial/audit history.

## Required group evidence

Across P29–P34, detailed phase tests must collectively prove:

- default-only operation with no external cache, queue or telemetry service;
- semantic parity across local and optional implementations;
- lock ownership, expiry, contention and crash recovery;
- batch size limits, mixed item results, replay, poison handling and
  backpressure;
- acknowledgement only after durable recoverability;
- cross-site queue/cache isolation and tenant-safe worker execution;
- structured correlation and redaction across synchronous/background paths;
- truthful health states for missing schedules, lag, outage and recovery;
- cold/warm query, latency, memory and concurrency budgets on representative
  fixtures;
- sustained load and soak behavior with bounded growth and cleanup;
- keyboard, focus, reflow, text spacing, contrast, reduced-motion,
  screen-reader and axe behavior across all surfaces;
- intelligence permission filtering, hostile retrieved content, schema-invalid
  output, human confirmation, audit and provider outage;
- hard refusal of every prohibited intelligence action; and
- the complete P0 baseline after every phase.

## Group exit criteria

The Scale and Assurance group is complete only when P29–P34 are individually
`[x]` and:

1. Optional scale components solve measured needs without becoming baseline
   dependencies or changing domain semantics.
2. Event batching and buffering preserve authenticated, replay-safe durability
   under backpressure and failure.
3. Operators can detect, correlate and recover every critical asynchronous and
   external path without leaking sensitive data.
4. Explicit performance and capacity budgets pass on representative fixtures.
5. Every shipped surface meets the accessibility contract with automated and
   manual evidence.
6. Intelligence remains a permission-filtered, explainable and reversible
   suggestion layer with tested hard authority limits.
7. The authoritative quality baseline is green and documentation accurately
   describes deployment, degradation and recovery.
