# AdSanity integration

Everything here was verified by reading AdSanity's source, not inferred from its documentation or its UI. Line references are to the installed copy at `wp-content/plugins/adsanity`.

**Installed version: AdSanity 2.0.1**, the licensed/paid build — it ships `includes/licensing/` and `update-engine/EDD_SL_Plugin_Updater.php`, and probes EDD item IDs to resolve a Core tier. Add-ons present: `adsanity-custom-ad-sizes` 1.6, `adsanity-rotating-ad-widget` 1.6.5. Not present: geolocation, weighted ads.

## What we depend on

These facts are load-bearing. If any changes, the publisher breaks, and the contract test in `tests/php/Contract/AdsanityContractTest.php` is what tells us.

### The ad post type

CPT `ads`, registered in `custom-post-types/class-adsanity-ads-cpt.php:180`. `public => true`, `show_in_rest => true`, `supports => ['title','thumbnail']`, `capability_type => 'post'`, `exclude_from_search => true`, `has_archive => false`.

Note `supports` has no `editor` and no `custom-fields`. An ad is a title, a featured image, and meta.

### The group taxonomy

`ad-group`, hierarchical, registered at `class-adsanity-ads-cpt.php:225` on the plugin's own `ads_init` action. This is the taxonomy that determines where an ad renders.

### The creative is the featured image

`theme-templates/ad.php:70-146` selects a render path in strict priority order:

```
featured image (_thumbnail_id)  →  _code  →  _text  →  ad_src
```

Only one applies. Setting both a featured image and `_code` renders the image and silently ignores the code. We publish image ads, so we set `_thumbnail_id` and none of the others.

### Meta keys

| Key | Meaning | Type as stored |
|---|---|---|
| `_url` | Destination / click-through URL | string |
| `_target` | Open in new window | bool |
| `_size` | Ad size, e.g. `"300x250"` | **plain string** |
| `_notes` | Internal notes | string |
| `_start_date` | Campaign start | **Unix timestamp, int** |
| `_end_date` | Campaign end | **Unix timestamp, int** |
| `_code` | External ad HTML | raw |
| `_text`, `_text_bg`, `_text_border`, `_text_border_width`, `_text_vertical` | Text-ad fields | mixed |
| `ad_src` | HTML5 iframe source (note: **no leading underscore**) | string |

Dates are timestamps, not `Y-m-d` strings — converted with `strtotime()` at save (`class-adsanity-ads-cpt.php:1716-1726`) and compared everywhere with `'type' => 'numeric'`.

### `ADSANITY_EOL`

`adsanity.php:56` defines it as the **string** `'2082672000'` (31 Dec 2035). It is the sentinel for "no end date": the admin list renders "Forever" only on an exact match, and the JS writes it rather than leaving `_end_date` empty.

We cast to int on write. Every consumer compares numerically, so the string form works, but storing an int keeps our own `meta_query` and comparisons honest.

### There is no cron. Scheduling is computed at read time.

This is the single most important fact about integrating with AdSanity, and it is not obvious.

Nothing expires an ad. There is no scheduled task, and `post_status` stays a normal `publish`. Instead, every query that displays ads injects a numeric `meta_query` requiring `_start_date <= now` and `_end_date >= now`:

- `adsanity_show_ad()` — `lib/template-tags.php:255-277`
- `adsanity_show_ad_group()` — `lib/template-tags.php:78-104`
- the admin "Active" group column — `class-adsanity-ads-cpt.php:319-333`
- REST — `lib/rest-api.php:36-64`

Portal schedules cover whole days in the WordPress site timezone. A selected
start date is stored as local `00:00:00`; a selected end date is stored as local
`23:59:59`. The latter is the final representable second before the following
midnight and is required because AdSanity treats `_end_date` as inclusive. Thus
an ad is eligible from the first midnight and ceases to be eligible at the next
midnight after its selected final day. These are calendar conversions, not
86,400-second additions, so daylight-saving changes remain correct.

**The consequence: an ad missing either date meta key is invisible everywhere.** Not "shows as expired" — absent. No shortcode, no widget, no block, no REST response will include it, and the post itself looks perfectly healthy in the database. An ad published without dates is the failure mode that produces "we billed for a campaign nobody ever saw."

