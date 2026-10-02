# Staff admin UI

How the screens under **Advertising** in wp-admin are built, what they must look
like, and what is still uneven. The advertiser portal has its own document,
[portal-routing-and-ui.md](portal-routing-and-ui.md). Who can open which screen
is in [administration.md](administration.md) and
[roles-and-capabilities.md](roles-and-capabilities.md).

The portal and the staff screens are two surfaces of one product. The staff
screens carry the portal's identity: its soft white canvas, graphite primary
colour, Archivo headings, monospaced labels, stat tiles and status pills. They
carry it inside wp-admin's own chrome. The admin bar, the sidebar, notices,
routing and every control's behaviour stay WordPress's.

**This reverses an earlier decision, twice over.** The first staff screens used
the portal's old warm palette and read as a plugin bolted into the admin, so the
tokens were switched to core's palette. That made seven screens
indistinguishable from any other wp-admin page, and the publisher's verdict was
"boring". The portal has since moved to graphite and Signal orange, which sit
beside wp-admin's dark sidebar without fighting it, so the staff screens now use
the same palette.

## The screens

Eight submenus, in the order they appear in the sidebar. Every one is gated by
its own capability. The umbrella issue tracks the work (see
[Implementation slices](#implementation-slices)).

| Menu | Slug | Capability | Primary job | Rendered by | Change needed |
|---|---|---|---|---|---|
| Review | `aggr-review` | `aggr_review_campaigns` | Clear the queue: approve, reject, pause, resume and cancel campaigns, and decide on advertiser requests | `Review_Screen` → `src/admin/review/` (React, portal design system via `admin.css`) | Structural |
| Placements | `aggr-placement-mapping` | `aggr_manage_placements` | Keep the catalogue of sellable slots correct | `Placement_Screen` → `src/admin/inventory/` (DataViews + modal) | Minor polish |
| Outlook | `aggr-forecast` | `aggr_manage_placements` | See which placements are oversold and which have room | `Forecast_Screen` → `src/admin/forecast/` (summary figures + DataViews) | Minor polish, then a link with Placements |
| Organizations | `aggr-organizations` | `aggr_manage_orgs` | Suspend, reactivate and manage members of advertiser accounts | `Organization_Screen` → `src/admin/organizations/` (server-paged DataViews + modals) | Minor polish |
| Conversions | `aggr-conversions` | `aggr_manage_settings` | Define conversions and issue server-to-server credentials | `Conversions_Screen` → `src/admin/conversions/` (two DataViews + modals) | Minor polish |
| Packages | `aggr-packages` | `aggr_manage_packages` | Decide what advertisers can buy and at what price | `Package_Screen` → `src/admin/packages/` (a card with a full form per package) | Structural |
| Reports | `aggr-reports` | `aggr_view_reports` | Explain fill: how often slots filled, and why they did not | `Reports_Screen` (PHP postboxes and tables) + `src/admin/reports/` (utilisation DataViews) | Structural |
| Settings | `aggr-settings` | `aggr_manage_settings` | Modules, brand, live edits, delivery, retention, reviewer access | `Settings_Screen` → `src/admin/settings/` (cards of autosaving controls) | Minor polish |

There is no overview screen. The parent menu item redirects to the first screen
the user can open (`Menu::redirect_to_first_screen()`), and waiting review work
is surfaced by the menu badge (`Pending_Work`) and the admin notice
(`Action_Notice`) on every wp-admin page.

### Findings per screen

**Review** is the most-used screen and still loads the portal stylesheet: its
page uses `aggr-button` buttons where every other screen uses core's `Button`.
Its dialogs are core's `Modal` with core controls, like every other screen's
(`decisions.tsx`, `queue.tsx`). The queue has the shared header, the portal's filter
chips and the shared table style. The queue is server-paged, so sorting and
search stay off until the server can answer them (see `queue-table.tsx`). Keep
that.

The campaign view is two columns: the artwork, decisions, delivery policy and
audit trail in the wide one, and the record (summary with schedule progress,
strategy, notes) beside it. It has the shared header, which keeps Edit; the
status decisions are in the decision bar at the end of the content, stuck to
the bottom of the screen, beside the status and the checklist's verdict.
Cancel is an outline until its dialog confirms it.
The delivery policy is fields — caps, "at most N per visit", days and hours,
targeting conditions — and any stored shape the fields cannot show stays as
JSON, so saving cannot flatten a rule the form did not understand
(`policy.ts`). The audit trail (`activity.tsx`, `trail.ts`) heads each day once,
draws status changes as pills, folds runs of identical entries into one with a
count, and labels refusals in words.

While a decision is waiting (submitted or in review), the view opens with
**Before approval**: six checks — details, advertiser, package and price,
schedule, placements, artwork and links — each ticked or carrying the reasons
it blocks. It is `Admin\Approval_Readiness`, which runs
`Campaign_Validator::validate_for_approval()`, the same check the approval
guard runs. The grouping is the domain's (`Campaign_Rules::check_group()`), and
the sentences are the validator's. It must never be computed anywhere else:
`ReviewDetailTest` runs the guard on the same campaign and fails if the guard
gives a reason the list does not show.

The delivery policy is collapsed to a row of facts from the saved line item
("Priority 100", "No impression limits", "Everyone"); **Edit delivery** opens
the form. A stored shape the fields cannot show reads "Custom rule", never
something simpler than it is (`policyFacts()` in `policy.ts`). The summary
panel holds the record once — no organization (the header has it), no pacing
(the policy says it), and the line item's name only when it was renamed — in
translated labels. On a narrow screen the summary moves above the main column.

**Check a class name against the portal before using it on this screen.**
Review loads the portal stylesheet, and four names have collided here already:
`.aggr-timeline`, `.aggr-activity`, `.aggr-readiness` and
`.aggr-panel__headrow` are all portal components. Each one restyled the staff
element that borrowed its name. The staff equivalents are `aggr-trail`,
`aggr-approval` and `aggr-policy-head`. `grep -rn '\.aggr-yourname' src/styles`
before naming anything new.

**Packages** rendered every package as an always-open edit form, each with its
own Save button, so reading one price meant scrolling past every other
package's fields. It is now a grid of product cards: price, run length,
placement chips, and Active and Default badges. One dialog creates or edits.
Cards, not a table, because a catalogue is a handful of offers and the portal
shows advertisers the same packages as cards. If catalogues grow to dozens,
revisit this.

**Reports** put its only action, the CSV download, at the very bottom, under
every table. It now sits in a toolbar beside the filters it exports. The two
fill-rate tiles share the width with a meter under each rate, and the two
no-fill tables sit side by side. Each no-fill reason says whether it is a rule
**working as intended** or **worth a look**. That split is
`No_Fill_Reason::is_expected()`, the same split administration.md describes
in prose. The window and placement filters still have no
visible labels.

**Outlook** and **Placements** share a capability and a subject, and now link
to each other. Outlook states its window and that it counts page opportunities
only. Each row's booked figure has a bar against its forecast, red when
oversold.

**Settings** is laid out as explanation beside controls: each section's icon,
title and purpose in a sticky left column, and its controls in a panel on the
right. Brand shows the advertiser portal in miniature, in the colours being
chosen, before they are saved.

**Organizations**, **Conversions** and **Settings** mostly work. Remaining
issues: Conversions' first table has no heading while its second does.
Settings' "Billing UI" module toggle describes a domain that P19 has not built.
Its documented behaviour is correct, but a switch for something absent reads
as a promise.

### Problems shared across screens

These were true before the modernization started. The ones marked **fixed**
have been closed since.

- **No identity.** Seven screens were stock wp-admin: core's grey, blue, 23px
  titles and flat tables. Nothing said they were one product, or the same
  product as the portal. **Fixed** by the identity slice.
- **Two design systems.** Review uses the portal components; the other seven
  use `@wordpress/components` and DataViews. So there were two dialogs, two
  button vocabularies, two tab styles and two notice styles. Tabs, tables,
  colours and dialogs now match; the page's buttons do not.
- **A notice about the queue on the queue's own product.** "Advertising is
  waiting on you" printed on every Advertising screen, where the sidebar's
  Review count is already on screen. It pushed each screen's content down.
  **Fixed**: it now shows everywhere in wp-admin except the Advertising screens.
- **No page header.** Seven screens printed a bare core `<h1>`. None said what
  the screen was for, none had room for a primary action beside the title, and
  none printed `wp-header-end`. Core's `common.js` moves every admin notice
  after that marker, or after the first heading when it is missing. **Fixed.**
- **Tokens read and never declared.** `admin-native.css`, `reports.css` and
  `forecast.css` all read `--aggr-*` properties, and nothing on the Reports or
  Outlook screens declared them. Muted captions rendered in body ink, and the
  outlook's summary cards drew no border at all. **Fixed.**
- **One table surface, copied three times.** Organizations, Placements and
  Conversions each carried the same DataViews container block. Outlook and
  Reports had none, so their tables sat flat on the canvas. **Fixed.**
- **Skipped heading levels.** Settings and Packages put `<h3>` card titles
  directly under the page `<h1>`. **Fixed.**
- **An unnamed focus stop.** Outlook's scrollable table region took focus with
  no name and no visible focus state. **Fixed.**
- **Literal colours.** Outlook hard-coded `#b32d2e` and `#fff` instead of the
  danger and surface tokens. **Fixed.**
- **Primary actions in different places.** Placements and Conversions put
  "New …" in the DataViews toolbar. Review puts "Create campaign" in its page
  header. Packages uses a whole card. Reports put its export last (**fixed**).
- **Reflow, partly measured.** At 360 CSS pixels no Advertising screen widens
  the page: `scrollWidth` equals the viewport on all eight, measured in a
  browser on 2026-09-30. DataViews tables scroll inside their own surface with
  the actions column pinned, so the `overflow: hidden` on the surface does not
  clip columns. 320px, 200% zoom and forced colours are still unmeasured.
- **Sidebar order does not follow the work.** The sidebar runs Review,
  Placements, Outlook, Organizations, Conversions, Packages, Reports, Settings,
  so Packages sits apart from the other two sell-side screens.
  `Menu::landing_pages()` uses yet another order.
- **Title casing.** "Advertising Settings" is title case, while "Advertising
  reports" and "Inventory outlook" are sentence case.

### What to reuse

- `Admin\Shared_Assets`: one DataViews bundle, and `enqueue_bundle()` for
  each screen's script.
- `Admin\Screen_Shell`: the header, its group eyebrow (`Screen_Shell::section()`)
  and the mount point.
- `Menu::body_class()`: `aggr-admin-screen` on `<body>`, which is how tokens
  and the graphite theme colour reach modals rendered outside the `.wrap`.
- `src/styles/admin-native.css`: header, surfaces, tables, stat tiles, pills,
  toolbar.
- `src/styles/base/_admin-tokens.css`: the palette, contrast-measured in
  `AdminContrastTest`.
- `src/admin/shared/state.tsx`: the status pill for a table cell.
- `src/admin/shared/icon.tsx`: `Icon` and `IconChip`, in the portal rail's
  geometry. PHP-rendered screens use `templates/portal/partials/icon.php`,
  which shares the same shapes.
- `src/admin/shared/initials.tsx`: `Named`, a name with its initials avatar —
  round for people, squared for organizations.
- `src/admin/shared/empty.tsx`: `Empty`, the icon and sentence a table shows
  when it has no rows.
- `src/admin/shared/save.tsx`: `useAction`, `SaveError`, the string table.
- `Admin\Review_Format`: how a date, a time, a status and a person print on
  the review screens. `Admin\Audit_Trail` and `Admin\Line_Item_Labels` are
  the timeline's rows and the line item's labels, apart from `Review_Data` so
  each vocabulary is one file.
- DataViews for any list of records. Core's `Modal` for any dialog on a
  DataViews screen.
- The campaign status pill and its fixed colours. It carries the campaign
  status vocabulary from `Post_Statuses`, and a status means the same thing on
  both surfaces.

Do not reuse the portal's layout or rail. wp-admin already has a sidebar, and a
second one inside it would be the "unrelated application" this is meant to
avoid.

### What a redesign can break

- **Authorization.** Every screen's `render()` checks its capability before
  printing anything, and every write goes through a REST route with its own
  `permission_callback`. `Screen_Shell` is presentation only. Moving a
  capability check below a render call changes authorization, not layout.
- **Payload contents.** The JSON bootstrap is what the browser may see.
  `ConversionsScreenTest` asserts an unauthorized render emits no reporting key.
  A redesign that adds fields to a payload widens what staff pages expose.
- **Query cost.** Opening a campaign is fifteen queries under review and
  twelve otherwise, however long its history. `Audit_Trail` primes every
  actor in one read; `ReviewDetailTest` fails if a field reintroduces a read
  per person or per row.
- **Review semantics.** The tabs map to `Review_Data::FILTERS`. The decision
  buttons are workflow transitions whose labels and availability come from
  `Review_Data`: "Start review", for example, is the explicit move to `review`
  that ends the advertiser's "withdraw to edit" window. A restyled button still
  calls the same transition and still appears only when the server offers it.
- **Routes and slugs.** Slugs appear in e-mails, notices and bookmarks.
  `aggr-placement-mapping` is historical and stays.
- **Tests.** `ScreenShellTest` counts one header per screen. Browser specs find
  controls by role and name, so renaming a heading or button is a test change.

## The contract

What every staff screen follows. It is only as big as the current screens need.
Add to it when a new screen needs something the existing ones do not.

**Container.** `<div class="wrap aggr-admin">`, which is core's `.wrap` for
gutters, plus the scope for the tokens. `<body>` carries `aggr-admin-screen`.
Use no max-width on the page. Prose keeps a 75ch measure, form cards 60rem and
text fields 34rem, and a table fills the width it has.

**Canvas and colour.** The portal's soft white canvas (`#f7f7f5`) and graphite
(`#111214`) as the primary colour. Graphite reaches `@wordpress/components`
through `--wp-admin-theme-color`, so primary buttons, toggles, checkboxes and
focus rings follow it without any control being restyled. The Brand settings
still recolour only the portal. A publisher's rebrand is for their advertisers,
not their staff.

**Header.** Printed by `Screen_Shell::open()` or `::mount()`; Review's React
queue prints the same classes. The anatomy is: an eyebrow (the Signal orange
mark and the group name — Campaigns, Inventory, Advertisers, Measurement,
Setup), the `<h1>` title, an optional one-sentence purpose line, an optional
actions slot on the right, then `<hr class="wp-header-end">`. Notices go under
it; core moves them there. The block is a `<div>`, not a `<header>`: core wraps
the page in `#wpbody[role=main]`, and a header inside it is a second banner
nested in main, which axe reports on every screen. `ScreenShellTest` counts
them at zero. The purpose line says what the screen is *for*, in
the words of the person using it. Leave it out when the sections already say
that (Settings does).

**Accent.** Bright Signal orange (`--aggr-color-mark`) is a shape only: the
mark. It fails contrast as text. The staff accent (`--aggr-color-accent`) is the
text-safe `#b5401a`, and it appears where the portal uses it: the hover of a
record's name. It never goes on a primary button, which is graphite.

**Type.** Archivo, self-hosted, for the page title (32px/700, tracking
-0.025em), section headings (19px/650; 17px in a card header) and figures
(30px/700, tabular). Small labels — the eyebrow, table headings, stat labels,
dates in data — use the system monospace at 10.5px/600, uppercase, spaced
0.1–0.12em. Body text and controls stay in core's system font, so a form reads
like every other form in wp-admin. Secondary text uses
`--aggr-color-text-muted`. Figures are never accented, except a count of
problems in the danger ink.

**Headings.** One `<h1>` per screen. Sections are `<h2>` and never skip a level.
A card's title is its section's heading.

**Spacing.** 24px under the header, 20px between cards, 32px between unrelated
sections, 12–16px inside toolbars and cards. Use a stack's `spacing` or `gap`,
never a control's margin.

**Cards.** A card groups controls that save together or figures that are read
together. A list of records is a table, not a stack of cards. Never nest a card
in a card. Every surface — card, table, stat tile — is white with a hairline
border, the portal's 12px radius and no shadow.

**Stat tiles.** An icon and monospaced label, the figure, an optional meter
and caption, and the counts it came from in monospace below. A tile shows a
number the domain computes, never a derived score. Outlook and Reports use
them.

**Icons.** Use an icon where it helps someone scan: a section's chip, a tile's
label, an empty state, a link to another screen. Never on every button or
column heading. Every icon sits beside a word that says the same thing and is
`aria-hidden`. Draw new shapes in the portal's geometry (24-unit box, 1.75
stroke, round caps) and add them to the one set.

**Meters.** A rate can be drawn as a length beside or under its figure: a
graphite bar on the sunken tint, `aria-hidden`, and never without the number.
It goes red only when the row's own verdict already says oversold.

**Identity in tables.** A person or an organization in a table gets its
initials avatar (`Named`). A slug or key goes under the name it spells, in
monospace, not in a column of its own. A placement's size gets its outline
drawn to scale.

**Tables.** Use DataViews for records. The surface and the portal's header row
(tinted, monospaced uppercase labels) are shared in `admin-native.css`. Do not
restyle them per screen. The primary column is graphite at 600 weight, and the
other cells are body ink with tabular figures. A state column renders its label
through `State` as a pill. When a table can be wider than the viewport, wrap it in
`.aggr-scroll-region` with `role="region"`, a name and `tabindex="0"`. Sorting
and search appear only when they apply to the whole set. A server-paged list
keeps them off until the server can answer them.

**Actions.** A screen's one creating action is a primary button: in the
DataViews `header` slot on a list screen, or in the page header when the screen
is not a list. Row actions live in the DataViews actions menu, with the one most
often used marked `isPrimary`. A destructive action is `isDestructive`, asks for
confirmation in a `Modal` that names the consequence, and is never primary
where it is offered. The button inside that confirmation which commits it is
primary and `isDestructive` (Suspend on Organizations, Cancel on Review).
Approval on Review stays the one green "positive" button.

**Status.** Use the domain's words and colours only: the campaign pill from
`Post_Statuses`, or a screen's own state from its data (forecast verdict,
organization active or suspended, placement active, credential live or revoked).
`State` maps each to a tone — live, neutral, pending, attention, danger — which
is emphasis over the word, not a status of its own. Every status carries a word
and a dot, never colour alone. Do not invent a derived status in the browser.

