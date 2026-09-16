# Portal routing and UI

## URL grammar

Base segment comes from settings, default `advertiser`.

```
/advertiser/                        → dashboard
/advertiser/{route}/                → a top-level screen
/advertiser/{route}/{object}/       → a screen scoped to one object
```

Routes: `campaigns`, `organization`, `account`, `help`, plus the public `login`
and `signup` account-entry screens. So `/advertiser/campaigns/123/` is the
campaign detail screen. Public routes never accept an object segment.

Three rewrite rules, registered `top`:

```php
'^advertiser/?$'                 → index.php?aggr_portal=1&aggr_route=dashboard
'^advertiser/([^/]+)/?$'         → index.php?aggr_portal=1&aggr_route=$matches[1]
'^advertiser/([^/]+)/([^/]+)/?$' → index.php?aggr_portal=1&aggr_route=$matches[1]&aggr_object=$matches[2]
```

`aggr_portal`, `aggr_route`, and `aggr_object` are registered via the `query_vars` filter. Route and object are parsed into an immutable `Portal\Request` value object which validates the grammar — unknown routes, over-long segments, and anything containing a path separator resolve to a 404 before the controller runs. `Request` has no WordPress dependency, so the grammar is unit-tested without a bootstrap.

## Request lifecycle

```
parse_query      → if aggr_portal: is_home = false, is_404 = false, pre_handle_404 = true
template_redirect → Gate: redirect to the portal login if logged out
                          allow account-entry routes without a session
                          403 template if no aggr_access_portal
                          wp_robots noindex
template_include  → templates/portal/base.php
```

Forcing `is_404 = false` at `parse_query` matters: without it core resolves the request as a 404 before `template_include` ever runs, and the portal renders inside the theme's 404 template with a 404 status code. Search engines and uptime monitors both notice.

The portal login handler delegates authentication to `wp_signon()` so core and
authentication plugins retain ownership of password verification, cookies,
sessions, SSO and two-factor filters. The portal owns only the presentation,
non-enumerating errors, rate limit and same-host destination validation.

## Why a rewrite rule, not a page with a block

The honest trade-off, since this decision is load-bearing.

**What it costs.** There is no row in the Pages list, so "where does `/advertiser/` come from?" is a harder support question. A rewrite flush that never ran produces a 404 that looks exactly like a broken deploy. Activation writes the rules (hard flush); a version bump covers file-only deploys; `Install\Rewrite_Health` asserts the end state in Tools → Site Health and offers a re-flush.

**What it buys.** The portal is a multi-screen area, not a page. A page-plus-block design expresses `/advertiser/campaigns/123/` as either a query string or one WordPress page per screen — and every one of those pages is something an editor can rename, trash, reorder, or paste a pattern into. A route the plugin owns cannot be edited into a broken state, and it removes the entire class of "the portal disappeared because someone trashed a page" incident. It also means the portal exists the moment the plugin activates, with no setup step and nothing to document.

## The rewrite flush

Never on every request — `flush_rewrite_rules()` rewrites `.htaccess` and regenerates every rule in the site, and calling it per-request is a well-known way to make a site inexplicably slow.

`Plugin::activate()` and `wp_initialize_site` call `Rewrite_Flusher::flush()`, which registers the portal and click-hop rules, then calls `flush_rewrite_rules( true )`. That is the same write Settings → Permalinks → Save performs. It has to happen in the activation hook because `activate_plugin()` includes this file after `init` has already run, so the plugin's `init` callbacks never fire on that request. Soft flush (`false`) updates the `rewrite_rules` option and leaves Apache's `.htaccess` stale, which is why `/advertiser/` 404s until someone clicks Save. Pretty permalinks must already be on; activation does not change `permalink_structure`.

File-only deploys never see the activation hook. `Rewrite_Flusher::maybe_flush()` runs on `init` priority 99, compares `aggr_rewrite_version` and `aggr_delivery_rewrite_version` against the class constants, and hard-flushes once if either differs.

**Shipping a route change means bumping the constant.** That is the whole deployment procedure for routing after the plugin is already active.

### Two things make the bump non-optional

The procedure above used to be discipline, and discipline is not a control. Both halves are now enforced.

**Before the push.** `bin/ci/check-rewrite-version.php` runs in `lint:files`. It calls `Router::rules()` and `Click_Hop::rules()` — pure, static, no WordPress — fingerprints what they return, and compares that against `bin/ci/rewrite-contract.json`. Changing a rule without bumping the constant fails the lane and prints the entry to append:

