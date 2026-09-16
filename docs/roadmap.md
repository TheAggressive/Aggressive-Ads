# Roadmap

This document records the product capability sequence that built Aggressive Ads.
Completed phases stay here as durable product history. **Actionable future work
lives in GitHub Issues**, not in a second checklist inside this file.

- Suite/product direction: [suite-roadmap.md](suite-roadmap.md)
- Platform status: [platform-implementation-progress.md](platform-implementation-progress.md)
- Work-tracking rules and execution order: [work-tracking.md](work-tracking.md)
- Roadmap tracking migration: #259
- Competitive product expansion: #258

Nothing is complete merely because the architecture can support it.

## Phase 1 — Foundation *(complete)*

Bootstrap, autoloader, container, private post types/statuses, installer/schema
upgrader, audit log, roles and organization-scoped ownership, design tokens,
portal routing, CI, reproducible packaging, SHA-256-verifying updater and
release automation.

**Outcome:** the plugin installs/upgrades/uninstalls safely, enforces its
capability model, routes its portal under any theme and packages reproducibly.

## Phase 2 — Domain layer *(complete)*

Repositories, domain value objects, campaign validation and the campaign state
machine with exhaustive legal/illegal transition coverage. Placement/package
resolution belongs to the domain/workflow boundaries rather than UI handlers.

## Phase 3 — Creative upload *(complete)*

Private two-stage creative storage, MIME/dimension/integrity validation,
authenticated streaming, rate limiting and upload-threat regression coverage.
See [threat-model.md](threat-model.md).

## Phase 4 — Portal UI *(complete)*

Dashboard, campaign list/detail, organization, account and the advertiser
campaign flow.

The current campaign wizard is **three steps**; the retired five-step flow's
stored resume points are mapped on read:

1. package & dates;
2. ads, with one destination link for the campaign; and
3. review & submit.

The complete flow works without JavaScript, including draft creation, package
snapshot, exact-size private creative upload, authenticated preview/removal,
a campaign destination link, scheduling, review and submission,
transition-time revalidation, audit and reviewer notification. JavaScript adds
progressive autosave/upload/dialog behavior rather than being the only path.

Atomic replacement for scheduled/live ads is also built: advertisers stage
private revisions without interrupting delivery, staff review them, and approval
reconciles publication with read-back/rollback semantics.

Public advertiser signup, portal-owned password setup/recovery/sign-in and
self-service account/organization workflows are built while WordPress core owns
user authentication, password hashing and sessions.

## Phase 5 — Staff review and notifications *(complete)*

Capability-gated staff UI, review queue, campaign detail, authenticated creative
preview, internal notes, required advertiser-facing feedback, approval controls
and scoped audit timeline.

Notification infrastructure includes capability-resolved recipients, duplicate
suppression, bounded partial retry and failure handling that never reverses a
successful business transition. See [notifications.md](notifications.md).

## Phase 6 — Publisher *(complete; native provider)*

`Ad_Provider_Interface` is implemented by the native publisher. Placements are
the slot catalogue. There is no AdSanity adapter/downstream ads CPT; native fill
is the baseline publisher.

## Phase 7 — Lifecycle automation *(complete)*

The campaign clock reconciles approved → scheduled → live → complete while
serve-time guards remain authoritative. Pause/resume/cancel are state-machine
operations. Ending-soon notifications and private-file retention purge are
built.

## Phase 8 — Organizations and members *(complete)*

Organization creation, owner/member administration, invitation/approval/revoke,
rename/collision handling, member removal, ownership transfer, self-service
email change and staff suspension controls are built.

The later multi-organization-per-user authorization expansion is a separate
platform phase tracked in P21 (#265); it is not unfinished Phase 8 work.

## Phase 9 — Packages and pricing *(complete)*

Validated package catalogue, advertiser selection, displayed pricing, campaign
snapshot, staff management and campaign copy/renew behavior are built.

Payment processing was deliberately excluded from this phase. The real billing
and commercial ledger is P19 (#263), not an implied capability of price fields.

## Phase 10 — Reporting *(complete)*

Org-scoped delivery metrics, campaign list/detail reporting, REST reporting and
bounded CSV export are built from rollups rather than raw event scans.

The expanded measurement/reporting platform work is P10–P14 and is recorded in
[platform-implementation-progress.md](platform-implementation-progress.md).
Scheduled client delivery is now an explicit product issue (#279) rather than an
implicit gap in this completed phase.

## Phase 11 — Hardening and launch *(complete)*

Delivery query budgets, Site Health checks, persistent-cache rate limiting,
rollup reconciliation, bounded event retention, rewrite-health repair,
administrator/runbook documentation, authorization/failure-state review,
audit-table load testing and concurrent soak testing are complete for the
recorded reference environments.

The recorded soak profile sustained 463.22 complete views/second at 64 clients
with 1,000 eligible ads, ~94.31 ms p95 and ~132.55 ms p99, zero request errors
and exact durable-ledger/reporting projection agreement. See
[load-and-soak-testing.md](load-and-soak-testing.md). Each production topology
still requires its own qualification.

## Platform expansion

The current platform phase sequence is no longer maintained as prose checkboxes
here. It is summarized in
[platform-implementation-progress.md](platform-implementation-progress.md) and
tracked by Issues:

- P17 #260 (remaining action #261)
- P18 #262
- P19 #263
- P20 #264
- P21 #265
- P22 #266
- P23 #267
- P24 #268
- P25 #269
- P26 #270
- P27 #271
- P28 #272
- P29 #273
- P30 #274
- P31 #275
- P32 #276
- P33 #277
- P34 #278

The durable group contracts remain authoritative for architecture and exit
rules:

- [platform-inventory-commerce-contract.md](platform-inventory-commerce-contract.md)
- [platform-api-privacy-contract.md](platform-api-privacy-contract.md)
- [platform-scale-assurance-contract.md](platform-scale-assurance-contract.md)

## Competitive product expansion

Capabilities previously described as merely “architected for” are now either
sequenced platform phases or explicit product issues under #258.

Current product issues include:

- #279 scheduled white-label advertiser reports;
- #280 advertiser inventory storefront/booking;
- #281 campaign templates / reusable sales-product presets;
- #282 proposal, quote and insertion-order workflow;
- #283 publisher sales/revenue dashboard;
- #284 deterministic renewal/sales intelligence;
- #285 CRM integration boundary;
- #286 cross-channel campaign model;
- #287 newsletter advertising; and
- #288 sponsored-content/native sponsorship.

Notification channels beyond email and self-service data exports remain ideas,
not roadmap commitments, until they receive an Issue with an outcome,
dependency placement and acceptance criteria.

## Rule for future roadmap changes

Do not add implementation checklists here.

If a new capability is approved:

1. place it in the dependency model;
2. create/update its umbrella Issue;
3. define durable architecture/invariants in docs where needed;
4. create PR-sized action Issues only when the contract is concrete; and
5. close those action Issues with the PRs that implement them.

See [work-tracking.md](work-tracking.md).