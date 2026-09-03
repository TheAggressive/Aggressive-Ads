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
| Native delivery, fill cache, signed click hop | Built | `Integration\Native\Publisher`, `Workflow\Decision_Engine` |
| Append-first event ledger with rollup projections | Built | `Repository\Event_Repository`, `Workflow\Reporting_Read` |
| Provider abstraction | Built, one implementation | `Integration\Ad_Provider_Interface` |
| Packages, inventory placements, organizations | Built | `Admin\*_Screen`, `Repository\Placement_Repository` |
| Audit trail on business transitions | Built | `Repository\Audit_Repository` |
| Line-item delivery strategy beneath Campaign | Built | `Repository\Line_Item_Repository`, `Workflow\Line_Item_Editor` |

### What is thinner than it looks

- **Serving is assignment-only.** Native fill reads
  `Creative_Assignment_Repository::candidates_for_placement()` through the
  decision engine. Paid slots stay empty until the P2 backfill finishes; there
  is no campaign-meta rotation path.
- **Two event types.** `TYPE_IMPRESSION` and `TYPE_CLICK`. There is no request,
  fill, no-fill, served, viewable or conversion event, so fill rate and
  viewability cannot be computed from the ledger at all.
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
      Fill reads assignments through P3; the line item remains the delivery
      strategy record beneath Campaign.
- [x] **P2 — Creative model refactor.** Many creatives per line item and
      placement, with weight, dates, status and revision history. Campaign
      validation stops failing on a second creative and starts requiring one
      eligible approved creative per required combination. Scope, migration,
      invariants and exit evidence are defined in
      [platform-p2-creative-model.md](platform-p2-creative-model.md). The
      metadata-ownership decision, the text-only review lane, the schema, the
      backfill, the freeze-at-approval write path, the coverage service and
      many-creatives-per-placement are recorded there. Delivery threshold
      criteria are fulfilled in P3.

### Serving

Shared boundaries and group exit criteria:
[platform-serving-contract.md](platform-serving-contract.md).

- [x] **P3 — Decision engine.** Replaces rotation. Request, context, candidate,
      result and trace; eligibility, targeting, frequency, pacing, priority,
      creative selection and competition as separable services. Explains
      exclusions. Traces are staff-only. **Enforces P2's exit criterion 3**: the
      delivery threshold of one eligible approved assignment per required
      line-item and placement combination is evaluated via the decision pipeline
      over states `Workflow\Coverage_Service` defines. Scope, boundaries and exit
      criteria are defined in
      [platform-p3-decision-engine.md](platform-p3-decision-engine.md). The
      pipeline, weighted selection, assignment-only serving cutover, staff trace
      route, exclusion metrics, Site Health, scaled query budgets, and metrics
      isolation are implemented and verified.
- [x] **P4 — Exact scheduling.** Serve-time timestamp evaluation with timezone
      and daypart support. Cron reconciliation stays for lifecycle state, but
      stops being the serving authority — a line item ending at 10:30 stops at
      10:30. Evaluated via `Schedule_Stage` inside `Decision_Pipeline`. See
      [platform-p4-exact-scheduling.md](platform-p4-exact-scheduling.md).
- [x] **P5 — Priority, weight, share of voice.** Configurable tiers by value
      rather than by hard-coded product name, weighted rotation within a tier.
      Implemented via `Priority_Stage` and `Priority_Rules` inside `Decision_Pipeline`.
      See [platform-p5-priority-weight-sov.md](platform-p5-priority-weight-sov.md).
- [x] **P6 — Delivery goals and pacing.** Impression, click, conversion, spend
      and SoV goals; daily and lifetime caps; EVEN and ASAP pacing against
      elapsed time rather than fixed daily quotas. Implemented via `Pacing_Stage`
      and `Pacing_Rules` inside `Decision_Pipeline`. See
      [platform-p6-delivery-goals-pacing.md](platform-p6-delivery-goals-pacing.md).
- [x] **P7 — Page-level batch decisions.** `POST /aggr/v1/decisions` returning
      every slot at once, enabling roadblocks, competitive separation and
      creative deduplication. Single-slot fill stays for compatibility.
      Implemented via `Decisions_Controller`, `Page_Decision_Coordinator`, and
      `Page_Coordination_Rules`. See
      [platform-p7-page-level-batch-decisions.md](platform-p7-page-level-batch-decisions.md).
- [x] **P8 — Targeting rule engine.** Schema-validated nested AND/OR with
      exclusions, never executable input. WordPress, request, user, time and geo
      dimensions. Implemented via `Targeting_Stage` and `Targeting_Rules` inside
      `Decision_Pipeline`. See
      [platform-p8-targeting-rule-engine.md](platform-p8-targeting-rule-engine.md).
