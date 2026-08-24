# Platform measurement group contract

This contract governs P10 through P14. Together they establish a versioned
measurement vocabulary, trustworthy collection and attribution, durable
analytics dimensions, and reporting that reads projections rather than the hot
event ledger. Each phase needs its own detailed definition from
[platform-phase-definition-template.md](platform-phase-definition-template.md)
before work starts.

Nothing in this document marks P10–P14 as started or complete.

## Group outcome

Publishers and advertisers can distinguish demand, delivery, attention,
response and attributed outcomes without changing history when a report is
rerun. Every accepted event has an authenticated lineage from request or
decision through its projection, duplicates are refused, invalid traffic can be
separated later, and reporting remains bounded as the ledger grows.

## Entry and ordering

- P10 defines the event lifecycle before P11, P12 or P14 consume it.
- P10's migration must move the meaning of legacy `impression` and its rollups
  together; a label-only change is not acceptable.
- P11 and P12 require signed P3 decision identifiers where attribution or replay
  protection depends on a served ad.
- P12 is gated by P27 wherever click-through or view-through attribution uses
  an identifier requiring consent.
- P13 follows stable P10–P12 event shapes and versions their dimensions.
- P14 reads P13 projections and may not make raw-ledger scans an ordinary report
  path.
- P6 cannot claim goal pacing complete until P10 and P13 expose suitable
  delivery counters.

## Canonical measurement lifecycle

The group must define one versioned relationship among:

- **request** — an opportunity presented to decisioning;
- **fill** — a decision returned an ad payload;
- **no_fill** — no ad was returned, with a stable primary reason;
- **served** — the payload reached the client-defined served boundary;
- **viewable** — the rendered creative met the configured visibility and time
  threshold;
- **click** — a valid signed click hop was accepted; and
- **conversion** — a configured outcome was accepted and attributed under a
  declared model and window.

The definitions must state what produces each event, whether it is client- or
server-observed, which predecessor identifiers it references, what replay key
it uses and which failures are recorded rather than silently dropped.

## Phase boundaries

### P10 — Measurement model

Owns event names, meanings, lineage, explicit no-fill reasons and the migration
from legacy `impression` to `served`. It also owns the compatibility policy for
old clients and reports during transition.

### P11 — Viewability

Owns browser observation using IntersectionObserver and Page Visibility,
continuous-time calculation, configurable thresholds, once-per-decision
recording and graceful degradation. Lack of support or blocked JavaScript never
blocks delivery and is not falsely recorded as non-viewable.

### P12 — Conversion tracking

Owns conversion definitions, browser and server-to-server ingestion,
idempotency keys, attribution windows and model. Attribution derives from
signed identifiers and trusted server configuration, never a client-supplied
Campaign id. Commerce adapters remain optional.

### P13 — Event and analytics schema

Owns schema versions, normalized dimensions, indexes, staged migration and
projection contracts. It must separate durable facts from dimensions that may
change later and review write amplification against measured query needs.

### P14 — Reporting

Owns publisher and advertiser report semantics, date and timezone behavior,
comparison periods, CSV export and the architecture for scheduled delivery. It
reads bounded rollups, reports freshness and never hides incomplete projection
state.

## Cross-phase invariants

- The append-first event ledger is durable truth; rollups are reproducible
  projections with named schema and projector versions.
- Event acceptance and duplicate refusal are atomic at the database boundary.
- A retry of the same observation cannot increment a report twice.
- Client input cannot choose organization, Campaign, line item, creative,
  placement, value or attribution when those facts can be derived from signed
  server state.
- Event timestamps distinguish server receipt from trusted occurrence time and
  define acceptable skew.
- Historical events retain their original dimensional meaning when names,
  assignments or organization metadata later change.
- Migration never changes the shape or totals of history silently.
- Retention does not delete ledger rows required to reconcile an unfinished
  projection.
- Reports expose freshness, timezone, filters and metric definitions; zero,
  unavailable and not-applicable are not conflated.
- House and external-provider events cannot be attributed to an advertiser by
  accidental id reuse.

## Privacy, security and traffic integrity

Every identifier has a documented purpose, scope, lifetime, consent condition
and erasure behavior. Raw IP addresses are never stored. Hashing or signing does
not turn personal data into non-personal data, and no cross-site identity is
constructed.

