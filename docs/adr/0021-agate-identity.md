# ADR-0021 — Product identity is Agate; one `agate_` prefix

**Status:** Superseded by [0022](0022-aggressive-ads-identity.md) — 2026-08-12

Agate read as a gemstone, not as the print unit. The house brand is The Aggressive Network (Aggressive Apparel). Identity moved to Aggressive Ads / `aggr_`.

## Context

The plugin was named and prefixed for one site: LAAO. That shows up in the
slug (`laao-advertiser-portal`), the PHP namespace (`LAAO_Advertiser_Portal`),
capabilities (`laao_ads_*`), post types (`laao_ads_*`), CSS tokens
(`--laao-ads-*`), and a second status prefix (`lap_`) that exists only because
`laao_ads_` plus a status slug would overflow `wp_posts.post_status`
`varchar(20)`.

The product is becoming a white-label advertising suite. A publisher who is
not LAAO should not ship `laao_` in their database, CSS, or admin. Display
name and logo are settings; **code identity is forever** — post types and meta
keys are not rebrandable after the first install.

## Decision

**Product name: Agate.**

An agate line is the traditional unit for priced advertising space. The word
is short, pronounceable, ownable, and not tied to one publication. The default
UI string can still read “Advertising”; Agate is the vendor name, not a
requirement on the login screen.

**One machine prefix: `agate_`.** Used for post types, statuses, meta,
options, capabilities, cron hooks, REST namespace, and CSS tokens
(`--agate-*`). The `lap_` / `laao_ads_` split goes away: `agate_` is four
characters shorter than `laao_ads_`, so every current status fits in 20
characters (`agate_scheduled` = 15, `agate_cancelled` = 15). Do not invent a
second prefix.

| Surface | Value |
|---|---|
| Plugin title | Agate |
| Slug / text domain | `agate` |
| PHP namespace | `Agate\` |
| Constants | `AGATE_*` |
| Post types | `agate_org`, `agate_placement`, `agate_package`, `agate_campaign`, `agate_creative` |
| Statuses | `agate_draft`, `agate_submitted`, … (same eleven slugs, new prefix) |
| Caps | `agate_access_portal`, `agate_manage_settings`, … |
| REST | `agate/v1` |
| Interactivity stores | `agate/dialog`, … |
| Script modules | `@agate/dialog`, … |
| Portal route | still `/advertiser/` by default (English, not a brand). Overridable later. |

White-label **does not** change this table. Logo, product name, and token
*values* are options. Prefixes are not.

The mechanical rename (files, installer migration of post types / meta /
options / caps / statuses) is a versioned upgrader step, not a search-replace
in git without a migration. See [suite-roadmap.md](../suite-roadmap.md) Phase 0.

## Consequences

- Subsequent ADRs and new code use `agate_`. Do not add new `laao_*` symbols.
- Existing installs must migrate; a prefix change without `dbDelta`/SQL
  rewrite leaves campaigns that cannot be queried (the same class of bug as
  truncating `post_status`).
- GitHub / Composer package names can follow in the same change or immediately
  after; they are not load-bearing at runtime.
- `laao_ads_publish_to_adsanity` becomes `agate_publish` with a one-release
  alias so existing reviewer roles keep working.

## Alternatives rejected

**Keep `laao_ads_` and only white-label the UI.** The database still says LAAO.
That is the thing a third-party publisher will grep on day one.

**`ads_` / `ad_`.** Collides with AdSanity’s `ads` CPT, with every tutorial
post type, and with too many plugins.

**Signet.** Strong metaphor (publisher’s mark) but Signet Jewelers is a public
company. Do not pick a fight with a trademark you do not need.

**Pica.** Also a publishing unit, even shorter. Rejected only because “pica”
is already a common code/font name; Agate is easier to own.

**Two prefixes again** (short statuses, long everything else). The `lap_`
split was a length workaround, not a brand. `agate_` does not need it.

**Make the code prefix a setting.** Post types cannot be tenant-configured.
White-label is display, not schema.
