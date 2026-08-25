# Platform implementation progress

The sequence that takes Aggressive Ads from a direct-sold advertiser portal with
native serving to a privacy-first advertising platform for WordPress publishers.

`roadmap.md` records the product phases that built what exists today; this file
records the platform phases ahead of it and their real status. Both obey the
same rule: **nothing is marked complete because a class or an interface exists.**
Complete means functional end to end, migrated, tested, and documented.

- `[ ]` pending
- `[~]` in progress
- `[x]` complete
- `[!]` blocked on credentials or an environment rather than on a commit

## What exists today

Audited against the source rather than against the documentation.

| Capability | State | Where |
|---|---|---|
| Campaign lifecycle, 11 statuses, 22 legal edges | Built | `Domain\Transition_Table`, `Workflow\Campaign_State_Machine` |
| Org-scoped tenancy and `map_meta_cap` ownership | Built | `Security\Ownership`, `Repository\Org_Repository` |
| Private creative storage, two-stage promotion | Built | `Workflow\Creative_Manager` |
| Native delivery, fill cache, signed click hop | Built | `Integration\Native\Publisher`, `Domain\Fill_Rotation` |
| Append-first event ledger with rollup projections | Built | `Repository\Event_Repository`, `Workflow\Reporting_Read` |
| Provider abstraction | Built, one implementation | `Integration\Ad_Provider_Interface` |
| Packages, inventory placements, organizations | Built | `Admin\*_Screen`, `Repository\Placement_Repository` |
| Audit trail on business transitions | Built | `Repository\Audit_Repository` |
| Line-item delivery strategy beneath Campaign | Built; serving cutover waits for P3 | `Repository\Line_Item_Repository`, `Workflow\Line_Item_Editor` |

### What is thinner than it looks

- **Rotation is not decisioning.** `Fill_Rotation` selects equally among live
  campaigns on a slot. There is no eligibility pipeline, no targeting, no
  frequency, no pacing, and no way to ask why a candidate lost.
- **Two event types.** `TYPE_IMPRESSION` and `TYPE_CLICK`. There is no request,
  fill, no-fill, served, viewable or conversion event, so fill rate and
  viewability cannot be computed from the ledger at all.
- **Serving still selects Campaigns.** P1 models line-item delivery strategy,
  while the native hot path intentionally stays campaign-based until P3 can
  cut it over to the decision engine as one tested change.
- **One creative per placement** is assumed by campaign validation.
- **"Billing" is a settings label**, not a domain. No orders, invoices, payments
  or ledger exist.
- **No targeting, frequency, viewability, conversion, forecasting, webhook or
  consent code exists** — confirmed by inspection, not inferred.

## Phases

Ordered by dependency. A phase should not start before the one it rests on is
stable, and the ordering is not negotiable where a later phase reads a table an
earlier one creates.

### Foundations

- [x] **P0 — Baseline and regression safety.** Every named behavior maps to
      focused executable coverage, and the PHP, WordPress, multisite, browser,
      accessibility, static-analysis, build, workflow and dependency-security
      baselines are green. Environment/version caveats and the existing
      non-failing advisories are recorded rather than hidden. See
      [platform-p0-baseline.md](platform-p0-baseline.md).
