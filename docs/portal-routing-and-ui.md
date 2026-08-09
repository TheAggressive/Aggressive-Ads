# Portal routing and UI

## URL grammar

Base segment comes from settings, default `advertiser`.

```
/advertiser/                        → dashboard
/advertiser/{route}/                → a top-level screen
/advertiser/{route}/{object}/       → a screen scoped to one object
```

Planned routes: `campaigns`, `organization`, `account`, `help`. So `/advertiser/campaigns/123/` is the campaign detail screen.

Three rewrite rules, registered `top`:

```php
'^advertiser/?$'                 → index.php?laao_ads_portal=1&laao_ads_route=dashboard
'^advertiser/([^/]+)/?$'         → index.php?laao_ads_portal=1&laao_ads_route=$matches[1]
'^advertiser/([^/]+)/([^/]+)/?$' → index.php?laao_ads_portal=1&laao_ads_route=$matches[1]&laao_ads_object=$matches[2]
```

`laao_ads_portal`, `laao_ads_route`, and `laao_ads_object` are registered via the `query_vars` filter. Route and object are parsed into an immutable `Portal\Request` value object which validates the grammar — unknown routes, over-long segments, and anything containing a path separator resolve to a 404 before the controller runs. `Request` has no WordPress dependency, so the grammar is unit-tested without a bootstrap.

## Request lifecycle

```
parse_query      → if laao_ads_portal: is_home = false, is_404 = false, pre_handle_404 = true
template_redirect → Gate: auth_redirect() if logged out
                          403 template if no laao_ads_access_portal
                          wp_robots noindex
template_include  → templates/portal/base.php
```

Forcing `is_404 = false` at `parse_query` matters: without it core resolves the request as a 404 before `template_include` ever runs, and the portal renders inside the theme's 404 template with a 404 status code. Search engines and uptime monitors both notice.

`auth_redirect()` rather than a hand-rolled login redirect, because it handles the `redirect_to` round trip, SSL, and the interim-login case correctly and we would get at least one of those wrong.

## Why a rewrite rule, not a page with a block

The honest trade-off, since this decision is load-bearing.

**What it costs.** There is no row in the Pages list, so "where does `/advertiser/` come from?" is a harder support question. A rewrite flush that never ran produces a 404 that looks exactly like a broken deploy. Both are mitigated: a Site Health check asserts the rules are present in `get_option( 'rewrite_rules' )`, and a Tools screen shows the resolved URL with a manual re-flush.

**What it buys.** The portal is a multi-screen area, not a page. A page-plus-block design expresses `/advertiser/campaigns/123/` as either a query string or one WordPress page per screen — and every one of those pages is something an editor can rename, trash, reorder, or paste a pattern into. A route the plugin owns cannot be edited into a broken state, and it removes the entire class of "the portal disappeared because someone trashed a page" incident. It also means the portal exists the moment the plugin activates, with no setup step and nothing to document.

See [ADR-0005](adr/0005-portal-route-via-rewrite-rule.md).

## The rewrite flush

Never on every request — `flush_rewrite_rules()` rewrites `.htaccess` and regenerates every rule in the site, and calling it per-request is a well-known way to make a site inexplicably slow.

`Router::maybe_flush()` runs on `init` priority 99, compares the `laao_ads_rewrite_version` option against a class constant, and calls `flush_rewrite_rules( false )` once if they differ. The soft form skips the `.htaccess` write, which we do not need. Rules are added earlier in `init`, so they are in `$wp_rewrite` when the flush happens.

**Shipping a route change means bumping the constant.** That is the whole deployment procedure for routing.

## Templates

```
templates/portal/
  base.php        the document — wp_head, wp_body_open, shell, wp_footer
  dashboard.php
  403.php
```

`base.php` renders the entire document itself: `<!doctype html>`, `<html <?php language_attributes(); ?>>`, `wp_head()`, `wp_body_open()`, the shell, `wp_footer()`, `</html>`.

**It never calls `get_header()` or `get_footer()`.** Those are the classic-theme mechanism. Under a block theme they are meaningless — Twenty Twenty-Five has no `header.php` — and under a classic theme they would pull in navigation and styling the portal does not want. Owning the document is what makes the portal render identically regardless of theme.

`wp_head()` and `wp_footer()` still run, because plugins and core legitimately need them, and because the admin bar, `wp_robots`, and script/style enqueues all depend on them. Under Twenty Twenty-Five that emits core's global-styles block and the admin bar. Both are harmless.

## Theme independence

The portal must look and behave the same under any theme. The mechanisms:

- The document is ours, so no theme markup wraps it.
- All layout comes from `src/styles/portal.css` inside `@layer laao-ads-*` cascade layers.
- Every design token carries a literal value. Nothing resolves through `--wp--preset--*`, because Twenty Twenty-Five and the LAAO theme expose *different* preset names — a token defined as `var(--wp--preset--color--primary)` renders correctly on one and transparent on the other. See [ADR-0017](adr/0017-self-contained-design-tokens.md).
- The reset is scoped to `.laao-ads-portal`, never global. A plugin that restyles `body` is a plugin that breaks the host site.

The LAAO theme may override `--laao-ads-*` tokens to make the portal feel native. That is the only supported coupling, it is one-directional, and the portal is fully functional without it.

**This is verified, not asserted.** `tests/e2e/portal-smoke.spec.ts` switches the active theme to Twenty Twenty-Five, logs in as an advertiser, loads the portal, and runs axe. An accidental theme dependency fails that test.

The one thing Twenty Twenty-Five will not provide is site header and navigation. That is deliberate — the portal is an application surface and a full-bleed shell is the right presentation. If a future phase wants theme chrome, `block_template_part( 'header' )` guarded by `wp_is_block_theme()` is the block-theme-safe call.
