# ADR-0022 — Product identity is Aggressive Ads; one `aggr_` prefix

**Status:** Superseded by [0035](0035-no-laao-compatibility-layer.md) — 2026-08-13

**Supersedes:** [0021](0021-agate-identity.md)

## Context

0021 chose Agate / `agate_` as a publisher-native, LAAO-free identity. In
practice Agate reads as a gemstone. Pica (the runner-up) was rejected as a
name. The company is **The Aggressive Network**; the sibling product is
Aggressive Apparel. The suite should sit in that family.

White-label still applies: a museum or university must not be forced to put
“Aggressive” on the advertiser login. Display name and logo are settings.
**Code identity is forever.**

## Decision

**Product name: Aggressive Ads.** Same pattern as Aggressive Apparel.

**One machine prefix: `aggr_`.** Post types, statuses, meta, options, caps,
cron, REST, CSS (`--aggr-*`). No second status prefix: `aggr_scheduled` = 14
characters (`varchar(20)`).

**Default tagline: “Live means live.”** Plugin header / wp.org short
description. It is a product claim (status is the state machine, fill is
busted on pause, dates are not a hope) and it is short enough to survive a
plugin card. White-label may replace it; the prefix does not change.

| Surface | Value |
|---|---|
| Plugin title | Aggressive Ads |
| Slug / text domain | `aggressive-ads` |
| PHP namespace | `Aggressive\Ads` |
| Constants | `AGGR_*` |
| Post types | `aggr_org`, `aggr_placement`, `aggr_package`, `aggr_campaign`, `aggr_creative` |
| Statuses | `aggr_draft`, `aggr_submitted`, … (same eleven slugs) |
| Caps | `aggr_access_portal`, `aggr_manage_settings`, … |
| REST | `aggr/v1` |
| Interactivity stores | `aggr/dialog`, … |
| Script modules | `@aggr/dialog`, … |
| Portal route | `/advertiser/` by default (English, not a brand) |
| Default UI product name | “Advertising” (not “Aggressive Ads”) unless Brand settings say otherwise |

The mechanical rename is Phase 0 in [suite-roadmap.md](../suite-roadmap.md): a
versioned upgrader, not a grep without migration. Alias
`aggr_publish` → `aggr_publish` for one release.

## Consequences

- New code uses `aggr_`. Do not add `laao_*` or `agate_*`.
- Namespace `Aggressive\Ads` maps to `inc/` the same way as today; the
  autoloader rule is recorded when Phase 0 lands.
- Existing installs must rewrite post types / statuses / meta / caps or
  campaigns vanish from queries.

## Alternatives rejected

**Agate / `agate_` (0021).** Reads as a gemstone. Print-unit joke does not
survive first contact.

**Pica.** Same typographic family; already disliked.

**Signet.** Signet Jewelers.

**`ads_`.** AdSanity’s CPT and everyone else’s tutorial.

**Aggro.** House slang; wrong default for a university publisher’s login.
White-label could still set it; it is not the vendor title.

**Putting “Aggressive Ads” on the portal by default.** The house brand is for
the plugin listing and wp-admin product chrome when they want it. Advertiser-
facing default stays generic.

**Make the code prefix a setting.** Schema is not white-label.
