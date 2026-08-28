# Aggressive Ads — suite build outline

Working identity: **Aggressive Ads** / `aggr_`. Tagline: **Live means live.**
This is the sequence for a white-label advertising management suite that
**serves** ads, not only reviews them. Phases 1–8 of [roadmap.md](roadmap.md)
already shipped the campaign domain. This file is what to build **next**, in
order. Do not start a later phase because it looks more fun.

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
| **Modules** | Kill-switches: Billing UI, Public signup, Reporting. Native delivery is always on and is not a checkbox. Off = routes/menus/fields **absent**, not `display:none`. |
| **Brand** | Product name, logo, tagline, accent/surface tokens. Writes `--aggr-*` on `.aggr-portal` / staff admin. Save **rejects** WCAG AA failures. |
| **Delivery** | Native fill. Fill cache TTL. House-ad policy. |
| **Tracking** | Beacon-only impressions, click hop, retention. Never trust a client-supplied count. |

Billing **off** until payments exist: campaign snapshots may still store
currency; the UI must not pretend checkout exists.

**White-label** is owned by the plugin: token *values*, logo, and
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
+ noscript house ad      compact eligible-id cache        HMAC, single-use,
                         per-creative payload, pick one   durable event ledger
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
  campaign and creative that were actually chosen.
- Reject expired, reused, prefetch (`Sec-Purpose`), obvious bots.
- Store `HMAC(IP + daily salt)`, not raw IP.
- Click is a first-party hop, then the destination. Destination works without JS.
- Advertiser metric tiles and a seven-day sparkline read **rollups only**, and
  only while Reporting is on.
  House (`campaign_id = 0`) never joins an org. Rotation does not move a view
  onto a different campaign after the fact.
- Closed UTC days are reconciled exactly from the event ledger before bounded
  retention may delete their raw rows.

---

## Build order

### Phase 0 — Identity *(shipped)*

Aggressive Ads / `aggr_`.
There is no LAAO rewrite or one-release alias. New installs write the current
names.

### Phase A — Portal UI close

Wizard / upload / autosave Interactivity stores on the existing no-JS forms.
Self-hosted Archivo. Preview, remove-confirmation, and live-ad
preview dialogs on the shared overlay primitive. Keyboard + screen-reader
e2e covers skip link, step-heading focus, dialog trap/Escape/restore, and
axe on each wizard step plus open overlays.

### Phase B — Control plane

**Shipped.** Settings + modules + brand tokens + unified Advertising menu.
`aggr_manage_settings` gates the
Settings screen. Public signup off is a 404 unless the URL carries an
invitation token. Inventory is always registered.

### Phase C — Native delivery

**Shipped.** Live set is `aggr_live`, not an `ads` CPT. Cached HTML is a
reserved slot; fill and beacons are uncached. Native is the
`Ad_Provider_Interface`.
Inventory creates placements (common sizes + custom WxH), house creative, and
the public slug.

### Phase D — Cutover (LAAO site)

**Plugin half shipped.** Dual-write is gone. Template parts in the
LAAO theme still need to swap AdSanity group blocks for `aggr/placement`.
Until they do, public pages show empty reserved slots. That theme change is
outside this repository.

### Phase E — Suite depth

Staff package admin is **shipped** (Advertising → Packages). Assignment-based
weighted fill is **shipped**. Fill dashboard and richer tiering remain. Geo /
frequency cap only when a buyer needs them. Payments only behind the Billing
module and a real PCI story.

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
