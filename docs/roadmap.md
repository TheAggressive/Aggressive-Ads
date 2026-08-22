# Roadmap

This is the capability sequence, not a claim that implementation has landed in
strict phase order. The repository currently contains the foundation, domain,
private-upload, portal, staff review, native publishing, and reporting tiles;
the status on each phase below names the remaining product work.

Nothing here is built merely because the architecture supports it.

**Suite direction** (white-label, unified admin, native ad serving, cache-safe
tracking) lives in [suite-roadmap.md](suite-roadmap.md).

**Platform direction** — line items, a decision engine, targeting, frequency,
pacing, viewability, conversions, billing, forecasting and the provider registry
— lives in
[platform-implementation-progress.md](platform-implementation-progress.md). That
file is the sequence beyond this one, and it carries the audit of what exists
today rather than repeating it here.

## Phase 1 — Foundation *(complete)*

Bootstrap, autoloader, container, post types and statuses, installer, schema
upgrader, audit log, roles and org-scoped ownership, design tokens, portal route
shell, CI, reproducible packaging, a SHA-256-verifying GitHub plugin updater,
and tag-driven release automation with build provenance.

**Ends with:** a plugin that installs, upgrades, uninstalls, enforces its capability model, routes its portal under any theme, and packages reproducibly. No campaign can be created yet — deliberately. The security foundation lands before the UI does.

## Phase 2 — Domain layer *(complete)*

The repositories, domain value objects, the campaign validator, and `Campaign_State_Machine` with exhaustive transition coverage including every illegal edge. Placement and package resolution.

## Phase 3 — Creative upload *(complete)*

The REST upload route, private two-stage storage, MIME/dimension/integrity validation, the authenticated file-stream endpoint, rate limiting, and the security regression tests for every upload threat in [threat-model.md](threat-model.md).

## Phase 4 — Portal UI *(complete)*

Dashboard, campaign list and detail, organization, account. The wizard: details → package → creative → destination and schedule → review → submit. The complete creation and submission flow now works without JavaScript, including draft creation, package snapshots, exact-size private creative upload, authenticated preview, removal, destination confirmation, submission-grade scheduling, actionable review, final confirmation, transition-time revalidation, audit, and reviewer notification. REST and forms converge on the same workflows. Atomic replacement for scheduled and live ads is also built: advertisers stage private revisions without interrupting delivery, staff review them in a dedicated queue, and approval reconciles the live publication with exact read-back and rollback. The shared dialog Interactivity store is shipped (creative replace, live-ad preview, draft preview and remove confirmation on campaign detail; imperative open/close — see [interactivity-stores.md](interactivity-stores.md)).

Public advertiser signup is also built. It is opt-in through WordPress's
"Anyone can register" policy (with a dedicated filter for managed identity
deployments), creates the user and organization as a compensating transaction,
and sends a core-backed one-time password setup link to a portal-owned password
screen. Password setup, recovery and subsequent sign-in remain inside the
advertiser portal while WordPress core still owns key validation, password
hashing, session invalidation and authentication. The public response never
reveals whether an email address already exists.

## Phase 5 — Staff review and notifications *(complete)*

A purpose-built, capability-gated admin surface keeps the private post types out
of WordPress's generic editor and forces every status write through the campaign
state machine. The review queue and campaign detail are built, including
authenticated creative previews, internal notes, required advertiser-facing
feedback, approval controls, and the object-and-organization-scoped audit
timeline.

Notification infrastructure lives here because it depends on the queue:
`Notification_Service`, dynamically resolved capability-based recipients,
individual submission and resubmission emails, per-recipient duplicate
suppression and bounded partial retry, and failure handling that never reverses
a successful submission. See [notifications.md](notifications.md).

## Phase 6 — Publisher *(complete; adapter superseded)*

`Ad_Provider_Interface` is implemented by `Integration\Native\Publisher`. Inventory is the placement catalogue (common IAB sizes plus custom WxH). There is no AdSanity adapter.

## Phase 7 — Lifecycle automation

`Campaign_Clock` and its hourly reconcile event are **built**: approved → scheduled → live → complete, driven by the guards rather than by the reconciler, with the sweep's source statuses derived from `Transition_Table::system_sources()`.

