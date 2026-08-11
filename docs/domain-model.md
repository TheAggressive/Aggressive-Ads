# Domain model

Five entities, all stored as private custom post types, all reached through repository classes. See [ADR-0002](adr/0002-private-cpts-behind-repositories.md) for why CPTs rather than custom tables.

```
Organization 1 ──── * Campaign 1 ──── * Creative
     │                   │                  │
     │                   │ * ──── * Placement
     │                   │              (also referenced by Package)
     └── * member users  └── 1 Package ─ * Placement
```

## Registration baseline

All five share these `register_post_type()` arguments:

```php
'public'              => false,
'publicly_queryable'  => false,
'exclude_from_search' => true,
'show_ui'             => false,   // staff use the constrained Phase 5 review screen
'show_in_menu'        => false,
'show_in_nav_menus'   => false,
'show_in_admin_bar'   => false,
'show_in_rest'        => false,   // portal REST is hand-rolled, not wp/v2
'has_archive'         => false,
'rewrite'             => false,
'query_var'           => false,
'hierarchical'        => false,
'delete_with_user'    => false,
'map_meta_cap'        => true,
'supports'            => array( 'title', 'author', 'revisions' ),
```

`show_in_rest => false` combined with `rewrite => false` and `query_var => false` is what makes these genuinely private, not merely hidden. No `wp/v2` route is generated, no permalink exists to guess, and no front-end `?post_type=` query can reach them. Hiding a CPT with `show_ui => false` alone leaves all three doors open.

`delete_with_user => false` because deleting a WordPress user must not cascade-delete campaigns. An advertiser's account going away does not erase the record of what they ran.

## Post types

| Slug | Length | `capability_type` | Notes |
|---|---|---|---|
| `laao_ads_org` | 12 | `['laao_ads_org','laao_ads_orgs']` | Registered first — every other entity resolves ownership through it |
| `laao_ads_placement` | 18 | `['laao_ads_placement','laao_ads_placements']` | Seeded on install; `supports` drops `author` |
| `laao_ads_package` | 16 | `['laao_ads_package','laao_ads_packages']` | `supports` drops `author` |
| `laao_ads_campaign` | 17 | `['laao_ads_campaign','laao_ads_campaigns']` | Carries the custom post statuses |
| `laao_ads_creative` | 17 | `['laao_ads_creative','laao_ads_creatives']` | Belongs to a campaign via meta, not `post_parent` |

**Every slug is ≤ 20 characters, and that is a hard constraint, not a style preference.** `wp_posts.post_type` is `varchar(20)`. A longer slug does not error — it truncates on write and then fails to match on read, producing posts that exist but cannot be queried. `tests/php/Unit/Core/PostTypesTest.php` asserts the lengths so nobody discovers this the slow way.

Creative uses a meta reference rather than `post_parent` because `post_parent` carries WordPress semantics we do not want: hierarchical permalink construction, `wp_delete_post()` cascade behaviour, and admin list-table nesting. A plain `_laao_ads_campaign_id` says exactly what it means and nothing more.

## Meta keys

All meta is `_laao_ads_`-prefixed and leading-underscore. The underscore is load-bearing: it marks the key protected, which hides it from the custom-fields UI and excludes it from `WP_REST_Post_Meta_Fields`. Every key is declared with `register_post_meta()` carrying an explicit `type`, `single`, `sanitize_callback`, and an `auth_callback` that returns `false` by default — meta is written by repositories, never by a generic API.

### Campaign — `laao_ads_campaign`

| Key | Type | Notes |
|---|---|---|
| `_laao_ads_org_id` | int | Owning organization. **Never accepted from client input.** |
| `_laao_ads_start_ts` | int | UTC Unix seconds |
| `_laao_ads_end_ts` | int | UTC Unix seconds; `0` means open-ended → becomes `ADSANITY_EOL` at publish |
| `_laao_ads_package_id` | int | |
| `_laao_ads_placement_id` | int | **Repeated** (`single => false`) |
| `_laao_ads_budget_cents` | int | Integer cents; never a float |
| `_laao_ads_currency` | string | ISO 4217 |
| `_laao_ads_submitted_at` | int | |
| `_laao_ads_reviewed_by` | int | `0` = unclaimed |
| `_laao_ads_reviewed_at` | int | |
| `_laao_ads_review_notes` | string | **Advertiser-visible.** Required non-empty on reject / changes-requested |
| `_laao_ads_internal_notes` | string | Staff-only; never leaves the admin |
| `_laao_ads_notification_receipt` | string, repeated | Internal notification type/revision/recipient receipt for idempotent delivery |
| `_laao_ads_advertiser_notes` | string | |
| `_laao_ads_revision` | int | Increments on each resubmission |
| `_laao_ads_adsanity_ad_id` | int | **Repeated** — one per provider object. A failed configuration may leave a checkpointed draft for idempotent retry. |
| `_laao_ads_autosave_rev` | int | Optimistic-concurrency token for wizard autosave |
| `_laao_ads_wizard_step` | string | Resume point; advancing to `review` requires complete creative coverage and a submission-grade date window. Review itself is read-only; submit is a state transition, not another persisted edit step. |

