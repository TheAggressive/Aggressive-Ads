# Platform inventory and commerce group contract

This contract governs P15 through P20. Together they turn placements and
Campaign packages into forecastable inventory, richer creative operations, an
auditable commercial ledger and publisher workflows that can manage delivery
obligations. Each phase still requires a detailed definition from
[platform-phase-definition-template.md](platform-phase-definition-template.md)
before implementation begins.

Nothing in this document marks P15–P20 as started or complete.

## Group outcome

Publishers can describe what they sell, understand likely capacity, reserve it
without hiding uncertainty, manage creative variants safely, transact through a
real accounting domain, and operate renewals, underdelivery and make-goods with
an auditable separation between commercial obligation and delivery mechanics.

Delivery reads a local commercial-eligibility projection. It never waits for a
payment provider, forecasting job or publisher workflow during a fill.

## Entry and ordering

- P15 establishes inventory identities and policies consumed by forecasting and
  reservations.
- P16 requires P15 plus enough versioned P13 history to measure forecast error.
- P17 builds on the P2 asset/revision/assignment model and P14 reporting facts.
- P18 extends the P2 creative handler boundary; it must not weaken upload,
  sandbox or approval rules.
- P19 establishes accounts, orders, invoices, payments and the immutable ledger
  before P20 assigns commercial meaning to renewals and make-goods.
- P20 may coordinate Campaign and commercial workflows but cannot rewrite
  historical financial entries or delivery events.

## Shared commercial model

The group must distinguish:

- **Inventory definition** — placement/group, responsive size mapping,
  category, refresh and house policy.
- **Forecast** — a versioned estimate for a declared inventory slice and time
  range, including method, input window, confidence and later observed error.
- **Reservation** — a time-bounded claim against forecast capacity with status,
  owner, quantity and auditable override state.
- **Creative experience** — a controlled set of P2 assignments or revisions
  compared under a declared experiment or schedule.
- **Commercial account and order** — who is billed and what was purchased.
- **Invoice, payment, credit and refund** — provider-correlated business records
  whose state changes are explicit and idempotent.
- **Ledger entry** — immutable balanced commercial fact; corrections are new
  entries, never edits.
- **Delivery obligation** — the local state delivery may read to know whether a
  commercial hold or approved override permits service.
- **Publisher case** — assignment, review, renewal, underdelivery or make-good
  work with separate internal and advertiser-visible communication.

Each mutable fact has one authoritative writer. Cached totals, balances and
commercial eligibility are projections with a named rebuild path.

## Phase boundaries

### P15 — Inventory management

Owns placement groups, responsive multi-size mapping, categories, house and
refresh policies, and utilization presentation. It does not forecast demand or
reserve capacity.

### P16 — Forecasting and reservations

Owns conservative forecasts, reservation lifecycle, oversell warnings,
auditable overrides and recorded forecast error. Forecasts advise; they do not
silently block authorized staff or pretend uncertainty is exact capacity.

### P17 — Creative experience

Owns variants, experiments, schedules, device preview, approval/rejection
history and performance comparison on the P2 model. Experiment assignment and
analysis must not change immutable reviewed revisions.

### P18 — Rich creative types

Owns format handlers behind a stable interface: responsive images, sandboxed
HTML5, staff-only third-party tags and VAST-compatible video providers. Each
handler declares validation, review, rendering, CSP/sandbox, measurement and
fallback behavior.

### P19 — Billing domain

Owns accounts, orders, invoices, payments, credits, refunds, provider webhook
correlation and the immutable ledger. Stripe may be first, but hosted flows and
provider abstractions keep card data outside the plugin.

### P20 — Publisher workflow

Owns review assignment, work ownership, bulk actions, renewals, expiry,
underdelivery notices and make-goods. Internal notes and advertiser messages
remain separate in storage, authorization and notifications.

## Cross-phase invariants

- Placement identities used by history or reservations are retired rather than
  reused for a different inventory meaning.
- Responsive mappings are deterministic; the same request context cannot map to
  two conflicting billable inventory units.
- Forecasts are immutable snapshots. Reforecasting creates a new version and
  preserves observed error for the old one.
- Reservation quantity and status changes are concurrency-safe and audited.
- Oversell is visible. An authorized override names actor, reason, forecast
  version and expected impact.
- Experiments cannot include unapproved, incompatible or cross-tenant creative
  revisions.
- Rich creative input never executes on the WordPress server. Browser execution
  uses the narrowest sandbox and CSP compatible with the declared handler.
- Financial ledger entries are immutable, balanced, currency-aware and
  idempotent against provider event ids.