**Empty states.** Keep the table and its controls, and put `Empty` inside it:
an icon and a sentence saying what is missing and why it matters. The
creating action stays in the toolbar above rather than being repeated. Do not
show a blank table, and do not replace the table with a sentence, which
removes the search needed to undo the query.

**Layout.** Lay out each screen for what it shows, not as one stretched
column. Two things read against each other go side by side. A settings
section puts its explanation beside its controls. A small catalogue is a card
grid. A list that grows is a table. Nothing stretches a text field across the
monitor.

**Notices.** A server-side outcome uses core's notice, which goes under
`wp-header-end`. An outcome of an in-page action uses the `Notice` component
above the content it concerns, and is dismissible when it is only information.
Errors stay until the next attempt.

**Dialogs.** Use core's `Modal`, sized to its content (`width: fit-content`
between a floor and a measure), with core controls inside. Focus returns to the
control that opened it. Pass `focusOnMount="firstContentElement"` so focus
lands on the first field, or on the safe answer when there is no field. Do not
build a dialog: `AdminDesignSystemTest` fails on a `role="dialog"` in Review's
sources.

**Decision bar.** When a screen's decisions are read about at length before
they are taken, put them in `.aggr-actionbar` (`_action-bar.css`) at the end of
the content. It is `position: sticky` at the bottom, so it rides the screen
while the content is in view and settles in place at the end. Each button
exists once, never mirrored from the header. It carries the status and why a
decision is blocked, because that is where the eye is when the decision lands.
On a phone it is the actions alone. `scroll-padding-bottom` keeps a focused
control above it (WCAG 2.4.11). An error raised from it is scrolled into view,
since the screen's notice prints at the top. It lives in the portal's
component set so the wizard's phone bar (#302) reuses it rather than building
another.

