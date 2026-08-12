# Roadmap

This is the capability sequence, not a claim that implementation has landed in
strict phase order. The repository currently contains the foundation, domain,
private-upload, portal-read, staff-review, and core AdSanity publishing paths;
the status on each phase below names the remaining product work.

Nothing here is built merely because the architecture supports it.

## Phase 1 — Foundation *(complete)*

Bootstrap, autoloader, container, post types and statuses, installer, schema
upgrader, audit log, roles and org-scoped ownership, design tokens, portal route
shell, CI, reproducible packaging, a SHA-256-verifying GitHub plugin updater,
and tag-driven release automation with build provenance.

**Ends with:** a plugin that installs, upgrades, uninstalls, enforces its capability model, routes its portal under any theme, and packages reproducibly. No campaign can be created yet — deliberately. The security foundation lands before the UI does.

## Phase 2 — Domain layer *(in progress)*

The five repositories, domain value objects, the campaign validator, and `Campaign_State_Machine` with exhaustive transition coverage including every illegal edge. Placement and package resolution.

## Phase 3 — Creative upload *(complete)*

The REST upload route, private two-stage storage, MIME/dimension/integrity validation, the authenticated file-stream endpoint, rate limiting, and the security regression tests for every upload threat in [threat-model.md](threat-model.md).

## Phase 4 — Portal UI *(in progress; creation steps 1–6 complete)*

Dashboard, campaign list and detail, organization, account. The wizard: details → package → creative → destination and schedule → review → submit. The complete creation and submission flow now works without JavaScript, including draft creation, package snapshots, exact-size private creative upload, authenticated preview, removal, destination confirmation, submission-grade scheduling, actionable review, final confirmation, transition-time revalidation, audit, and reviewer notification. REST and forms converge on the same workflows. Atomic replacement for scheduled and live ads is also built: advertisers stage private revisions without interrupting delivery, staff review them in a dedicated queue, and approval reconciles the existing AdSanity object with exact read-back and rollback. Remaining here: drag/drop, Interactivity stores for wizard/upload/autosave/dialog, and full keyboard and screen-reader flow coverage.

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

## Phase 6 — AdSanity publisher *(complete)*

`Ad_Provider_Interface`, the AdSanity adapter, fail-closed `Placement_Mapping` resolver, `Creative_Promoter`, exact write/read-back verification, draft-first provider checkpoints, dual-record reconciliation, idempotent retry, and partial-failure recovery. The capability-gated Ad delivery screen maps every placement to an immutable ad-group term ID through placement-scoped, nonce-protected, verified and audited writes; missing providers, empty catalogues, unmapped placements and deleted groups all fail closed.

**The phase that removes the manual work**, and the reason the rest exists.

## Phase 7 — Lifecycle automation

`Campaign_Clock` and its hourly reconcile event are **built**: approved → scheduled → live → complete, driven by the guards rather than by the reconciler, with the sweep's source statuses derived from `Transition_Table::system_sources()`.

Pause, resume and cancel need no separate UI — the review screen derives its buttons from the transition table. Still outstanding in this phase: ending-soon notifications and the private-file retention purge.

## Phase 8 — Organizations and members *(in progress; secure access workflow complete)*

The organization screen shows name, active state, people with owner/member roles,
and campaign count. Initial creation is atomic during signup. Organization names
are uppercase with a unique private canonical identity; exact or unambiguous
misspellings create an owner-reviewed pending request rather than a duplicate
tenant or automatic attachment. Owner/staff email invitations are expiring,
email-bound and single-use, and the screen supports approve, deny and revoke.

Remaining here: organization rename with canonical-key collision handling,
removing existing members (including the last-owner rule), ownership transfer,
and staff suspension controls.

Self-service email change belongs here too. Core's flow mails a signed confirmation to the *new* address and completes on `profile.php`, which portal users cannot reach, so supporting it means owning the token: issue, expiry, single use and rate limiting. The account screen shows the address read-only until then.

## Phase 9 — Packages and pricing *(catalogue read/selection complete; management remains)*

The validated catalogue, wizard selection, price display, and campaign snapshot are implemented. Remaining: the staff package-management surface, renew, and duplicate. **Payment processing is deliberately out of scope** — the currency fields exist so that adding it later is not a migration, not because it is planned.

## Phase 10 — Reporting

Impressions and clicks surfaced from AdSanity's `_views-{ts}` / `_clicks-{ts}` counters, per-organization dashboards, and CSV export.

## Phase 11 — Hardening and launch

Full threat-model test coverage, authorization review, audit-table load testing at volume, Site Health checks, performance profiling, failure-state review, admin documentation, and the production rollout runbook.

## Architected for, not planned

The domain model accommodates these without redesign. None is scheduled.

Campaign analytics beyond Phase 10 · downloadable reports · an Advertising Manager role · online payments and invoices · campaign templates · sponsored content · richer creative formats · notification channels beyond email · CRM integration · multiple ad providers behind `Ad_Provider_Interface` · self-service exports.
