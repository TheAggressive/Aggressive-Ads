# ADR-0030 — Reporting reads native rollups, never AdSanity counters

**Status:** Accepted — 2026-08-13; amended 2026-08-13 by [0033](0033-native-delivery-is-not-a-staff-module.md)

Amends [0018](0018-portal-ui-from-the-design-with-three-deviations.md) decision 2
(metric tiles stay empty until there is data), [0023](0023-settings-and-module-flags.md)
(`reporting` kill-switch), and [0027](0027-cache-safe-fill.md) (rollups as a
future reporting source). Does **not** supersede 0018's rule against inventing
business figures, nor 0023's rule that a disabled module is absent.

## Context

Phase 10 of the original roadmap planned to scrape AdSanity's
`_views-{timestamp}` / `_clicks-{timestamp}` meta. Native delivery already
writes `aggr_rollups` from the beacon and click hop. Two sources on one
dashboard is how "we billed for a campaign nobody ever saw" comes back as a
UI defect: AdSanity is still serving on today's LAAO site while native fill
is off, so a tile that reads empty rollups looks like zero traffic.

ADR-0018 refused to ship those tiles without data. The reporting module flag
has existed since ADR-0023 so this phase would not invent a second switch.

## Decision

**`aggr_rollups` is the only reporting source.** Reads live in
`Rollup_Repository`. AdSanity view/click meta is not read anywhere, including
`inc/Integration/Adsanity/`. Dual-source dashboards are a later ADR, if ever.

**Tiles and the seven-day sparkline appear only when `reporting` is on.** Native
delivery is always recording ([ADR-0033](0033-native-delivery-is-not-a-staff-module.md)).
Reporting-off keeps campaign-by-state counts and omits impression, click, CTR,
and the sparkline entirely — not `display:none`, not a row of zeros. Spend stays
absent until billing has a source.

**Org totals are filtered in SQL**, joining campaign `_aggr_org_id`. Fetching
the page of campaigns and summing in PHP is how pagination silently under-counts.
House rows (`campaign_id = 0`) never join an organization and are never shown
to an advertiser.

Campaign list/detail and `GET /aggr/v1/campaigns` expose integer impressions
and clicks, plus a derived CTR (null when impressions are 0), for authorized
objects only, and only while Reporting is on. A seven-day org-scoped
impression series from the same table may appear beside the dashboard campaign
table. CSV export is not this slice.

## Consequences

- An operator who turns Reporting off sees no metric tiles. That is the
  intended fail-closed, not a missing feature. Native delivery is not a
  second switch.
- Beacon failure still under-counts (ADR-0027). The tiles tell the truth about
  what native delivery recorded, not what AdSanity served.
- Spend is still the design's fourth tile and still absent.

## Alternatives rejected

**Read AdSanity `_views-*` / `_clicks-*`.** It would populate tiles on the
current LAAO site, then lie after cutover, and it would put AdSanity meta
keys outside `inc/Integration/Adsanity/`.

**Show zeros whenever Reporting is on.** A dashboard of invented business
figures is worse than one showing fewer real ones (ADR-0018).

**Sum the current campaign page.** Pagination would make "impressions" mean
"impressions on this page of campaigns."
