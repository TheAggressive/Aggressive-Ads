# ADR-0034 — One WordPress site is one publisher tenant

**Status:** Accepted — 2026-08-13

Amends [0014](0014-version-driven-idempotent-installer.md): network activation
of a *new* site still cannot wait for the first public request. Amends
[0013](0013-phpunit-9-with-wp-test-suite.md): a third PHPUnit config boots
WordPress as multisite. Amends [0027](0027-cache-safe-fill.md): fill tokens and
the fill cache key bind `blog_id`, because post ids restart at 1 on every site
and `wp_salt( 'aggr_fill' )` is network-wide.

Does **not** introduce network-wide organizations, `switch_to_blog()` on the
fill/beacon/hop path, weighted rotation, or a Super Admin override of org
isolation.

## Context

Users are network-wide. Everything this plugin stores is not: custom post
types, `$wpdb->prefix` tables, `aggr_settings`, rewrite rules, cron, and
`wp_upload_dir()` private files. Membership is post meta on `aggr_org`, so it
is already site-scoped. That shape is a per-site tenant, accidentally.

The accident is unsafe. A fill token is HMAC over placement, campaign, and
creative ids. Those ids collide across blogs. The HMAC secret is the same on
every site in the network. A token minted on site A for `(placement 5,
campaign 10, creative 12)` verifies on site B and will increment whichever
rows happen to hold those ids — or 500 if the events table was never
installed because network activation never ran on that blog.

ADR-0014's `maybe_upgrade()` on `plugins_loaded` heals a site that receives a
request. A brand-new site's first request can be `GET /aggr/v1/fill/{slot}`.
That is too late to create `aggr_events`.

## Decision

**One WordPress site is one publisher tenant.** Organizations, campaigns,
placements, settings, rollups, private files, cron, and rewrite rules live
on that site's prefix. Users remain network-wide; membership stays org post
meta. There is no network org, no network settings document, and no Super
Admin shortcut around `map_meta_cap`.

**Fill tokens bind `blog_id`.** The payload is
`blog_id.placement_id.campaign_id.creative_id.exp.nonce.hmac`. Parse verifies
the HMAC, then rejects the token when `blog_id !== get_current_blog_id()`.
Six-part tokens from before this decision are rejected; their TTL is five
minutes, so there is no dual-read. `blog_id` is never taken from request
input.

**Fill cache keys include `blog_id`.** The object-cache group stays
`aggr_fill`. Do not add it to the global groups. The key must still contain
the blog id so a drop-in that prefixes incorrectly cannot mix candidate sets.

**Network-activate installs on `wp_initialize_site`.** When the plugin is
network-active, a new site gets schema, roles, and options before it can
serve. Per-site activation does not install onto other blogs; that site's
first request still self-heals through `maybe_upgrade()`. Fill, beacon, and
the click hop never call `switch_to_blog()`.

**Site deletion drops our tables.** `$wpdb->tables( 'blog' )` does not include
plugin tables. `wp_uninitialize_site` drops `{$prefix}aggr_*` while the
prefix still exists. Network uninstall walks sites; a per-site uninstall
touches only the current blog, so deleting the plugin on one tenant cannot
wipe another.

**Email-change user meta stays user-global.** `_aggr_email_change` is a
property of the WordPress user, not of a site. Completing a change on any
site the user can sign into is the accepted behaviour, recorded rather than
namespaced.

## Consequences

- A network of publishers can activate this plugin without sharing campaigns,
  fill, or rollups.
- An in-flight fill token from a deploy that predates this ADR dies at the
  next beacon or hop, at most five minutes after mint.
- Tests that mint and parse on one site keep working: `get_current_blog_id()`
  is `1` on single-site.
- Colliding-id and new-site install assertions live in a dedicated multisite
  PHPUnit config so they cannot `markTestSkipped()` on the single-site lane.

## Alternatives rejected

**Network-wide organizations.** Would fight table prefixes, `home_url()`
origin checks, per-site brand settings, and the org-isolation threat model.
A user who is a member on site A must not read site B by sharing a user id.

**Rely on the object-cache drop-in to prefix keys.** Some drop-ins treat
groups as global. A key that already contains `blog_id` stays correct.

**Per-site HMAC salts.** `wp_salt()` is in `wp-config.php`. Inventing a
per-blog secret is a second store that can desync; binding `blog_id` into
the existing payload is the smaller change.

**Install only on first request.** The first request can be public fill.
Missing `aggr_events` is a 500 on the page that is supposed to show an ad.

**`switch_to_blog()` during fill.** Cross-site queries in the hot path, and
in-memory repository caches keyed only by post id would leak.