Pause, resume and cancel need no separate UI — the review screen derives its buttons from the transition table. Ending-soon notifications and the private-file retention purge are built: live and paused campaigns with a finite `end_ts` inside a seven-day window receive one receipt-backed reminder per end date; private creative bytes for terminal campaigns past ninety days are deleted while campaign records, checksums and Media Library attachments remain.

## Phase 8 — Organizations and members *(complete)*

The organization screen shows name, active state, people with owner/member roles,
and campaign count. Initial creation is atomic during signup. Organization names
are uppercase with a unique private canonical identity; exact or unambiguous
misspellings create an owner-reviewed pending request rather than a duplicate
tenant or automatic attachment. Owner/staff email invitations are expiring,
email-bound and single-use, and the screen supports approve, deny and revoke.

Remaining here: none for membership administration. Self-service email change is
built: the account screen issues a portal-owned confirmation token to the new
address, stores only a salted HMAC with expiry, rate-limits requests, and
completes on `/advertiser/confirm-email/` with a signed-in session. Staff
suspension controls remain on the Organizations admin screen.

Organization rename with canonical-key collision handling, member removal with
the last-owner rule, and ownership transfer remain available on the portal
organization screen for owners and staff.

## Phase 9 — Packages and pricing *(complete)*

The validated catalogue, wizard selection, price display, campaign snapshot,
staff package-management surface, and campaign copy (renew / duplicate) are
implemented.
**Payment processing is deliberately out of scope** — the currency fields exist so that adding it later is not a migration, not because it is planned.

## Phase 10 — Reporting *(complete)*

Org-scoped impression, click and CTR tiles from `aggr_rollups`, gated on the
Reporting module. Native delivery is always on.
Campaign list/detail and `GET /campaigns` expose the same integer counts for
authorized objects. Spend stays absent until billing has a source.

CSV export is built: `Portal\Report_Actions` streams a per-campaign, per-day
document for the caller's own organization, bounded to 31 days, behind the same
Reporting gate as every other surface. `Domain\Csv_Writer` neutralizes
spreadsheet formulas — see [threat-model.md](threat-model.md), which explains
why HTML escaping is not the relevant defence here.

## Phase 11 — Hardening and launch

The 1,000-ad delivery query-budget regression, delivery Site Health dependency
check, atomic persistent-cache rate limiting, exact rollup reconciliation, and
bounded event retention are built. The rewrite-staleness Site Health assertion
and its repair control are built (`Install\Rewrite_Health`). Administrator
documentation and the production rollout runbook are written —
[administration.md](administration.md) and [runbook.md](runbook.md).

Audit-table load testing at volume is **done**, and it found something. At a
million rows with fifty thousand on one campaign, `Audit_Repository::for_object()`
was resolved as an index-merge intersection of `object` and `org` followed by a
filesort: 9,645 rows examined and sorted to return fifty, about 27 ms. The
`object` index now carries `org_id`, which the query also filters on, and the
same read is a backward index scan that stops at fifty — about 0.7 ms, with no
optimizer hint, because the planner chooses it unaided. Replacing the index
rather than adding a sixth cost 13 MB per million rows. See db version 9.

What that testing also established is that nothing bounds the table at all;
[open-work.md](open-work.md) records it, because how long an audit log must be
kept is a compliance question rather than an engineering one.

Remaining: concurrent request/soak testing on production-equivalent
infrastructure, and a full authorization/failure-state review. Both need an
environment rather than a commit, which is why they are the last items and why
nothing here claims them.

## Architected for, not planned

The domain model accommodates these without redesign. None is scheduled *here* —
several are now sequenced in
[platform-implementation-progress.md](platform-implementation-progress.md)
instead, which is where they gained dependencies and migration notes:
downloadable reports (P14), online payments and invoices (P19), richer creative
formats (P17, P18), an organization-scoped role model beyond a single
Advertising Manager (P21), and multiple providers behind `Ad_Provider_Interface`
(P24).

Still architected for and still unscheduled anywhere: campaign templates ·
sponsored content · notification channels beyond email · CRM integration ·
self-service exports.