```
portal: the rules changed but Portal\Router::REWRITE_VERSION is still 2.
    Bump it to 3 and append to rewrite-contract.json:
      { "version": 3, "hash": "0d9ae0…" }
```

The contract's history is append-only, and versions must increase. That is deliberate: re-recording a hash under the version already there is precisely the mistake the guard exists to stop, so the only move that makes it pass is the one that also fixes the deploy. It compares the rules as **data** rather than as source text, so reformatting a method is not a rule change and a rule assembled from constants elsewhere still is — and it fails on any `add_rewrite_rule()` call outside a versioned rule set, so a third rule source cannot appear unversioned.

**On the site.** `Rewrite_Health` compares every declared rule against the installed `rewrite_rules` option **by key and by target**. A rule that is missing, or one that survives but now points at a different query, is reported critical with a repair button. It deliberately does not read `aggr_rewrite_version`: that option records only that a flush was *attempted*, and a restored database or a rules row regenerated by another plugin leaves the version current and the rules gone.

The two are not redundant. The guard catches the developer who forgot; the health check catches every cause that has nothing to do with a developer.

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
It creates an organization-scoped `aggr_draft` and redirects to the ordinary
`/advertiser/campaigns/{id}/` detail URL, keeping the documented URL grammar
numeric rather than inventing a special `new` object segment.

The dashboard also lists each active package as a button in one create form.
The button pressed posts its own `package_id`, so choosing what to buy and
starting the campaign are one click: the draft opens on details with that
package applied. A package that cannot be applied leaves the draft in place
and reports why on it.

The detail screen is also the resumable wizard surface. It has three steps.
There were five: the destination step only read back addresses the creative
step had already collected, its dates belonged beside the package that prices
them, and submit was the review screen with a notes box. Each cost a page load
and asked nothing new.

**Package & dates** asks what the campaign is and when it runs. There is no
name field. The name used to be the first question, which made inventing a
label the price of starting; the wizard now names an unnamed campaign after
its plan — "Launch bundle – October 2026" — and keeps that name following the
package and start month until somebody renames it. The page heading is the
rename control: the autosave module turns the heading's text into a button
styled to be indistinguishable from it, and Enter or leaving the field saves
through REST autosave. Without script, review carries an ordinary rename form.
`_aggr_title_is_automatic` records that the name is the wizard's, beside
`_aggr_title_is_placeholder`, which still blocks submission of "Untitled
campaign".

The package list presents only active, completely configured catalogue entries
as native radio controls, with price, duration, and included placement sizes.
A package explicitly marked for a custom schedule displays that label instead
of inventing a duration, and the active package marked as default is
preselected only while the campaign has no saved package. The package is
**not** `required`: a draft must be savable before anything is decided, and
`Review_Readiness` points the missing-package error back at this fieldset.
Choosing a package no longer submits the step, because a date now sits beside
it; Continue is the only way on.

The schedule is beside the package because the package prices it. A fixed
package sells a number of calendar days, so its end date is derived —
`Campaign_Rules::fixed_end_ts()`, counting the start day as day one, set by
calendar day so a daylight-saving change cannot move it — and stated as "Runs
through …" instead of asked for. The end field is still rendered, disabled and
hidden, so it is not posted and switching to a custom package only has to
enable it; a custom package asks for an optional end. `Campaign_Editor` derives
the end whenever the package or start moves and no end was supplied, so an
explicit `end_ts` from a REST client or a staff correction still wins. Leaving
this step with a start date applies the submission-grade window, so a past
date is refused beside the field; leaving without one is allowed. Dates travel
as the local `YYYY-MM-DD` string the input holds and are resolved by
`Date_Input::parse()` on the server, never in the browser: a date input carries
no timezone, and a stamp built client-side is the visitor's zone rather than
the site's.

**Ads** restates the plan — package, dates, price, and how many sizes are
ready — above one upload card per package placement, including exact
dimensions, a downloadable blank SVG template at that size, a native file
input, destination URL, authenticated preview, and nonce-protected removal.
The Destination card above the sizes is a real field: `default_click_url`,
saved on the campaign by autosave once the browser accepts it as a URL, or by
a "Save link" button drawn only inside `<noscript>` (a refused link returns to
this step). Under it, "Used by N of M ads" counts sizes whose first ad goes to
the link; "Add tracking tags" is drawn disabled until #291.
Typing in it updates every card whose link is empty or still the old one; a
card changed by hand keeps its own. Without a campaign link, the first uploaded
ad's link is used as before. Once the campaign has a destination, every further
card arrives with that link filled in and folded behind "Goes to …", so choosing a file is the whole
upload; the field is still a posted input inside a native `<details>`, and a
refused address reopens it. Accessible image text is generated from the validated
destination host unless an API client supplies its own. The upload sends itself
once a file and a valid destination are both present, so the submit button is
hidden — by script, after the module attaches, so a browser without it keeps
the ordinary form. It commits on `change`/`blur` rather than `input`, because a
half-typed address such as `https://exa` is already a valid URL and would
upload to it; the button is restored if an attempt is refused. Uploading is a
change of context, so a sentence describing it precedes both controls and is in
`aria-describedby` on each (WCAG 3.2.2).