- [x] **P9 — Frequency capping.** Session, hour, day and rolling windows at
      campaign, line-item and creative level. No fingerprinting; expiring
      storage behind an interface so an object cache can back it at scale.
      Implemented via `Frequency_Stage`, `Frequency_Rules`, and `Frequency_Store`.
      See [platform-p9-frequency-capping.md](platform-p9-frequency-capping.md).

### Measurement

Shared boundaries and group exit criteria:
[platform-measurement-contract.md](platform-measurement-contract.md).

- [x] **P10 — Measurement model.** Split the lifecycle into request, fill,
      no_fill, served, viewable, click and conversion, with explicit no-fill
      reasons. Implemented via `Measurement_Event_Type`, `No_Fill_Reason`,
      `Measurement_Rules`, and `Event_Repository` updates. Migration preserves
      legacy `impression` as an alias for `served` without losing history. See
      [platform-p10-measurement-model.md](platform-p10-measurement-model.md).
- [x] **P11 — Viewability.** IntersectionObserver and Page Visibility, 50% for
      one continuous second by default and configurable. Once per decision,
      replay protected, and never blocking delivery when unavailable. Scope,
      boundaries and exit criteria are defined in
      [platform-p11-viewability.md](platform-p11-viewability.md). The signal is
      client-attested and the contract says so: the server's controls are that
      a `viewable` must carry a signed token that already recorded a `served`,
      and can be spent once. The threshold, the observer, the beacon, the
      projection and the reporting tile shipped first; the browser evidence the
      contract asks for in the Playwright lane followed —
      `viewability.spec.ts` watches a real advertisement enter a real viewport,
      and both halves of it fail under sabotage. The Site Health ratio from the
      contract's observability section shipped with it, so nothing from that
      document remains outstanding.
- [x] **P12 — Conversion tracking.** Definitions, browser and server-to-server
      endpoints, idempotency keys, click-through windows. Attribution derives
      from signed identifiers, never from client-supplied campaign ids. Scope,
      boundaries and exit criteria are defined in
      [platform-p12-conversion-tracking.md](platform-p12-conversion-tracking.md).
      Two decisions were settled there before any code: a conversion gets its
      own append-only table rather than a row in `aggr_events`, whose
      `(token_hash, event)` unique key would permit exactly one conversion per
      fill for all time; and **view-through attribution is defined but not
      shipped**, because it requires the cross-visit identifier P11 explicitly
      declined to invent and P27 exists to gate. Click-through needs none — the
      signed token travels in the destination URL.

      Closeout found two of the contract's required proofs missing, and both are
      worth naming because both had passing suites over them. **Deduplication
      was never proven concurrently**: every test wrote both rows through one
      repository in one process, which a check-then-insert implementation would
      also pass — `ConversionLedgerTest` now refuses a duplicate whose competing
      row was written outside the repository, and counts the write path's
      queries so a read cannot creep in front of the index. **The carrier had no
      browser evidence**, only the hop's `Location` header;
      `click-carrier.spec.ts` now clicks a rendered advertisement and reads the
      address it lands on, in both the clean and already-carrying cases. The
      attested-signal caveat the contract asks for reached `threat-model.md`
      with it, and `administration.md` and `testing-strategy.md` caught up with
      what had shipped.

- [x] **P13 — Event and analytics schema.** Normalized dimensions and schema
      versioning on the append-first ledger. Scope, boundaries and exit evidence
      are in
      [platform-p13-event-analytics-schema.md](platform-p13-event-analytics-schema.md).
      Two decisions were settled before any code and both held: **`org_id` is a
      frozen fact** rather than a join to current campaign metadata, so moving a
      campaign no longer moves its history; and **`request`/`fill`/`no_fill` are
      per-placement per-day counters, not ledger rows**, because they are per
      opportunity rather than per fill.

      The phase also replaced a measured defect. `Decision_Metrics` kept one
      serialized option and read-modify-wrote all of it on the public fill path
      — 228 KB at a thousand placements, growing linearly, pruned by nothing,
      and losing concurrent increments as it went. Counters now live in
      `aggr_decision_rollups` where the unique key makes an increment atomic,
      retention can prune a day, and the reconciler can rebuild one.

      **Three defects surfaced while building it, none by a failing test**: the
      batch decision path recorded nothing at all, house fills were credited to
      whichever advertiser owned the matching assignment, and conversion-only
      rollup rows were unattributed and invisible to every org report.

      Two exit criteria were **narrowed at closeout rather than ticked** — a
      publisher *seeing* why a slot is empty is P14's, and the staged-backfill
      machinery was reversed for one idempotent `UPDATE` because a backfill
      cannot recover history and emptying the projection would have reset every
      live delivery cap. Runtime behaviour on an unknown future version is
      explicitly out of scope, owned by whichever phase first bumps one.