So the publisher always writes both keys as ints and re-reads their exact values before declaring success. A merely present but incorrect timestamp is still a failed publication.

*(One asymmetry worth knowing: `lib/rest-api.php:36-64` filters only on `_end_date`, not `_start_date`, so a future-dated ad can appear via REST while correctly hidden on the front end. We do not rely on AdSanity's REST output, so this does not affect us — but do not use it as a source of truth for "is this campaign live".)*

### `save_post` is a no-op for programmatic writes

`AdSanity_Ads_CPT::save_post()` (`class-adsanity-ads-cpt.php:1624-1784`) begins by requiring `$_POST['ads_nonce']` and a `current_user_can( 'edit_post' )` check. A `wp_insert_post()` call from another plugin supplies neither, so **the handler returns immediately and does nothing**.

Two consequences, in opposite directions:

- Good: we do not need to satisfy or work around AdSanity's own sanitization.
- Bad: **there is no safety net whatsoever.** Every meta value's type, format, and presence is entirely our responsibility. AdSanity will happily store whatever we write and then fail to display it.

The publisher therefore re-reads every key it wrote and asserts the value back before reporting success. That read-back is not paranoia; it is the only validation in the pipeline.

### Reviewed replacement of a running ad

A creative update reuses the existing AdSanity post so its placement identity,
schedule, and accumulated reporting remain continuous. The proposed image
stays in private storage until staff approval. Approval promotes the checked
bytes, writes thumbnail, URL, target, size, dates, and group, then performs the
same exact read-back used at initial publication. If any value disagrees, the
publisher immediately rewrites and verifies the previous creative. The portal
does not mark the revision active or transfer the provider id until that public
write succeeds.

### Ad sizes

Not a taxonomy. A key→label map in the single option `adsanity-options`, sub-key `sizes`, e.g. `'728x90' => '728x90 - Leaderboard'`. Defaults at `adsanity.php:57-94` (32 entries), seeded on activation.

Load the saved option as the base map and then pass that map through the
`adsanity_ad_sizes` filter. Calling the filter with an empty array discards the
four configured base sizes; reading the option without applying the filter
misses changes made by `adsanity-custom-ad-sizes`. Both halves are required.

`_size` on the ad post is **not validated at save**. Any string is accepted and stored. An unrecognized value renders with an empty CSS class and shows "- invalid size -" in the admin list — cosmetic, not fatal, but it means AdSanity will not catch our typos either.

### Live ad groups on laartsonline.com

Read from the LAAO theme's saved block templates, which embed the group IDs and names from the last editor save.

| Term ID | Name | Size |
|---|---|---|
| **6** | `728x90 Header` | 728×90 |
| **48** | `720x300 Bottom` | 720×300 |
| **62** | `160x600` | 160×600 |
| **19** | `728×90 Break` | 728×90 |
| **34** | `300x250 Responsive` | 300×250 |

**Term 19's name contains U+00D7 MULTIPLICATION SIGN, not the letter `x`.** Every other term uses `x`. This is why placement mapping keys on term ID and never on term name: a name-matching implementation works for four of the five and fails on the fifth in a way that reads as a typo rather than a bug. See [ADR-0007](adr/0007-placement-mapping-is-explicit-data.md).

## Useful hooks

| Hook | Use |
|---|---|
| `ads_init` | Fires immediately after the `ads` CPT registers — the right place for a third party to attach to it |
| `adsanity_ad_sizes` | Filters the size map. **Read the size list through this, always.** |
| `adsanity_hide_ad` / `adsanity_hide_ad_group` | Suppress rendering without deleting data — the mechanism behind a future pause implementation |
| `adsanity_get_ads_args` / `adsanity_get_all_ads_args` | Modify `AdSanityQuery` queries |
| `adsanity_before_ad` / `adsanity_after_ad` | Wrap rendered ad markup |

## What we observed but do not depend on

Recorded so a future reader does not rediscover them and assume they matter.

- **`Adsanity\Meta_Data`** (`lib/class-meta-data.php`) wraps core meta functions and fires `adsanity_pre_update_meta`, `adsanity_after_update_meta_{$key}`, and friends. **Core `update_post_meta()` bypasses all of it.** We use core functions deliberately: the wrapper's hooks drive nothing we need, and depending on an undocumented internal wrapper to store data is worse than depending on WordPress. Recorded in [known-issues.md](known-issues.md) in case an add-on ever starts relying on those hooks.
- **`AdSanityQuery`** (`lib/query.php`) — thin `get_posts()` wrappers. We query through our own repositories.
- **`adsanity_view()` / `adsanity_click()`** (`lib/tracking.php`) — global functions that bump per-day counters stored as `_views-{timestamp}` / `_clicks-{timestamp}` meta, using raw `$wpdb` increments. Undocumented as an API. Phase 10 reporting will read these counters; nothing before then touches them.
- **REST**: AdSanity adds `rendered_ad`, `ad_type`, `ad_size` fields to core's `wp/v2/ads`, and `ad_ids` to `wp/v2/ad-group`. No bespoke namespace. We do not consume any of it.
- **admin-ajax**: one core endpoint, `wp_ajax_adsanity_get_ads_by_term`, logged-in only.
- **No registry table.** An ad is `wp_posts` + `wp_postmeta` + `wp_term_relationships` and nothing else. There is no bookkeeping we must also update.

## Interactions with the rest of this site

- The LAAO theme **dequeues** `adsanity-default-css` and `adsanity-cas` (`inc/Assets/class-styles.php:41-44`) and supplies its own ad styling keyed on `.ad-{size}` / `.adsanity-{size}` classes.
- The theme patches missing ad-image alt text at render time in `inc/Accessibility/class-ad-link-labels.php`, injecting `alt="Advertisement: {title}"` — written because three ads on the front page had no alt text. **We close that gap at the source**: `_laao_ads_alt_text` is generated from the validated destination host (or accepted from an API client) and written to `_wp_attachment_image_alt` when the creative is promoted, so the theme's shim has nothing to fix. See [accessibility.md](accessibility.md).
- `adsanity-custom-ad-sizes` is active, so the size map is not the stock 32 entries. Read it through the filter.

## Publishing an ad — the required sequence

```
1. resolve_all( placement_ids )        → term IDs, or abort before any write
2. promote creative to an attachment   → sha256 re-verified first
3. reconcile a dual-checkpointed existing ad, or wp_insert_post( post_type: 'ads', post_status: 'draft' )
4. persist the ad ID onto both the creative and campaign immediately
5. set_post_thumbnail( ad_id, attachment_id )
6. update_post_meta: _url, _target, _size, _start_date, _end_date   (ints for dates)
7. wp_set_object_terms( ad_id, term_id, 'ad-group' )
8. read back every required value and assert an exact match
9. change the verified draft to publish and confirm the status
10. audit
```

Steps 1, 4, and 8 are the ones that would be skipped by someone in a hurry. Step 1 fails closed before any write. Step 4 makes a post-creation failure retryable without creating a duplicate, while the draft status keeps that incomplete object out of rotation. Step 8 is the only validation AdSanity does not do for us. A saved provider ID is trusted for reconciliation only when both the creative and its campaign contain it; a stale one-sided pointer cannot authorize rewriting an unrelated ad.

## Managing placement mappings

Staff holding `laao_ads_manage_placements` use **wp-admin → Ad delivery**. The screen lists active and inactive placements, their declared sizes, and one of three mapping states: mapped, unmapped, or deleted group. Every option includes the provider term ID because the ID is the stored identity; the name remains editable display text.

Each placement saves through its own form, nonce, capability check, object-aware capability check, exact persistence read-back, and `placement.mapping_updated` audit event. An arbitrary or just-deleted term ID is rejected against a freshly loaded provider catalogue. Choosing “Not mapped — approval blocked” is an explicit supported operation.

If AdSanity is inactive, its taxonomy cannot be read, or it contains no groups, the screen becomes read-only and explains why. Existing stored mappings are not cleared. Approval independently re-resolves every selected placement, so a screen viewed before a provider change cannot weaken the fail-closed publication guard.