Continue to review is a POST, not a link. It advances the resume point only
when every placement is covered and the stored dates pass the window rule; a
refused date sends the advertiser to details, anything else back to the
uploads.

**Review & submit** opens on the **Ready check** and aggregates every current
submission problem with a link back to the exact step and field; the Ads row
names the sizes still without a file and its action reads **Add file**. While
the campaign is ready it also collects the advertiser's **Notes for the review
team** and offers **Submit for review**, and beneath it states the editing
lock, the withdrawal boundary and the changes-requested path. The notes are posted by that button rather
than autosaved — the gap between a last keystroke and the click is where a
debounced save loses them.

Resume points stored by the five-step wizard are mapped on read: `package` and
`destination` resume on details, where the questions they were asking now are.
`?step=submit` falls back to the stored resume point.

Beside every step sits an **order summary**: package and its duration, schedule
and its length in days, the sizes the package asks for (grouped, so two 728×90
placements read `728×90 ×2`), the link, how many ads are ready (from the Ads step
on) and the total, with the one action that moves the advertiser on —
**Continue to ads**, **Continue to review**, **Submit for review**. A locked
action says why beneath it; when all that is missing is one file, it names the
size. The page heading carries a breadcrumb, "All changes saved" while the
wizard is on screen, and "Step N of 3" on a phone, where the bar has no room
for labels. The first step's calendar states how many days are selected and
draws the design's key; marking days as limited or sold out waits on #293. The
wizard is a CSS container, so the two columns follow the panel's width rather
than the viewport's. On a wide panel the step's own primary button is hidden and
the summary's button submits the step's form through the `form` attribute; on a
narrow one the step keeps its button and the summary sits below as a card. There
is exactly one primary action on screen at any width, which is also what keeps
browser tests unambiguous. Review shows the submit button locked, with how many
things are left, until every submission check passes.

Package cards lead with the price, then the length and number of sizes in the
monospace face, then each size drawn to scale — `Catalogue_View_Data` supplies
the shapes, clamped so a skyscraper and a leaderboard both fit a card.

All three steps work without JavaScript.

Once the campaign is no longer editable its screen opens on a **status view**
(`partials/campaign-status.php`): the stages Draft → Submitted → In review →
Approved → Scheduled → Live → Complete as a dated line, a note on what the
advertiser can do now (with **Withdraw to edit** while that edge is open), an
activity list built from the dates the campaign already stores, and a campaign
card and thumbnails of its ads beside it. A stage with no stored date says `—`;
the full audit-backed activity log is #295.

Panels that describe a campaign which already exists — Summary, Creatives,
delivery strategy, ad updates, variant comparison, update history — are hidden
for as long as an advertiser has the wizard on screen. Staff editing on a
client's behalf keep them. `editable` alone is the wrong test for this:
`Edit_Window::allows()` is true for staff in every status, so keying on it
would blank those panels for a reviewer.

A completed or otherwise uneditable campaign can be copied from the detail
screen. Complete campaigns label the action **Renew campaign**; others say
**Duplicate campaign**. Both create a new draft with the stored snapshot and
artwork, never the old dates or provider ads. The campaign list offers each row's next
step in its last column: **Continue setup** on a draft, **Make changes** when
changes were requested, and **Run again** on a completed campaign, through the
same copy action. A copy
resumes on details, because the dates are the one thing it never carries.

The dashboard always shows campaign-by-state counts — running, in review, needs
your attention, and all campaigns — each with a line saying what it counts.
Impression, click and CTR tiles, the "Impressions per day" chart inside the
delivery card, and the table's impression column appear only when Reporting is
on. The window is chosen from 7/14/30/90-day links with the current one marked,
and a **Custom** fold holds the two UTC date fields. Below sit the five most
recent campaigns with **View all**, the first campaign waiting on the
advertiser with the review team's reason, and **Start a campaign** as a compact
package list. **New campaign** is also at the foot of the rail on every screen.

