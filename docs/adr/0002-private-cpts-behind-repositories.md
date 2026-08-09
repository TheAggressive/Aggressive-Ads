# ADR-0002 — Private CPTs behind repositories, not custom tables

**Status:** Accepted — 2026-08-08

## Context

Five entities need storage: Organization, Placement, Package, Campaign, Creative. They are business objects, not content — no advertiser ever wants a permalink for a campaign.

The instinct for "this is an application, not a blog" is custom tables. The counter-instinct is that custom tables in WordPress mean writing, by hand, what core already provides: querying, meta, revisions, authorship, capability mapping, caching, and the object-cache integration every hosting layer already understands.

## Decision

All five entities are **custom post types registered fully private**, and all access goes through repository classes in `inc/Repository/`.

Private means all of these together, not any one of them:

```php
'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true,
'show_in_rest' => false, 'has_archive' => false, 'rewrite' => false,
'query_var' => false, 'show_ui' => false, 'delete_with_user' => false,
```

`show_ui => false` alone hides a CPT from the admin and leaves the REST route, the permalink, and the `?post_type=` query all open. `show_in_rest => false` plus `rewrite => false` plus `query_var => false` is what makes them genuinely unreachable.

`inc/Repository/` is the **only** place `WP_Query`, `get_posts()`, `get_post_meta()`, `update_post_meta()`, or `$wpdb` appears anywhere in `inc/`. `bin/ci/check-repository-boundary.sh` fails the build on a violation.

The audit log is the deliberate exception and gets a real table — see [ADR-0003](0003-audit-log-in-custom-table.md).

## Consequences

- `map_meta_cap`, revisions, authorship, and the object cache all work without being reimplemented. The org-scoped ownership model in [ADR-0009](0009-org-scoped-map-meta-cap.md) is a filter on core's pipeline rather than a bespoke permission system.
- Post-type slugs are capped at 20 characters, because `wp_posts.post_type` is `varchar(20)`. A longer slug truncates on write and then fails to match on read — posts that exist and cannot be queried. `tests/php/Unit/Core/PostTypesTest.php` asserts the lengths.
- Meta queries are slower than indexed columns would be. Acceptable at this data volume; the repository boundary means a specific hot query can move to a table later without touching a caller.
- Multi-valued fields are stored as repeated meta rows (`single => false`), never serialized arrays, so `meta_query` can reach them. See [domain-model.md](../domain-model.md).
- Creative references its campaign through `_laao_ads_campaign_id` meta rather than `post_parent`, to avoid inheriting hierarchical permalink construction and `wp_delete_post()` cascade semantics we did not ask for.

## Alternatives rejected

**Custom tables for everything.** Reimplements capability mapping and caching, and forfeits `map_meta_cap` — which is precisely the mechanism that makes one ownership implementation serve every surface.

**Public CPTs with `show_ui => false`.** The failure mode is a `wp/v2/laao_ads_campaign` route serving another organization's budgets to anyone with a browser. Hidden is not private.

**Options or transients for the small entities.** Placements and packages look small enough until they need querying, sorting, and per-object capabilities — at which point they are post types with extra steps.