- [x] **P1 — Line item domain.** The delivery unit beneath Campaign. Dedicated
      table rather than postmeta; pricing models, goal types, pacing modes,
      priority, weight, targeting rules and frequency policy as columns.
      *Migration: every existing campaign must behave as one default line item
      without recreating anything, and without interrupting a serving ad.* See
      [data-schema.md](data-schema.md#campaign-line-items). The six closure
      items, their evidence, and the exit criteria they satisfy are recorded in
      [platform-p1-line-item-closeout.md](platform-p1-line-item-closeout.md).
      Serving still selects Campaigns, deliberately: the line item is a
      projection until P3 cuts the hot path over as one tested change.
- [~] **P2 — Creative model refactor.** Many creatives per line item and
      placement, with weight, dates, status and revision history. Campaign
      validation stops failing on a second creative and starts requiring one
      eligible approved creative per required combination. Scope, migration,
      invariants and exit evidence are defined in
      [platform-p2-creative-model.md](platform-p2-creative-model.md). The
      metadata-ownership decision that document requires before schema work is
      recorded there; nothing is built yet.

### Serving

Shared boundaries and group exit criteria:
[platform-serving-contract.md](platform-serving-contract.md).

- [ ] **P3 — Decision engine.** Replaces rotation. Request, context, candidate,
      result and trace; eligibility, targeting, frequency, pacing, priority,
      creative selection and competition as separable services. Must explain
      exclusions. Traces are staff-only.
- [ ] **P4 — Exact scheduling.** Serve-time timestamp evaluation with timezone
      and daypart support. Cron reconciliation stays for lifecycle state, but
      stops being the serving authority — a line item ending at 10:30 stops at
      10:30.
- [ ] **P5 — Priority, weight, share of voice.** Configurable tiers by value
      rather than by hard-coded product name, weighted rotation within a tier.
- [ ] **P6 — Delivery goals and pacing.** Impression, click, conversion, spend
      and SoV goals; daily and lifetime caps; EVEN and ASAP pacing against
      elapsed time rather than fixed daily quotas. Needs delivery counters that
      avoid `COUNT(*)` on the event table during a fill.
- [ ] **P7 — Page-level batch decisions.** `POST /aggr/v1/decisions` returning
      every slot at once, enabling roadblocks, competitive separation and
      page-level frequency. Single-slot fill stays for compatibility.
- [ ] **P8 — Targeting rule engine.** Schema-validated nested AND/OR with
      exclusions, never executable input. WordPress, request, user, time and geo
      dimensions; geo behind an abstraction so no vendor is wired into core.
- [ ] **P9 — Frequency capping.** Session, hour, day and rolling windows at
      campaign, line-item and creative level. No fingerprinting; expiring
      storage behind an interface so an object cache can back it at scale.

### Measurement

Shared boundaries and group exit criteria:
[platform-measurement-contract.md](platform-measurement-contract.md).

- [ ] **P10 — Measurement model.** Split the lifecycle into request, fill,
      no_fill, served, viewable, click and conversion, with explicit no-fill
      reasons. *Migration: today's `impression` becomes `served` without losing
      history.*
- [ ] **P11 — Viewability.** IntersectionObserver and Page Visibility, 50% for
      one continuous second by default and configurable. Once per decision,
      replay protected, and never blocking delivery when unavailable.
- [ ] **P12 — Conversion tracking.** Definitions, browser and server-to-server
      endpoints, idempotency keys, click-through and view-through windows.
      Attribution derives from signed identifiers, never from client-supplied
      campaign ids. WooCommerce adapter optional, never a dependency.
- [ ] **P13 — Event and analytics schema.** Normalized dimensions and schema
      versioning on the existing append-first ledger. Indexes from real query
      patterns; write amplification reviewed rather than assumed.
- [ ] **P14 — Reporting.** Advertiser and publisher views from rollups rather
      than raw events, date ranges with comparison, CSV export, and the
      architecture for scheduled email.

### Inventory and commerce

Shared boundaries and group exit criteria:
[platform-inventory-commerce-contract.md](platform-inventory-commerce-contract.md).

- [ ] **P15 — Inventory management.** Placement groups, responsive multi-size
      mapping, categories, house and refresh policy, utilisation dashboard.
- [ ] **P16 — Forecasting and reservations.** Conservative forecasts from
      rolling history, tracked against actuals with error recorded. Oversell
      warns and logs the override rather than silently blocking staff.
- [ ] **P17 — Creative experience.** Variants, A/B tests, schedules, device
      preview, approval and rejection history, performance comparison. Upload
      security is not relaxed to add formats.
- [ ] **P18 — Rich creative types.** Handlers behind an interface: responsive
      image, HTML5 (sandboxed, CSP, no server execution), third-party
      (staff-only), video via VAST-compatible providers.
- [ ] **P19 — Billing domain.** Accounts, orders, invoices, payments, credits,
      refunds and an immutable ledger. Stripe first, hosted flows only, no card
      data. Delivery never calls a payment provider — it reads a commercial
      eligibility state, and overrides are audited.
- [ ] **P20 — Publisher workflow.** Assignment, review ownership, bulk actions,
      renewals, expiry and underdelivery notices, make-goods. Internal notes and
      advertiser messages stay distinct.

### Platform

Shared boundaries and group exit criteria:
[platform-api-privacy-contract.md](platform-api-privacy-contract.md).

- [ ] **P21 — Organization-scoped RBAC.** Owner, admin, campaign manager,
      creative manager, analyst, billing, viewer — scoped per organization, one
      user in several. Layers on top of WordPress capabilities rather than
      replacing them. *This relaxes invariant 9 in `domain-model.md`; that
      invariant and its four `$org_ids[0]` call sites must be revisited
      deliberately, not incidentally.*
- [ ] **P22 — Public API and service accounts.** Versioned surface, scoped
      revocable credentials hashed at rest, rate limited and audited.
- [ ] **P23 — Webhooks.** Signed payloads, event ids, idempotency, retries,
      delivery log, secret rotation.
- [ ] **P24 — Provider system.** Extend the existing interface into a registry:
      native, house, GAM, Prebid/OpenRTB, VAST. External failure degrades
      gracefully and the plugin stays whole with every provider disabled.
- [ ] **P25 — Programmatic foundations.** Integrate standards; build neither a
      DSP nor an SSP. External demand never silently outranks a guarantee.
- [ ] **P26 — Supply chain and ads.txt.** Validation and warnings; never
      overwrite a publisher-controlled file without authorization.
- [ ] **P27 — Privacy and consent.** A first-class abstraction ahead of any
      identifier use — CMP-agnostic, GPP/TCF/GPC aware, retention policy, and
      WordPress exporter/eraser integration.
- [ ] **P28 — Traffic quality.** Valid, suspicious and invalid classification
      with reason metadata. No single weak heuristic presented as fraud
      detection, and dashboards that separate raw from valid.

### Scale and assurance

Shared boundaries and group exit criteria:
[platform-scale-assurance-contract.md](platform-scale-assurance-contract.md).

- [ ] **P29 — Scalability abstractions.** Interfaces only where scale demands
      them; MySQL and the object cache remain the default and nothing new
      becomes required.
- [ ] **P30 — Event ingestion scale.** Batching and buffering without weakening
      durability. Clients send authenticated events; the server does every
      aggregation.
- [ ] **P31 — System health and observability.** Migration state, cron health,
      rollup lag, decision and fill latency, provider and cache health, table
      sizes, with request/decision/event ids in structured logs and no secrets.
- [ ] **P32 — Testing and performance.** Thousand-campaign fixtures, targeting
      and pacing benchmarks, concurrency tests, and explicit query budgets for
      cold and warm decisions, event writes and report reads.
- [ ] **P33 — Accessibility.** Every new surface keyboard-navigable, labelled,
      focus-managed and colour-independent, with axe coverage extended as the
      UI grows.
- [ ] **P34 — Intelligence layer.** Only once the data model is mature.
      Suggestions, never authority: no AI path may charge, publish, alter
      billing, override privacy or change a guarantee.

## Dependencies worth stating

- P3 cannot start before P1 and P2: a decision engine that selects among
  campaigns rather than line items and creatives would be rewritten immediately.
- P6 depends on P10 and P13. Pacing needs delivery counters, and counters need
  event types that distinguish served from requested.
- P11, P12 and P14 all read the P10 schema; building reporting first would mean
  reporting on the wrong events.
- P16 depends on P15 and on enough P13 history to forecast from.
- P19 must land before P20's renewals and make-goods mean anything commercially.
- P27 gates P9 and P12 wherever an identifier is involved, so it cannot be left
  to the end despite its number.

## Migration concerns

- **P1 is the highest-risk migration in the sequence.** Live campaigns are
  serving while it runs. Default line items must be derivable from existing
  campaign state, the migration must be restartable, and no advertiser may be
  asked to recreate anything.
- **P10 rewrites the meaning of an existing event type.** `impression` becomes
  `served`; rollups already project it, so the projection and the history must
  move together or reporting silently changes shape.
- **P13 touches the highest-volume table in the schema.** It needs a staged
  migration rather than a blocking one.
- **P21 changes a documented invariant.** One organization per user is asserted
  in `domain-model.md` and relied upon by four call sites that take
  `$org_ids[0]`. Those are named there for exactly this moment.
- `wp_posts.post_type` and `post_status` are `varchar(20)`. Any new status slug
  is truncated silently past that, producing rows that exist and cannot be
  queried.

## Test coverage

Already present and worth extending rather than replacing: transition-table
exhaustiveness, ownership and tenant isolation, REST authorization, upload
security, upgrade/migration walking, delivery query budgets, multisite id
collision, and the axe-backed portal and admin suites.

Needed, and absent today: targeting evaluation, frequency windows, pacing
decisions, weighted selection distribution, batch-decision coordination,
conversion deduplication, viewability replay protection, forecast error, and
concurrency around counters.
