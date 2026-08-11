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

`wp_head()` and `wp_footer()` still run, because plugins and core legitimately
need them, and because `wp_robots` and script/style enqueues depend on them.
The front-end WordPress admin bar is suppressed only inside this owned
document: otherwise its unrelated wp-admin controls render before the portal's
skip link and become the first keyboard stops. Under Twenty Twenty-Five core's
global styles still emit, but the portal's scoped cascade remains authoritative.

## Campaign creation

Creation starts from a nonce-protected form on the dashboard or campaign list.
It creates an organization-scoped `lap_draft` and redirects to the ordinary
`/advertiser/campaigns/{id}/` detail URL, keeping the documented URL grammar
numeric rather than inventing a special `new` object segment.

The detail screen is also the resumable wizard surface. All six steps currently
ship. Details collects campaign name, optional placement interests, and notes
for reviewers. Package presents only active,
completely configured catalogue entries as native radio controls, with price,
duration, and included placement sizes. Creative presents one upload card per
package placement, including exact dimensions, a native file input, destination
URL, required alternative text, authenticated preview, and nonce-protected
removal. Destination and schedule confirms every per-creative destination and
description, then collects a required future local start date and optional
end date. Review presents the stored campaign, commercial package snapshot,
schedule, and authenticated creative previews. It aggregates every current
submission problem and links each one back to the exact editing step and field.
Submit explains the editing lock, withdrawal boundary, and changes-requested
path before presenting the final action. All six steps work without JavaScript.

Saving details persists `package` as the resume point. Saving a package copies
its current placement set, integer-cent price, and currency onto the campaign;
the catalogue remains mutable without retroactively changing the draft. Each
campaign form has its own campaign-bound nonce, and campaign-field writes carry
the optimistic revision token through `Campaign_Editor`. Creative writes have
campaign/placement- or creative-bound nonces and share `Creative_Manager` with
REST. Creative files remain private, previews use the authorized stream with a
short-lived REST nonce, and invalid dimensions report the uploaded and required
sizes. Later drag/drop, live progress, and atomic replace work enhances this
flow; it does not replace it.

Step 4 has its own campaign-bound nonce and optimistic revision. Completion is
not cosmetic: `Campaign_Editor` refuses to advance the resume point to `review`
unless every selected placement has exactly one creative and the date window
already satisfies submission-grade rules. Existing REST clients continue to
write Unix timestamps; the HTML form performs the timezone conversion.

Step 5 is intentionally read-only. `Review_Readiness` adapts the canonical
submission validator into advertiser-safe `code`, `message`, `step`, and
`target` values, discarding raw validation context that can contain URLs or
internal identifiers. The success state and issue summary are announced, edit
links use the normal resumable wizard URL plus an in-page target, and creative
destinations remain text instead of active external links. The final submit
step will re-run the validator because readiness can change while the review
screen is open.

Step 6 is a query-only confirmation screen, not another draft mutation. The
durable resume point remains `review`; REST autosave rejects `submit` as a
persisted wizard step. Its campaign-bound nonce authorizes only final
submission, and the form uses the same transition rate limit and
`Campaign_State_Machine` as REST. The machine reauthorizes ownership and
capabilities, re-runs the validator against current storage, stamps submission,
writes the audit event, and dispatches notifications only after status commits.
A replay reaches an illegal edge, is audited as denied, and cannot create a
second successful submission. Post/redirect/get then renders the locked
campaign detail with an announced success notice.

## Theme independence

The portal must look and behave the same under any theme. The mechanisms:

- The document is ours, so no theme markup wraps it.
- All layout comes from scoped `.laao-ads-*` rules in `assets/portal.css`. Only token defaults are cascade-layered; authored reset, layout, and component rules stay unlayered so generic host-theme element styles cannot outrank them.
- Every design token carries a literal value. Nothing resolves through `--wp--preset--*`, because Twenty Twenty-Five and the LAAO theme expose *different* preset names — a token defined as `var(--wp--preset--color--primary)` renders correctly on one and transparent on the other. See [ADR-0017](adr/0017-self-contained-design-tokens.md).
- The reset is scoped to `.laao-ads-portal`, never global. A plugin that restyles `body` is a plugin that breaks the host site.

The LAAO theme may override `--laao-ads-*` tokens to make the portal feel native. That is the only supported coupling, it is one-directional, and the portal is fully functional without it.

**This is verified, not asserted.** `tests/e2e/campaign-wizard.spec.ts` switches the active theme to Twenty Twenty-Five, logs in as an advertiser, loads the portal, and runs axe. An accidental theme dependency fails that test.

The one thing Twenty Twenty-Five will not provide is site header and navigation. That is deliberate — the portal is an application surface and a full-bleed shell is the right presentation. If a future phase wants theme chrome, `block_template_part( 'header' )` guarded by `wp_is_block_theme()` is the block-theme-safe call.