The campaigns list has tabs for the same slices with their counts, the current
one marked, and a search field drawn disabled until #296. Rows show the package
under the name and short `Sep 6 → Oct 4` schedules; the foot says
"Showing N of M". Native delivery is always recording.
They read `aggr_rollups` and never invented zeros. Spend stays absent.

The counts are tiles on the page ground, each linking to the filtered list, and
the delivery figures are tiles inside a card whose header carries the reporting
window — the two rows are told apart by what governs one of them, which is why
the counts can be tiles at all. Organization and Account use one layout: a
column somebody reads (people and pending access; your details) beside a
narrower column of things they act on (summary, invite, organization name;
signing in and password), stacking below 64rem. Help lists how a campaign runs
as numbered cards drawn from a CSS counter, so the steps renumber themselves,
then a strip of what artwork needs (formats derived from the upload rules), each
placement's size and limit with a blank template from `Domain\Size_Template` —
the same file the upload form offers — and the status glossary in two columns.


Saving details persists `creative` as the resume point. Saving a package copies
its current placement set, integer-cent price, and currency onto the campaign;
the catalogue remains mutable without retroactively changing the draft. Each
campaign form has its own campaign-bound nonce, and campaign-field writes carry
the optimistic revision token through `Campaign_Editor`. Creative writes have
campaign/placement- or creative-bound nonces and share `Creative_Manager` with
REST. The three per-variant controls on the creative step — share, dates, and
pause — are **assignment**-bound instead: their nonces are scoped to the
assignment id, and they carry the revision the page was rendered from, so a form
opened before somebody else's change is refused rather than winning. Share goes
through `Creative_Manager`; dates and pause go through
`Workflow\Assignment_Editor`, the same path the REST route drives, so the rules
for a delivery window and a status transition have one definition rather than a
portal copy. Creative files remain private, previews use the authorized stream with a
short-lived REST nonce, and invalid dimensions report the uploaded and required
sizes. Scheduled and live campaign detail screens expose **Your ads** as
selectable previews. The thumbnail opens a larger preview overlay; **Update**
opens the replacement form on the same dialog primitive. Draft removal is a
hash-link confirmation overlay that POSTs only after the advertiser confirms.
Each current placement accepts one private replacement, keeps the current ad
running while review is pending, shows the proposed preview and destination,
and permits withdrawal. Rejected revisions remain visible with staff feedback;
approved revisions become the new current creative. Drag/drop and client-side
type/size/dimension checks enhance the native file input; they do not replace it.

Leaving the creative step has its own campaign-bound nonce and optimistic
revision. Completion is not cosmetic: `Campaign_Editor::complete_creative()`
refuses to advance the resume point to `review` unless every selected placement
has a creative and the stored date window already satisfies submission-grade
rules. Existing REST clients continue to write Unix timestamps; the HTML form
performs the timezone conversion. Both paths enforce complete calendar days in
the WordPress site timezone: start at `00:00:00`, inclusive end at `23:59:59`,
or an open end. Fill eligibility ends at the next midnight.

Review is read-only apart from its rename and submit forms. `Review_Readiness` adapts the canonical
submission validator into advertiser-safe `code`, `message`, `step`, and
`target` values, discarding raw validation context that can contain URLs or
internal identifiers. The step draws them as one check list with a row each for
package, schedule, destination, ads and name (plus account when a problem is
about neither): a done row says what it holds and links to change it, and a row
with problems lists each message and links to the first problem's target. The
success state and issue summary are announced, edit links use the normal
resumable wizard URL plus an in-page target, and creative destinations remain
text instead of active external links. Submission re-runs the validator because
readiness can change while the review screen is open.

"See it on the page" is an `aria-hidden` illustration: the stored creatives on
an article sketch, wide sizes across the column and the rest in the side column.
The creative list under it says the same thing in words. Placing each ad where
its placement really sits is #294.

Submission is not another draft mutation. The durable resume point remains
`review`; REST autosave rejects `submit` as a persisted wizard step. Its campaign-bound nonce authorizes only final
submission, and the form uses the same transition rate limit and
`Campaign_State_Machine` as REST. The machine reauthorizes ownership and
capabilities, re-runs the validator against current storage, stamps submission,
writes the audit event, and dispatches notifications only after status commits.
A replay reaches an illegal edge, is audited as denied, and cannot create a
second successful submission. Post/redirect/get then renders the locked
campaign detail with an announced success notice.

## Advertiser signup

