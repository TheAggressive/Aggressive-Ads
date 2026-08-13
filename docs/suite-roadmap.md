# Aggressive Ads — suite build outline

Working identity: **Aggressive Ads** / `aggr_`. Tagline: **Live means live.**
See [ADR-0022](adr/0022-aggressive-ads-identity.md).

This is the sequence for turning the LAAO advertiser portal into a white-label
advertising management suite that can **serve** ads, not only review them.
Phases 1–8 of [roadmap.md](roadmap.md) already shipped the campaign domain.
This file is what to build **next**, in order. Do not start a later phase
because it looks more fun.

Nothing here is built merely because this document exists.

---

## How the product looks

**One wp-admin product**, not three top-level megaphones.

```
{Product name}                 ← Settings → Brand (default UI: “Advertising”)
  Review                       aggr_review_campaigns
  Organizations                aggr_manage_orgs
  Inventory                    aggr_manage_placements
  Packages                     aggr_manage_packages
  Settings                     aggr_manage_settings
```

Reviewers never see Settings. Inventory never implies Review. Caps stay
distinct; only the **shell** unifies. Parent slug `aggr` stays stable.

**Settings (control plane)**

| Panel | Behaviour |
|---|---|
| **Modules** | Kill-switches: Billing UI, Public signup, Reporting. Native delivery is always on and is not a checkbox ([ADR-0033](adr/0033-native-delivery-is-not-a-staff-module.md)). Off = routes/menus/fields **absent**, not `display:none`. |
| **Brand** | Product name, logo, tagline, accent/surface tokens. Writes `--aggr-*` on `.aggr-portal` / staff admin. Save **rejects** WCAG AA failures. |
| **Delivery** | Native fill. Fill cache TTL. House-ad policy. |
| **Tracking** | Beacon-only impressions, click hop, retention. Never trust a client-supplied count. |

Billing **off** until payments exist: campaign snapshots may still store
currency; the UI must not pretend checkout exists.

**White-label** is ADR-0017 owned by the plugin: token *values*, logo, and
display name. Theme CSS may still override. Settings should win on
`.aggr-portal` so a tenant is not fighting the host theme. Prefixes never
change per tenant. Advertiser-facing default is “Advertising”, not
“Aggressive Ads”.

**Front of site:** editors place a **slot**, never a campaign.

- Block `aggr/placement` (header / break / sidebar / footer template parts)
- PHP `aggr_placement( 'header-728x90' )`
- Shortcode → the same renderer

Cached HTML is a **reserved box** + placement id. It does not freeze a
creative into the page cache.

---

## Cache vs the right ad

Full-page cache and accurate ads cannot share AdSanity’s model (query + count
on HTML render).

```
Cached page              Fill (short TTL)                 Count (never cached)
────────────             ────────────────                 ────────────────────
Reserved slot            GET aggr/v1/fill/{slot}          POST impression token
+ noscript house ad      live eligible set, pick one      HMAC, single-use,
                         object-cache of candidates       after paint
                         bust on approve/pause/complete   click: 302 /ads/c/{token}
```

- State-machine transitions **delete** that placement’s fill key in the same
  request. A paused campaign must not ride out a CDN TTL.
- No-JS: `noscript` house (or SSR fill on uncached pages). No-JS impressions
  are **clicks only** — do not invent SSR counts on cached HTML.
- Logged-in / uncached pages may SSR fill for first paint; still beacon so
  SSR and JS do not double-count.

---

## Hardened tracking

Own tables, not `_views-{day}` postmeta:

- `aggr_events` — append-only (type, ids, time, token hash)
- `aggr_rollups` — campaign/placement/day for dashboards

Rules:

- Server increments only. Fill mints a signed, single-use token bound to the
  campaign and creative that were actually chosen ([ADR-0032](adr/0032-equal-rotation-counts-follow-the-fill.md)).
- Reject expired, reused, prefetch (`Sec-Purpose`), obvious bots.
- Store `HMAC(IP + daily salt)`, not raw IP.
- Click is a first-party hop, then the destination. Destination works without JS.
- Advertiser metric tiles and a seven-day sparkline read **rollups only**, and
  only while Reporting is on ([ADR-0030](adr/0030-reporting-from-native-rollups.md),
  [ADR-0033](adr/0033-native-delivery-is-not-a-staff-module.md)).
  House (`campaign_id = 0`) never joins an org. Rotation does not move a view
  onto a different campaign after the fact.

---

## Build order

### Phase 0 — Identity *(shipped)*

