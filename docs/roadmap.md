# Roadmap

Phase 0 (documentation, ADRs) and Phase 1 (foundation) are the current work. Everything below is intent, not commitment — later phases will be planned properly when the phases before them have shipped and invalidated some of these assumptions.

Nothing here is built merely because the architecture supports it.

## Phase 1 — Foundation *(current)*

Bootstrap, autoloader, container, post types and statuses, installer, upgrader, audit log, roles and org-scoped ownership, asset pipeline, design tokens, portal route shell, CI, i18n, packaging, semantic-release.

**Ends with:** a plugin that installs, upgrades, uninstalls, enforces its capability model, routes its portal under any theme, and packages reproducibly. No campaign can be created yet — deliberately. The security foundation lands before the UI does.

## Phase 2 — Domain layer

The five repositories, domain value objects, the campaign validator, and `Campaign_State_Machine` with exhaustive transition coverage including every illegal edge. Placement and package resolution.

## Phase 3 — Creative upload

The REST upload route, private two-stage storage, MIME/dimension/integrity validation, the authenticated file-stream endpoint, rate limiting, and the security regression tests for every upload threat in [threat-model.md](threat-model.md).

## Phase 4 — Portal UI

Dashboard, campaign list and detail, organization, account. The wizard: details → package → creative → destination and schedule → review → submit. Interactivity stores for wizard, upload, autosave, and dialog. Autosave and resumable drafts. Full keyboard and screen-reader coverage.

## Phase 5 — Staff review and notifications

`show_ui` flipped on for the staff post types. Review queue, campaign review screen with all creative previews in one place, internal notes, the rejection workflow with required advertiser-facing feedback, approval controls, and the audit timeline.

Notification infrastructure lands here rather than earlier because it depends on the queue existing: the `Notification_Service`, capability-based recipient resolution (every user holding `laao_ads_review_campaigns`, resolved dynamically — never a maintained list), the submission and resubmission emails, duplicate-submission suppression, and failure handling that never reverses a successful submission.

## Phase 6 — AdSanity publisher

`Ad_Provider_Interface` and the AdSanity adapter. `Placement_Mapping` with the fail-closed resolver and its admin UI. `Creative_Promoter`. Provider ID persistence, reconciliation, idempotent retry, and partial-failure recovery that reuses what already succeeded.

**The phase that removes the manual work**, and the reason the rest exists.

## Phase 7 — Lifecycle automation

`Campaign_Clock`, the hourly reconcile event, scheduled → live → complete, expiry and ending-soon notifications, pause and resume, and the private-file retention purge.

## Phase 8 — Organizations and members

Organization CRUD for advertisers, multiple users per organization, invitations, member management, and suspension. The data model already supports this; the UI does not exist yet.

## Phase 9 — Packages and pricing

Package catalogue, selection in the wizard, price display, renew and duplicate. **Payment processing is deliberately out of scope** — the currency fields exist so that adding it later is not a migration, not because it is planned.

## Phase 10 — Reporting

Impressions and clicks surfaced from AdSanity's `_views-{ts}` / `_clicks-{ts}` counters, per-organization dashboards, and CSV export.

## Phase 11 — Hardening and launch

Full threat-model test coverage, authorization review, audit-table load testing at volume, Site Health checks, performance profiling, failure-state review, admin documentation, and the production rollout runbook.

## Architected for, not planned

The domain model accommodates these without redesign. None is scheduled.

Campaign analytics beyond Phase 10 · downloadable reports · account invitations · an Advertising Manager role · online payments and invoices · campaign templates · sponsored content · richer creative formats · notification channels beyond email · CRM integration · multiple ad providers behind `Ad_Provider_Interface` · self-service exports.