`/advertiser/signup/` is public when the **Public signup** module is on *and*
WordPress's **Anyone can register** setting is enabled. Turning the module off
makes the route a 404, not a closed form. An invitation token is membership,
not open registration, so that URL still resolves. `aggr_signup_enabled` may
replace the WordPress-switch answer for a managed identity policy; it cannot
reopen a disabled module. The default fails closed so installing the plugin
does not silently open account creation.

The normal form accepts a name, organization and work email, never a password.
Organization names are canonicalized to uppercase before persistence, so
capitalization cannot create inconsistent display variants. A private
server-side lookup compares an accent- and punctuation-normalized canonical key
and then a conservative fuzzy score. There is intentionally no public
autocomplete endpoint: exposing suggestions would expose the customer
directory. A unique match does not automatically attach the applicant. It
creates a subscriber-only pending request, emails the existing owner, and
requires an explicit approve or deny decision on the organization screen.
Until approval, a successful sign-in returns to the portal login screen with a
pending notice and creates no portal session.

Organization owners can instead issue a single-use invitation from the
organization screen. It is bound to the normalized recipient email, expires
after three days, is stored only as a salted hash, and leads back to the signup
screen. An invitation recipient does not retype the organization name. The
recipient either creates a subscriber account and receives the portal password
setup link, or attaches an existing WordPress account without replacing its
other roles. Only the owner or a user carrying `aggr_manage_orgs` can create,
approve, deny, or revoke access. The acting portal tenant is derived from the
authenticated user and is never accepted from a hidden field.

Invitation pages remain usable when a recipient already has a WordPress
session. Like password links, they are non-cacheable and send
`Referrer-Policy: no-referrer` so the query-string bearer token is not disclosed
to theme assets, analytics, or later navigation. A successful invite POST
drops the token from the redirect (it has been consumed) and lands on
`?aggr_signup=sent`. That confirmation still renders for a signed-in visitor;
bouncing them to the dashboard would hide the result of the invitation they
just used.

The nonce-protected `admin-post` signup handler is rate-limited by a hashed connecting-IP
subject and carries a honeypot that produces no data or mail. Existing emails
receive the same success response as new ones and no repeat email, preventing
both account enumeration and use of the endpoint as a mail-bombing primitive.

For a genuinely new organization, creation is deliberately ordered. The WordPress user starts as a subscriber,
the private organization and ownership meta are written and read back, then the
advertiser role is granted. A failure after either durable write compensates by
removing both records. Last, the plugin sends a single-recipient activation
message containing a password-reset key generated and validated by WordPress
core. A mail-transport refusal also rolls the writes back, so there is no usable
portal account without a delivered setup path.

The message links to `/advertiser/set-password/`, never `wp-login.php`. That
portal screen re-validates the expiring, single-use core key on both GET and
POST, enforces the local password floor, runs core's password-policy extension
hook, consumes the key through `reset_password()`, and returns to
`/advertiser/login/`. The token response is non-cacheable and sends
`Referrer-Policy: no-referrer` so a reset key cannot leak through a resource or
navigation referrer.

`/advertiser/forgot-password/` owns recovery for existing accounts with the
same portal-only message and reset screen. Its public response is identical for
missing, ineligible and real addresses, and requests are bounded per hashed
client address. Advertisers sign in with their work email; their opaque
WordPress login identifier is neither displayed nor accepted by portal forms.

## Theme independence

The portal must look and behave the same under any theme. The mechanisms:

- The document is ours, so no theme markup wraps it.
- All layout comes from scoped `.aggr-*` rules in `src/styles/` (compiled to `dist/styles/portal.css`). Only token defaults are cascade-layered; authored reset, layout, and component rules stay unlayered so generic host-theme element styles cannot outrank them.
- Every design token carries a literal value. Nothing resolves through `--wp--preset--*`, because Twenty Twenty-Five and the LAAO theme expose *different* preset names — a token defined as `var(--wp--preset--color--primary)` renders correctly on one and transparent on the other.
- The reset is scoped to `.aggr-portal`, never global. A plugin that restyles `body` is a plugin that breaks the host site.

The LAAO theme may override `--aggr-*` tokens to make the portal feel native. That is the only supported coupling, it is one-directional, and the portal is fully functional without it.

**This is verified, not asserted.** `tests/e2e/campaign-wizard.spec.ts` switches the active theme to Twenty Twenty-Five, logs in as an advertiser, loads the portal, and runs axe. An accidental theme dependency fails that test.

The one thing Twenty Twenty-Five will not provide is site header and navigation. That is deliberate — the portal is an application surface and a full-bleed shell is the right presentation. If a future phase wants theme chrome, `block_template_part( 'header' )` guarded by `wp_is_block_theme()` is the block-theme-safe call.