Public event endpoints authenticate lineage, enforce event-specific replay
rules, validate origin/context where reliable, rate-limit abuse and cap payload
size. Server-to-server credentials are scoped and revocable. Conversion value
and currency come from trusted definitions or authenticated integrations.

P28 may later classify valid, suspicious and invalid traffic, so P10–P13 must
preserve the reason metadata and raw-versus-valid separation needed for that
classification without presenting a weak heuristic as fraud detection.

## Migration and compatibility contract

P10 and P13 migrations touch high-volume durable data and must be staged. A
phase definition must specify dual-read or dual-project behavior, batch and lock
limits, cursors, pause/resume, failure records, reconciliation, cutover checks,
rollback and the condition for deleting compatibility code.

Legacy `impression` history becomes `served` only through an explicit mapping
that updates or reprojects all dependent rollups consistently. During cutover,
the same source row may not appear under both names or disappear from both.

Old browser payloads and signed tokens need a bounded compatibility window with
version-aware parsing. Unknown future versions fail explicitly and safely.

## Durability and failure contract

Event recording is durable before success is returned. Projection, email and
analytics failures never roll back or discard an accepted ledger event. Failed
projection work is retryable and observable, with idempotent replay from a
watermark.

Viewability and conversion collection degrade independently from delivery. A
reporting outage does not stop fills; an ingestion outage returns an explicit
retryable or terminal result and does not claim success before durability.

## Performance and scale contract

Event writes have fixed query and index budgets independent of ledger size.
Decision/fill requests do not synchronously rebuild reports. Projection runs in
bounded batches with measurable lag. Ordinary reports use selective rollup
indexes and bounded date ranges; CSV and scheduled work stream or page results
rather than loading an export into memory.

Each detailed phase definition records expected daily event volume, retention,
write amplification, table growth, cold/warm latency and query plans on the
authoritative MySQL version.

## Reporting contract

Metric names, numerators, denominators and unavailable states are documented.
At minimum, request, fill, no-fill, served, viewable, click and conversion totals
must reconcile through their declared relationships. Fill rate cannot be
computed from served events alone, and viewability rate must state its eligible
denominator.

Organization access is applied in SQL before aggregation or export. CSV uses
the same filters and metric definitions as the screen, prevents spreadsheet
formula injection, records timezone and range, and remains auditable.

## Observability contract

Operations can see ingestion success/failure, duplicate rate, invalid lineage,
projection watermark and lag, reconciliation differences, retention progress,
table size and report latency. Request, decision, event and conversion ids are
correlatable without logging tokens, secrets or raw personal data.

Alerts distinguish collection loss from delayed projection and report-serving
failure; those conditions require different recovery actions.

## Required group evidence

Across P10–P14, detailed phase tests must collectively prove:

- event lifecycle definitions, predecessor lineage and explicit no-fill reasons;
- atomic replay refusal for every event type;
- exact legacy-impression migration with unchanged historical totals;
- viewability thresholds, continuous time, tab visibility and unsupported
  browser behavior;
- conversion definition, signed attribution, window boundaries and browser/S2S
  deduplication;
- consent changes, retention and erasure behavior;
- staged high-volume migration, interruption, resume and rollback;
- projection idempotency, lag, reconciliation and retention ordering;
- report metric math, timezone/date boundaries, comparison periods and
  freshness states;
- tenant isolation in screens, SQL aggregation, CSV and scheduled delivery;
- formula-safe, bounded exports;
- fixed event-write and bounded report-read budgets at realistic volume;
- dependency failure without delivery interruption; and
- the complete P0 baseline after every phase.

## Group exit criteria

The Measurement group is complete only when P10–P14 are individually `[x]` and:

1. Every metric has a stable definition and authenticated event lineage.
2. Legacy history and new events reconcile without duplication or silent loss.
3. Viewability and conversion collection are replay-safe, privacy-aware and
   non-blocking to delivery.
4. Versioned schemas and rollups scale independently of ledger size.
5. Advertiser and publisher reports are tenant-safe, reproducible and explicit
   about freshness and unavailable data.
6. Operations can detect, explain and recover collection or projection failure.
7. The authoritative quality baseline is green and documentation describes the
   shipped measurement system.