- Amounts use integer minor units with explicit currency and rounding rules.
- Delivery never calls a payment provider; it reads a local eligibility state
  whose override is capability-gated, expiring and audited.
- A refund, credit or make-good cannot erase the original invoice, payment,
  delivery or audit history.
- Staff notes never enter advertiser responses, email or exports unless an
  explicit workflow copies approved text into an advertiser message.

## Inventory and forecasting contract

P15 must define the inventory grain used consistently by P16, P19 and reports.
Refreshable inventory must distinguish page opportunity from refresh
opportunity, apply viewability and policy gates, and avoid forecasting infinite
supply from a timer.

P16 declares source history, exclusions, seasonality assumptions, minimum data,
confidence representation and conservative fallback for sparse inventory.
Forecast error is recorded when actuals mature. Reservation checks are atomic
for the chosen consistency model, and the tolerated race/oversell bound is
measured rather than assumed.

## Creative-format security contract

P17 and P18 reuse P2 tenant, revision, review and assignment invariants. Preview
is untrusted rendering and must use the same or stricter isolation as delivery.
HTML archives are validated against path traversal, decompression bombs,
executable server files, remote dependency policy and size/count limits.

Third-party tags are staff-only, visibly identified and disabled by default.
Video and external handlers time out and fall back without blocking the page.
No format may weaken private storage or accept the browser's MIME claim as
authority.

## Billing and provider contract

Provider checkout is hosted. The plugin stores provider customer, session,
invoice and transaction references but never card numbers, CVC or equivalent
authentication data. Webhooks are signed, replay-safe, order-independent where
the provider permits it and durably recorded before business projection.

Ledger posting and provider state reconciliation are separable. A provider
outage queues or delays commercial updates and surfaces health; it cannot make a
fill request call the provider. Manual reconciliation and override workflows
require dedicated capabilities and audit evidence.

Tax, legal invoicing, revenue recognition and supported currencies must be
explicitly scoped before P19 implementation. Features not provided by the
plugin must not be implied by labels in the UI.

## Failure, recovery and rollback

Forecast and report jobs are reproducible from durable inputs. Reservation,
financial and make-good transitions use atomic writes or tested compensation.
Webhook retries are idempotent. File/provider failures preserve the last known
safe state and create actionable diagnostics.

Rollback must preserve unknown newer rows. Schema or code rollback may disable
new workflows, but it may not delete ledger history, approved creative bytes,
reservations or messages merely because an older release cannot present them.

## Performance and operations contract

Inventory and reservation queries are bounded by placement/group and time
range. Forecasting and reconciliation are background work with checkpoints,
runtime limits and lag metrics. Billing webhooks return only after durable
acceptance, not after every downstream notification completes.

Operations can see forecast age/error, reservation pressure and overrides,
creative handler failures, provider/webhook health, unreconciled transactions,
ledger imbalance alarms, commercial-eligibility lag, workflow queues and failed
notifications. Secrets, payment payloads and private creative content are
redacted.

## Required group evidence

Across P15–P20, detailed phase tests must collectively prove:

- inventory identity, responsive mapping, category and refresh-policy rules;
- utilization math without unbounded event scans;
- sparse/history-rich forecasts, versioning, observed error and conservative
  fallback;
- reservation races, oversell warnings and audited overrides;
- experiment allocation integrity, schedules and statistically defensible
  comparison labels;
- creative handler validation, archive attacks, CSP/sandbox behavior and
  dependency failure fallback;
- immutable balanced ledger entries, correction entries and currency rounding;
- signed webhook replay, reordering, retry and reconciliation;
- hosted checkout without payment data entering storage or logs;
- local commercial eligibility and payment-provider outage during delivery;
- staff/adviser message separation and notification recipient privacy;
- tenant isolation across inventory, creatives, billing and workflows;
- large-fixture query, job-memory and latency budgets;
- multisite installation, isolation, deletion and opt-in uninstall; and
- the complete P0 baseline after every phase.

## Group exit criteria

The Inventory and Commerce group is complete only when P15–P20 are individually
`[x]` and:

1. Inventory has stable identities and policies suitable for forecasting,
   reservation, delivery and reporting.
2. Forecasts expose uncertainty and measured error; reservations and overrides
   are concurrency-safe and auditable.
3. Creative experiences and rich handlers preserve P2 review, security and
   immutable-revision guarantees.
4. The billing ledger is immutable, balanced, replay-safe and isolated from the
   delivery hot path.
5. Publisher workflows preserve commercial, delivery, audit and communication
   history.
6. Operations can reconcile every external or background process safely.
7. The authoritative quality baseline is green and documentation accurately
   describes the shipped commercial system.