**Responsive.** Nothing may widen the page at 320 CSS pixels. Tables scroll
inside their surface, and toolbars and header actions wrap. Check at 200% zoom
and in forced colours. The mark switches to `CanvasText` there.

**Performance.** Every screen renders its first view from the payload the page
already carries. It does not fetch after load to fill the page. A summary figure
is a count query, not a sum over loaded rows.

## Implementation slices

Each slice is one reviewable PR. The umbrella issue links them as they are
scoped.

1. **Foundation** *(shipped first)*. `Screen_Shell` and the header on seven
   screens, tokens declared on every screen, the shared table surface, heading
   levels on Settings and Packages, and Outlook's named focus region. No
   behaviour, route or capability changes.
2. **Identity** *(second)*. The portal's palette, type, eyebrow, tables, pills,
   stat tiles and filter chips on every staff screen, graphite through
   `--wp-admin-theme-color`, the Reports export beside its filters, and the
   waiting-work notice kept off the Advertising screens. Review's queue joined
   the shared header.
3. **Layouts** *(third)*. Settings as explanation beside controls with a
   portal preview, Packages as product cards with one editor dialog, Reports
   side by side with meters and no-fill diagnosis, Outlook and Placements
   cross-linked with booked bars and size outlines, initials avatars, empty
   states, and a shared icon set.
