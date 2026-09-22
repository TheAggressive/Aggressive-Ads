# Platform implementation progress

This document is the **high-level status and dependency map** for the platform
roadmap. It is not the live implementation backlog.

- Architecture, invariants, migrations, failure semantics and exit evidence live
  in the phase/group documents.
- Actionable work lives in GitHub Issues.
- The work-tracking convention and current execution order live in
  [work-tracking.md](work-tracking.md).

Nothing is marked complete because a class or interface exists. A phase is
complete only when it is functional end to end, migrated, tested, documented
and its exit evidence is recorded.

Status keys:

- `[ ]` pending
- `[~]` in progress
- `[x]` complete
- `[!]` blocked on credentials/environment rather than implementation

## Current product baseline

Aggressive Ads already has the direct-sold advertising core that the original
roadmap set out to build:

- campaign lifecycle and guarded transitions;
- organization-scoped ownership;
- private creative storage and reviewed revisions;
- native delivery and a page-level decision engine;
- exact scheduling, priority, weighting, pacing and targeting;
- frequency capping;
- request/fill/no-fill/served/viewable/click/conversion measurement;
- viewability and click-through conversion attribution;
- rollup-backed advertiser/publisher reporting and CSV export;
- inventory management, forecasting and reservations;
- packages, organizations and advertiser portal workflows;
- audit/history, operational hardening and reproducible packaging.

The detailed proof for completed phases remains in their phase documents. Do not
recreate a second historical narrative here.

## Platform phases

### Foundations

- [x] **P0 — Baseline and regression safety.** See
  [platform-p0-baseline.md](platform-p0-baseline.md).
- [x] **P1 — Line item domain.** See
  [platform-p1-line-item-closeout.md](platform-p1-line-item-closeout.md).
- [x] **P2 — Creative model refactor.** See
  [platform-p2-creative-model.md](platform-p2-creative-model.md).

### Serving

Shared contract: [platform-serving-contract.md](platform-serving-contract.md).

- [x] **P3 — Decision engine.** See
  [platform-p3-decision-engine.md](platform-p3-decision-engine.md).
- [x] **P4 — Exact scheduling.** See
  [platform-p4-exact-scheduling.md](platform-p4-exact-scheduling.md).
- [x] **P5 — Priority, weight and share of voice.** See
  [platform-p5-priority-weight-sov.md](platform-p5-priority-weight-sov.md).
- [x] **P6 — Delivery goals and pacing.** See
  [platform-p6-delivery-goals-pacing.md](platform-p6-delivery-goals-pacing.md).
- [x] **P7 — Page-level batch decisions.** See
  [platform-p7-page-level-batch-decisions.md](platform-p7-page-level-batch-decisions.md).
- [x] **P8 — Targeting rule engine.** See
  [platform-p8-targeting-rule-engine.md](platform-p8-targeting-rule-engine.md).
- [x] **P9 — Frequency capping.** See
  [platform-p9-frequency-capping.md](platform-p9-frequency-capping.md).

### Measurement

Shared contract:
[platform-measurement-contract.md](platform-measurement-contract.md).

- [x] **P10 — Measurement model.** See
  [platform-p10-measurement-model.md](platform-p10-measurement-model.md).
- [x] **P11 — Viewability.** See
  [platform-p11-viewability.md](platform-p11-viewability.md).
- [x] **P12 — Conversion tracking.** Click-through attribution is built;
  identifier-dependent expansion remains gated by P27.
- [x] **P13 — Event and analytics schema.** See
  [platform-p13-event-analytics-schema.md](platform-p13-event-analytics-schema.md).
- [x] **P14 — Reporting.** Closed 2026-09-02. See
  [platform-p14-reporting.md](platform-p14-reporting.md). Scheduled client
  delivery is now tracked separately in #279 rather than as a hidden unfinished
  sentence in this phase.

### Inventory and commerce

Shared contract:
[platform-inventory-commerce-contract.md](platform-inventory-commerce-contract.md).

- [x] **P15 — Inventory management.** See
  [platform-p15-inventory-management.md](platform-p15-inventory-management.md).
- [x] **P16 — Forecasting and reservations.** See
  [platform-p16-forecasting-reservations.md](platform-p16-forecasting-reservations.md).
  Advertiser-facing booking is intentionally separate and tracked in #280.
- [x] **P17 — Creative experience.** Tracking: #260. Per-creative measurement,
  variant management, comparison, durable review history and exact-revision
  device preview are built. See
  [platform-p17-creative-experience.md](platform-p17-creative-experience.md).
- [ ] **P18 — Rich creative types.** Tracking: #262. Responsive images,
  sandboxed HTML5, staff-only third-party tags and VAST-compatible video remain
  behind the creative-handler boundary.
- [ ] **P19 — Billing domain.** Tracking: #263. Accounts, orders, invoices,
  payments, credits, refunds and an immutable commercial ledger; hosted payment
  flows only; delivery reads local commercial eligibility.
- [ ] **P20 — Publisher workflow.** Tracking: #264. Review/work ownership, bulk
  actions, renewals, expiry, underdelivery and make-goods. Commercially
  meaningful renewal/make-good work is blocked on P19.

