# ADR-0005 — Portal route via rewrite rule plus `template_include`

**Status:** Accepted — 2026-08-08

## Context

The portal needs a URL grammar, not a URL:

```
/advertiser/                    → dashboard
/advertiser/{route}/            → a top-level screen
/advertiser/{route}/{object}/   → a screen scoped to one object
```

The conventional WordPress answer is a Page containing a shortcode or block. That answer makes `/advertiser/campaigns/123/` either a query string or one Page per screen, and every one of those Pages is a row an editor can rename, trash, reorder, or paste a pattern into.

## Decision

Three rewrite rules registered `top`, backed by three query vars (`laao_ads_portal`, `laao_ads_route`, `laao_ads_object`), resolved by `template_include` to `templates/portal/base.php`.

Route and object are parsed into an immutable `Portal\Request` value object that validates the grammar — unknown routes, over-long segments, and anything containing a path separator resolve to 404 before a controller runs. `Request` has no WordPress dependency, so the grammar is unit-tested without a bootstrap.

The lifecycle:

```
parse_query       → if laao_ads_portal: is_home = false, is_404 = false, pre_handle_404 = true
template_redirect → auth_redirect() when logged out
                    403 template without laao_ads_access_portal
                    wp_robots noindex
template_include  → templates/portal/base.php
```

Forcing `is_404 = false` at `parse_query` is load-bearing: without it core resolves the request as a 404 before `template_include` runs, and the portal renders inside the theme's 404 template **with a 404 status code**. Search engines and uptime monitors both notice.

`auth_redirect()` rather than a hand-rolled login redirect, because it handles the `redirect_to` round trip, SSL, and the interim-login case, and we would get at least one of those wrong.

Flushing is version-gated: `Router::maybe_flush()` on `init` priority 99 compares the `laao_ads_rewrite_version` option against a class constant and calls `flush_rewrite_rules( false )` once when they differ. **Shipping a route change means bumping the constant** — that is the entire deployment procedure for routing.

## Consequences

- The portal exists the moment the plugin activates. No setup step, nothing to document, nothing an editor can trash.
- **There is no row in the Pages list**, so "where does `/advertiser/` come from?" is a harder support question. Mitigated by a Site Health check asserting the rules are present in `get_option( 'rewrite_rules' )` and a Tools screen showing the resolved URL with a manual re-flush.
- A deploy that changes routes without bumping the version leaves stale rules, and `/advertiser/` 404s in a way that looks like a broken deploy rather than a stale cache. Same two mitigations; recorded in [known-issues.md](../known-issues.md).
- `base.php` owns the whole document and calls neither `get_header()` nor `get_footer()` — those are the classic-theme mechanism, meaningless under a block theme and undesirable under a classic one. `wp_head()` and `wp_footer()` still run, because the admin bar, `wp_robots`, and every enqueue depend on them.
- The base segment is a setting, defaulting to `advertiser`, so a collision with an existing page slug is resolvable without a code change.

## Alternatives rejected

**A Page with a shortcode or block.** Every screen becomes editable content. The failure is not hypothetical: "the portal disappeared" because someone trashed a page is a category of incident this design removes entirely.

**`admin-post.php` or an admin screen for advertisers.** Requires giving advertisers wp-admin access, which contradicts the capability model in [ADR-0009](0009-org-scoped-map-meta-cap.md) and puts a WordPress admin in front of people who should never see one.

**A `do_parse_request` short-circuit.** Bypasses rewrite rules and the flush problem, at the cost of running before `WP_Query` exists — so the conditional tags, the admin bar, and the theme's own expectations are all subtly wrong, and every downstream plugin sees a request core never resolved.