Mechanical rename LAAO → Aggressive Ads: plugin header, namespace, autoloader,
text domain, CPT/status/meta/option/cap/cron/REST/CSS/store IDs, installer
migration that **rewrites existing rows**. The previous publish capability is
aliased to `aggr_publish` for one release.

Do not land Settings or the unified menu on `laao_*` and rename twice.

### Phase A — Portal UI close

Wizard / upload / autosave Interactivity stores on the existing no-JS forms.
Self-hosted Archivo (ADR-0018). Preview, remove-confirmation, and live-ad
preview dialogs on the shared overlay primitive. Keyboard + screen-reader
e2e covers skip link, step-heading focus, dialog trap/Escape/restore, and
axe on each wizard step plus open overlays.

### Phase B — Control plane

**Shipped.** Settings + modules + brand tokens + unified Advertising menu.
`aggr_manage_settings` gates the
Settings screen. Public signup off is a 404 unless the URL carries an
invitation token. Inventory is always registered.

ADRs in this phase: [0023](adr/0023-settings-and-module-flags.md),
[0024](adr/0024-white-label-tokens.md), [0025](adr/0025-unified-admin-menu.md).

### Phase C — Native delivery

**Shipped.** Live set is `aggr_live`, not an `ads` CPT. Cached HTML is a
reserved slot; fill and beacons are uncached. Native is the
`Ad_Provider_Interface` ([ADR-0031](adr/0031-native-is-the-only-publisher.md)).
Inventory creates placements (common sizes + custom WxH), house creative, and
the public slug.

ADRs in this phase: [0026](adr/0026-native-delivery.md),
[0027](adr/0027-cache-safe-fill.md).

### Phase D — Cutover (LAAO site)

**Plugin half shipped (ADR-0031).** Dual-write is gone. Template parts in the
LAAO theme still need to swap AdSanity group blocks for `aggr/placement`.
Until they do, public pages show empty reserved slots. That theme change is
outside this repository.

### Phase E — Suite depth

Staff package admin is **shipped** (Advertising → Packages). Equal rotation
among live campaigns on a slot is **shipped** ([ADR-0032](adr/0032-equal-rotation-counts-follow-the-fill.md)).
Fill dashboard and weighted rotation remain. Geo / frequency cap only when a
buyer needs them. Payments only behind the Billing module and a real PCI story.

---

## ADRs this outline still owes

| # | Topic |
|---|---|
| [0021](adr/0021-agate-identity.md) | Superseded (Agate) |
| [0022](adr/0022-aggressive-ads-identity.md) | **Done.** Aggressive Ads / `aggr_` |
| [0023](adr/0023-settings-and-module-flags.md) | **Done.** One `aggr_settings` option; a disabled module is absent |
| [0024](adr/0024-white-label-tokens.md) | **Done.** Brand tokens, contrast-gated save |
| [0025](adr/0025-unified-admin-menu.md) | **Done.** One Advertising parent; submenus keep distinct caps |
| [0026](adr/0026-native-delivery.md) | **Done.** Live set is ours (adapter dual-write superseded by 0031) |
| [0028](adr/0028-staff-package-catalogue.md) | **Done.** Staff package catalogue; campaigns keep snapshots |
| [0029](adr/0029-campaign-copy-is-not-a-transition.md) | **Done.** Renew/duplicate copy into a new draft |
| [0030](adr/0030-reporting-from-native-rollups.md) | **Done.** Reporting from `aggr_rollups`; tiles need native delivery too |
| [0031](adr/0031-native-is-the-only-publisher.md) | **Done.** Native is the only publisher; Inventory is the catalogue |
| [0032](adr/0032-equal-rotation-counts-follow-the-fill.md) | **Done.** Equal rotation; counts follow the filled campaign |
| [0033](adr/0033-native-delivery-is-not-a-staff-module.md) | **Done.** Native delivery is not a staff module |
| [0034](adr/0034-site-scoped-tenancy.md) | **Done.** One site is one publisher tenant; fill tokens bind `blog_id` |

Write each ADR in the same change that starts that phase.

---

## Modern WordPress (use / do not use)

**Use:** private CPTs + repositories, custom event table, Script Modules,
Interactivity, block `viewScriptModule`, object cache with transition bust,
REST with real permission callbacks, settings behind a repository.

**Do not use:** a React wp-admin SPA, `wp/v2` for campaigns, counting inside
cached HTML, a second CSS theme for white-label, a tenant-configurable
post-type prefix.

---

## Out of scope until named

Header bidding / GAM, CRM, multi-provider auctions, online checkout, video /
HTML5 tags, a second dialog stack. The domain can take them; this outline
does not schedule them.