### Platform/API/privacy

Shared contract:
[platform-api-privacy-contract.md](platform-api-privacy-contract.md).

- [ ] **P21 — Organization-scoped RBAC.** Tracking: #265. Replaces the current
  one-organization-per-user assumption deliberately.
- [ ] **P22 — Public API and service accounts.** Tracking: #266. Blocked on P21.
- [ ] **P23 — Webhooks.** Tracking: #267. Builds on P21/P22 authority and
  machine identities.
- [ ] **P24 — Provider system.** Tracking: #268. Capability registry and
  graceful fallback; every provider may be disabled.
- [ ] **P25 — Programmatic foundations.** Tracking: #269. Standards integration,
  not a DSP or SSP.
- [ ] **P26 — Supply chain and ads.txt.** Tracking: #270. Publisher-controlled,
  diffable and reversible.
- [ ] **P27 — Privacy and consent.** Tracking: #271. This is a **logical gate**
  for identifier-dependent behavior and must not be postponed merely because its
  phase number is later.
- [ ] **P28 — Traffic quality.** Tracking: #272. Explainable classification with
  raw-versus-valid reporting; classification alone is not irreversible
  authority.

### Scale and assurance

Shared contract:
[platform-scale-assurance-contract.md](platform-scale-assurance-contract.md).

- [ ] **P29 — Scalability abstractions.** Tracking: #273. Introduce seams only
  for measured bottlenecks; ordinary WordPress/MySQL/object-cache operation
  remains the baseline.
- [ ] **P30 — Event ingestion scale.** Tracking: #274. Authenticated batching,
  buffering, backpressure and replay-safe server aggregation.
- [ ] **P31 — System health and observability.** Tracking: #275. Structured
  correlation, health signals and recoverable operational paths.
- [ ] **P32 — Testing and performance.** Tracking: #276. Representative fixtures
  and explicit capacity/query/latency release gates.
- [ ] **P33 — Accessibility.** Tracking: #277. Cross-cutting throughout earlier
  UI phases; the final P33 closeout audits the complete platform rather than
  postponing accessibility until the end.
- [ ] **P34 — Intelligence layer.** Tracking: #278. Bounded, explainable,
  reversible suggestions only; never direct authority over publication,
  billing, privacy, credentials or delivery guarantees.

## Competitive product track

The platform phases above remain the architectural backbone. Competitive product
work that uses those capabilities is tracked under #258 rather than being mixed
into phase definitions:

- #279 scheduled white-label advertiser reporting;
- #280 advertiser inventory storefront and booking;
- #281 reusable campaign templates / sales-product presets;
- #282 proposal, quote and insertion-order workflow;
- #283 publisher sales and revenue dashboard;
- #284 deterministic renewal and sales intelligence;
- #285 CRM integration boundary;
- #286 cross-channel campaign and line-item model;
- #287 newsletter advertising; and
- #288 sponsored-content/native-sponsorship workflow.

See [work-tracking.md](work-tracking.md) for their dependency order.

## Dependencies that materially affect ordering

The issue bodies are the live source for blockers. The durable dependency rules
worth keeping here are:

- P17 closes before richer creative formats expand the review/preview surface.
- P19 supplies authoritative commercial facts before P20 renewals/make-goods and
  revenue dashboards can be commercially meaningful.
- P21 changes organization authority before P22/P23 machine identities depend on
  it.
- P27 gates identifier-dependent behavior in frequency, conversion and future
  provider/programmatic work regardless of its numeric position.
- Cross-channel campaigns (#286) land before newsletter (#287) and sponsored
  content (#288), so those channels do not create parallel campaign models.
- P29 follows measured bottlenecks; P30 depends on its durability seams; P31
  observes real paths; P32 turns their measured behavior into gates.
- P33 accessibility applies during every UI phase, not after P32.
- P34 starts only when domain, measurement, privacy and audit data are mature
  enough to constrain and evaluate suggestions.

## Migration concerns that remain durable

Completed migration history belongs in the relevant phase documents. These
future risks remain important:

- **P21 changes a documented invariant.** One organization per user is relied on
  by call sites that choose the first org; they must be removed deliberately.
- **New post type/status slugs must respect WordPress storage limits.** Do not add
  status names that truncate into unreachable rows.
- **Cross-channel work must migrate every existing campaign as native web**
  without asking users to recreate it.
- **Financial and audit history is append/correction oriented.** Rollback may
  disable newer workflows, but it must not delete ledger, reservation, message,
  event or audit history merely because older code cannot present it.

## How to work from this document

Do not add detailed implementation checklists here.

1. Open the phase or product Issue.
2. Confirm its named dependencies are complete.
3. If the phase lacks a detailed definition, define it using
   `platform-phase-definition-template.md` before implementation.
4. Create PR-sized action issues from the accepted definition.
5. Close action issues with implementation PRs.
6. At phase closeout, record durable exit evidence in the phase doc, update this
   status index and close the umbrella Issue.

See [work-tracking.md](work-tracking.md) for the complete convention.