4. **Review campaign view** *(fourth)*. Two columns, shared header, the
   delivery policy as fields with a JSON fallback (collapsed to a summary),
   the audit trail as a timeline, schedule progress, the Before approval
   checklist from the guard's own validation, one translated summary panel,
   quieter empty states, summary-first on a phone, and checkboxes that look
   like checkboxes, core's `Modal` for both dialogs, and the decision bar.
   Still open: the page's own buttons move to core's `Button` in their own
   change, so Review has one button vocabulary with the other screens; and
   #302 adopts `.aggr-actionbar` for the wizard on a phone.
5. **Sidebar and remaining copy.** Regroup the sidebar so the sell-side screens
   sit together (`add_submenu_page`'s position, not boot order), add visible
   filter labels on Reports, and fix the Billing module toggle's copy until P19
   exists.
6. **Final pass.** Measure 320px reflow, 200% zoom and forced colours on every
   screen in the browser suite, and record the evidence here.

An operational overview screen is deliberately **not** on this list. The data
for an honest one exists: `Pending_Work`, the review tab counts, fill rate and
the outlook's oversold count. But it should come after slices 4–5, so that it
links into screens that already follow the contract. It shows only counts the
domain computes. It shows no revenue until P19 exists, and no figure that
duplicates a screen without linking to it.