### Creative — `laao_ads_creative`

| Key | Type | Notes |
|---|---|---|
| `_laao_ads_campaign_id` | int | |
| `_laao_ads_org_id` | int | Denormalized from the campaign — see invariants |
| `_laao_ads_placement_id` | int | |
| `_laao_ads_size` | string | Must be a key of AdSanity's current size map, ASCII `x` form |
| `_laao_ads_kind` | enum | `image` \| `code` \| `text` \| `html5`. **Advertisers may only set `image`** |
| `_laao_ads_private_path` | string | Relative to the private root. Never absolute, never a URL |
| `_laao_ads_private_token` | string | 32 hex chars |
| `_laao_ads_mime` | string | Server-detected, never the browser's claim |
| `_laao_ads_width` / `_height` | int | From `getimagesize()`, not from the client |
| `_laao_ads_filesize` | int | Bytes |
| `_laao_ads_sha256` | string | 64 hex chars — proves at approval that this is the file that was reviewed |
| `_laao_ads_click_url` | string | `http`/`https` only |
| `_laao_ads_target_blank` | int | `0` \| `1` |
| `_laao_ads_alt_text` | string | Becomes `_wp_attachment_image_alt` on promotion |
| `_laao_ads_attachment_id` | int | `0` until approval |
| `_laao_ads_adsanity_ad_id` | int | `0` until provider-object creation; checkpointed while the new object is still a draft, before configuration and activation |
| `_laao_ads_review_state` | enum | `pending` \| `approved` \| `rejected` |

### Organization — `laao_ads_org`

`_laao_ads_owner_user_id` int · `_laao_ads_member_user_id` int **repeated** · `_laao_ads_billing_email` string · `_laao_ads_contact_phone` string · `_laao_ads_website_url` string · `_laao_ads_org_state` enum `active|suspended`

### Placement — `laao_ads_placement`

`_laao_ads_size` string · `_laao_ads_adgroup_term_id` int · `_laao_ads_position_label` string · `_laao_ads_max_concurrent` int · `_laao_ads_is_active` int `0|1` · `_laao_ads_sort_order` int

### Package — `laao_ads_package`

`_laao_ads_placement_id` int **repeated** · `_laao_ads_duration_days` int · `_laao_ads_price_cents` int · `_laao_ads_currency` string · `_laao_ads_is_active` int `0|1`

## Repeated meta, not serialized arrays

`_laao_ads_placement_id` and `_laao_ads_member_user_id` are stored as multiple rows with `single => false`, never as one serialized array.

A serialized array is opaque to `meta_query`. "Which campaigns use placement 62?" and "which organizations is user 41 a member of?" are both day-one queries — the second runs inside `map_meta_cap` on every capability check. With repeated rows those are indexed lookups. With a serialized array they are a full scan plus `LIKE '%i:62;%'`, which is also wrong, because it matches `162` and `620`.

## Invariants

These hold at all times and are asserted in the repositories, not merely assumed:

1. **A Creative's `_laao_ads_org_id` equals its Campaign's `_laao_ads_org_id`.** Denormalizing the org onto the creative is what lets the file-stream endpoint authorize without loading the campaign — the hottest authorization path in the system. The denormalization is only safe because this invariant is enforced on write.
2. **A Campaign's placements are all `_laao_ads_is_active`.** Checked at submission and re-checked at approval, because a placement can be deactivated while a campaign sits in the queue.
3. **`org_id` is never read from client input.** It is derived server-side from the authenticated user on every request without exception. This one rule collapses most of the IDOR surface — see [threat-model.md](threat-model.md).
4. **A campaign's `post_status` is only ever written by `Campaign_State_Machine::apply()`.** See [campaign-workflow.md](campaign-workflow.md).
5. **Timestamps are UTC Unix integers everywhere.** No date strings, no site-local times, no `DateTime` in storage. Formatting happens at the display layer via `wp_date()`. See [ADR-0016](adr/0016-utc-unix-integer-times.md).
6. **Package selection creates a campaign snapshot.** The selected package must be active and completely configured, and every included placement must be active. Its package id, repeated placement ids, integer-cent price, and currency are copied onto the campaign in one editor operation. Later package edits never mutate an existing campaign implicitly.
7. **An editable campaign has at most one creative per selected placement.** Upload validates exact dimensions before creating the record. Removal deletes private bytes before the record, and neither operation is allowed after the campaign leaves an advertiser-editable state.

## Money

Integer cents plus an ISO 4217 code. Never a float, never a formatted string. Nothing in Phase 1 charges anyone, but the fields exist now because retrofitting a currency onto data already stored as `19.99` is materially worse than carrying two unused columns.
