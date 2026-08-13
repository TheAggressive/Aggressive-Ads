# ADR-0027 — Cached HTML is a reserved slot; counts happen after paint

**Status:** Accepted — 2026-08-12; amended 2026-08-12; amended 2026-08-13 by [0030](0030-reporting-from-native-rollups.md); amended 2026-08-13 by [0032](0032-equal-rotation-counts-follow-the-fill.md); amended 2026-08-13 by [0034](0034-site-scoped-tenancy.md)

## Context

Full-page cache and accurate ads cannot share AdSanity's model (query plus
count at HTML render). A cached page that inlines a creative freezes that
creative until the CDN expires. A cached page that increments a view
counter bills the wrong campaign, or the same campaign forever.

## Decision

**Cached HTML contains a reserved box and a placement slug.** It may include
a `noscript` house image. It never inlines a paid creative.

```
Cached page              Fill (short TTL)                 Count (never cached)
────────────             ────────────────                 ────────────────────
Reserved slot            GET aggr/v1/fill/{slot}          POST aggr/v1/i
+ noscript house         live eligible creative           HMAC token, single-use
                         object-cache, bust on            after paint
                         approve / pause / complete       click: 302 /ads/c/{token}
```

Fill is public, module-gated, and returns one creative or house. The object
cache key is `aggr_fill_{blog_id}_{placement_id}` and stores the **candidate set** of
servable live creatives, not a single winner ([ADR-0032](0032-equal-rotation-counts-follow-the-fill.md)).
`blog_id` is in the key because post ids restart on every site; see
[ADR-0034](0034-site-scoped-tenancy.md).
TTL comes from settings (default 30 seconds, bounds 5–300). **State-machine
transitions that touch a campaign delete that placement's fill key in the same
request**, via `aggr_campaign_transitioned`. Creative replacement does the
same. A paused campaign must not ride out a CDN TTL. Each request picks one
candidate with equal probability and mints a token bound to that campaign and
creative. The public JSON never includes the set.

Fill mints a signed, expiring token (HMAC with `wp_salt( 'aggr_fill' )`)
bound to the current `blog_id` as well as placement, campaign, and creative.
Impression and click consume it once: `aggr_events.token_hash` is unique,
so a replay is a database refusal. Reject expired, reused, empty, and
prefetch (`Sec-Purpose: prefetch`). Store `HMAC(IP + daily salt)`, never a
raw IP.

Click is a first-party hop (`/ads/c/{token}`), then the destination. The
destination URL works without JavaScript. No-JS impressions are **clicks
only** — do not invent SSR counts on cached HTML. Logged-in / uncached
pages may SSR fill for first paint; they still beacon, and SSR does not
write an impression.

Events are append-only in `aggr_events`. `aggr_rollups` holds
campaign/placement/day counters. Advertiser metric tiles appear only when
the reporting **and** native-delivery modules are on ([ADR-0030](0030-reporting-from-native-rollups.md)).

## Consequences

- A theme or CDN that caches the page still shows the right ad on the next
  fill, at the cost of one uncached GET per slot per TTL.
- Beacon failure under-counts. That is preferred to cached HTML over-counting.
- Unique `token_hash` is both the replay defence and the audit of "this
  view happened."

## Alternatives rejected

**Counting inside `render_callback`.** Invisible on cached HTML, and a
double-count on uncached HTML that also beacons.

**Putting the paid creative in the cached markup "for performance."** The
paused-campaign-still-showing bug, by design.

**Client-supplied impression counts.** The client is not a trusted counter.

## Amendment (2026-08-12)

The unique key is `(token_hash, event)`, not `token_hash` alone. One fill
mints one token used for both the beacon and the click hop; uniqueness per
event type still refuses a second impression or a second click. Schema v5
drops the v4 `token_hash` unique index because dbDelta will not.

The hop sets `Referrer-Policy: no-referrer` before the 302 so the destination
cannot replay `/ads/c/{token}` from the Referer. Beacon and hop re-check that
the named campaign is still live (or that house is still servable) before
counting. Public fill JSON omits internal post ids after minting.

## Amendment (2026-08-13)

The HMAC secret is network-wide and post ids restart on every site, so the
token payload and the fill cache key include `blog_id`. Parse rejects a
token bound to another site even when the HMAC matches. See
[ADR-0034](0034-site-scoped-tenancy.md).