- [x] **P14 — Reporting.** Advertiser and publisher views from rollups rather
      than raw events, date ranges with comparison, CSV export, and the
      architecture for scheduled email. **Closed out 2026-09-02** —
      [platform-p14-reporting.md](platform-p14-reporting.md).

      Three decisions were settled before any code. **The reporting day is UTC
      and every surface says so**, because neither projection has an hour
      dimension and re-bucketing into a site timezone is a storage decision a
      reporting phase should not smuggle in. **Comparison is the immediately
      preceding window of equal length**, so a percentage cannot turn out to be
      arithmetic about the calendar. **Freshness is part of the report** —
      reconciled, provisional or partial, derived from
      `aggr_rollups_reconciled_through`, which exists today and no surface
      reads.

      It inherited one defect and two invisible features.
      `Rollup_Repository::totals_for_org()` — the first tile on the advertiser
      dashboard — had no date predicate at all, so it aggregated every row the
      organization had ever produced on every page load: 12,775 rows examined
      at one year of history against a 30-day read's 1,500, and flat only for
      the ranged one. **Fixed**: org-scoped reads moved to
      `Rollup_Report_Repository` and take a `Domain\Report_Period`, a value
      object that cannot exist unbounded, and the tiles now print the window
      and its timezone rather than silently changing meaning.

      **P12's conversions are now visible.** They had been written, reconciled
      and retained since P12 while appearing in no tile, table, CSV column or
      REST field — a feature that ran and could not be seen. They now surface on
      the dashboard, the campaign list, campaign detail, `GET /campaigns` and the
      export, with `NULL` rendered as "Not measured" rather than zero, because a
      campaign that ran before conversion tracking did not convert nobody.

      **Tiles now compare against the window before them**, with a rate's
      change in percentage points and a count's in percent — 1.0% to 1.5% CTR
      is half a point and a 50% rise, and only one of those is what a reader
      takes "up 50%" to mean. An unmeasured or empty previous window draws no
      comparison at all rather than a `-100%` invented out of a feature's
      release date.

      **P13's counters finally have a reader.** Advertising → Reports is
      capability-gated on a new `aggr_view_reports` — new rather than borrowed,
      because P21's analyst will need exactly one thing to scope — and answers
      the question P13 stored the data for: how often a slot was asked for, how
      often it filled, and when it did not, why, with every reason rendered as
      a sentence rather than a code. A request that is neither a fill nor a
      reason is shown rather than absorbed, because that invariant belongs to
      the engine and not to the table.

      **The dashboard window is the reader's to choose**: two date inputs and
      three presets, with the tiles, chart, caption and export all resolving
      one request so the page cannot disagree with itself. A range that cannot
      be used is refused and said to be, never clamped. The export follows the
      window and truncates to what it can assemble in memory — a different cap
      from what a read may examine, and the button names the number it will
      actually produce.

      **The publisher export ships in long format** — a row per day and
      outcome, so the column set cannot change the first time a new reason
      occurs — carrying the sentence for a reader and the code beside it.
      **The scheduled-delivery seam is proven rather than built**: both exports
      produce their bytes through a pure `document()` over rows, asserted with
      no signed-in user, so whichever phase wants a mailed report inherits a
      delivery problem and not a report-generation one. No email ships, for the
      reason `notifications.md` gives.

      **One criterion was narrowed at closeout.** Both CSVs state their
      timezone and neither states freshness: a prose row breaks every parser
      pointed at a data file, and the statement stays beside the download
      button instead. That is enough while a person downloads what they are
      looking at and stops being enough the moment a report is mailed to
      somebody who never saw the screen — which is the scheduling phase's to
      say, and is recorded rather than left to be discovered.

      **Five tests were written, watched to pass, and found worthless** — four
      by attacking them, the fifth by CI, which is the weakest of the ways — and
      one of them was covering a real defect: `aggr_view_reports` reached the
      reviewer role and the menu map but not `Capabilities::primitives()`, so
      an administrator could not have opened the screen while every
      reviewer-based test stayed green.

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
  event types that distinguish served from requested. **Both are now `[x]`**, so
  the dependency is satisfied rather than merely stated: P6 shipped its stages
  against `aggr_rollups`, and P13 froze the tenancy those counters are read
  through and proved the reads stay bounded as the table fills.
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
