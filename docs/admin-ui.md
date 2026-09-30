# Staff admin UI

How the screens under **Advertising** in wp-admin are built, what they must look
like, and what is still uneven. The advertiser portal has its own document,
[portal-routing-and-ui.md](portal-routing-and-ui.md). Who can open which screen
is in [administration.md](administration.md) and
[roles-and-capabilities.md](roles-and-capabilities.md).

The portal and the staff screens are two surfaces of one product, not one
design. The portal is the product's own page, with its own canvas, typeface and
accent. The staff screens live inside wp-admin, next to core's lists and
notices, and follow wp-admin's conventions wherever it already has one. They
share the status vocabulary, the token names and the tone of the copy. They do
not share a palette.

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

**Review** is the most-used screen and the only one still drawn with the
portal's design system: pill tabs, `aggr-button` buttons, its own `Dialog`
(`role="dialog"`) where every other screen uses core's `Modal`, and a React
page header (`aggr-pagehead`) that sits inside `.aggr-portal`. It works and it
is contrast-gated. It also reads as a different product from the seven screens
around it. The queue is server-paged, so sorting and search stay off until the
server can answer them (see `queue-table.tsx`). Keep that.

**Packages** renders every package as an always-open edit form, each with its
own Save button, under a create form. At ten packages that is ten forms to
scroll through to find one. Nothing shows active, default or price at a glance.
This is the one screen that does not scale. It should become the Placements
pattern: a DataViews list with status, price and placements as columns, and one
modal editor.

**Reports** puts its only action, the CSV download, at the very bottom, under
every table. Its window and placement filters are a bare GET form with no
visible labels. The two figure cards are core postboxes and the reason tables
are `widefat`. Each piece is fine alone, but together they give the page no
visible structure.

**Outlook** and **Placements** share a capability and a subject. They are two
views of the catalogue with no link between them. Outlook's window is fixed
server-side and it shows page inventory only. Say that on the screen rather
than leaving it implied.

**Organizations**, **Conversions** and **Settings** mostly work. Remaining
issues: Conversions' first table has no heading while its second does.
Settings' "Billing UI" module toggle describes a domain that P19 has not built.
Its documented behaviour is correct, but a switch for something absent reads
as a promise.

### Problems shared across screens

These were true before the first slice. The ones marked **fixed** were closed by
it.

- **Two design systems.** Review uses the portal components; the other seven
  use `@wordpress/components` and DataViews. So there are two dialogs, two
  button vocabularies, two tab styles and two notice styles.
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
  header. Packages uses a whole card. Reports puts its export last.
- **Unverified reflow.** The shared DataViews surface uses `overflow: hidden` to
  clip the table to its rounded corners. At 320 CSS pixels that may clip
  columns instead of scrolling them. Reports measured DataViews at 465px wide on
  a 320px viewport before its tables were given a scroll region. Organizations,
  Placements and Conversions have not been measured. That belongs to the final
  audit slice, and it needs a browser.
- **Sidebar order does not follow the work.** The sidebar runs Review,
  Placements, Outlook, Organizations, Conversions, Packages, Reports, Settings,
  so Packages sits apart from the other two sell-side screens.
  `Menu::landing_pages()` uses yet another order.
- **Title casing.** "Advertising Settings" is title case, while "Advertising
  reports" and "Inventory outlook" are sentence case.

### What to reuse

- `Admin\Shared_Assets`: one DataViews bundle, and `enqueue_bundle()` for
  each screen's script.
- `Admin\Screen_Shell`: the header and mount point (below).
- `src/styles/base/_admin-tokens.css`: the admin palette, contrast-measured
  in `AdminContrastTest`.
- `src/admin/shared/save.tsx`: `useAction`, `SaveError`, the string table.
- DataViews for any list of records. Core's `Modal` for any dialog on a
  DataViews screen.
- The status pill and its fixed colours. They carry the campaign status
  vocabulary from `Post_Statuses`, and a status must mean the same thing on both
  surfaces.

Do not reuse portal layout, the portal rail, Archivo or the portal's canvas and
accent on staff screens. Sharing a colour is not a shared contract.

### What a redesign can break

- **Authorization.** Every screen's `render()` checks its capability before
  printing anything, and every write goes through a REST route with its own
  `permission_callback`. `Screen_Shell` is presentation only. Moving a
  capability check below a render call changes authorization, not layout.
- **Payload contents.** The JSON bootstrap is what the browser may see.
  `ConversionsScreenTest` asserts an unauthorized render emits no reporting key.
  A redesign that adds fields to a payload widens what staff pages expose.
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
gutters, plus the scope for the tokens. Use no max-width on the page. Prose
keeps a 75ch measure, and a table fills the width it has.

**Header.** Printed by `Screen_Shell::open()` or `::mount()`. The anatomy is:
the mark, the `<h1>` title, an optional one-sentence purpose line, a hairline,
then `<hr class="wp-header-end">`. Notices go under it; core moves them there.
The purpose line says what the screen is *for*, in the words of the person using
it. Leave it out when the sections already say that (Settings does).

**Accent.** Signal orange appears once per screen, on the decorative mark in the
header. It never goes on a control, a link, a focus ring or a status. In
wp-admin, blue already means "clickable" and the status colours are fixed, so
orange anywhere else would compete with both. Buttons and links stay core blue.
The Brand settings recolour the portal only.

**Type.** The page title is 23px/600. A section title is an `<h2>` at 15px/600,
which is the size `admin-native.css` gives postbox headings. Pass `size={ 15 }`
to `Heading` rather than choosing a level for its size. Body text is core's
13px. Secondary text uses `--aggr-color-text-muted`. Figures that are the point
of the screen are 28–32px/600 and never accented.

**Headings.** One `<h1>` per screen. Sections are `<h2>` and never skip a level.
A card's title is its section's heading.

**Spacing.** 24px under the header, 20px between cards, 32px between unrelated
sections, 12–16px inside toolbars and cards. Use a stack's `spacing` or `gap`,
never a control's margin.

**Cards.** A card groups controls that save together or figures that are read
together. A list of records is a table, not a stack of cards. Never nest a card
in a card. Cards have a hairline border, a 4px radius and no shadow.

**Tables.** Use DataViews for records. The surface (white, hairline, 4px radius)
is shared in `admin-native.css`. Do not restyle it per screen. The primary
column is 600 weight. When a table can be wider than the viewport, wrap it in
`.aggr-scroll-region` with `role="region"`, a name and `tabindex="0"`. Sorting
and search appear only when they apply to the whole set. A server-paged list
keeps them off until the server can answer them.

**Actions.** A screen's one creating action is a primary button: in the
DataViews `header` slot on a list screen, or in the page header when the screen
is not a list. Row actions live in the DataViews actions menu, with the one most
often used marked `isPrimary`. A destructive action is `isDestructive`, asks for
confirmation in a `Modal` that names the consequence, and is never primary.
Approval on Review stays the one green "positive" button.

**Status.** Use the domain's words and colours only: the campaign pill from
`Post_Statuses`, or a screen's own verdict from its data (forecast `oversell`,
organization active or suspended). Every status carries a word, never colour
alone. Do not invent a derived status in the browser.

**Empty states.** Keep the table and its controls, and put a sentence inside it
saying what is missing and, where there is one, what creates it. Do not show a
blank table, and do not replace the table with a sentence. That removes the
search needed to undo the query.

**Notices.** A server-side outcome uses core's notice, which goes under
`wp-header-end`. An outcome of an in-page action uses the `Notice` component
above the content it concerns, and is dismissible when it is only information.
Errors stay until the next attempt.

**Dialogs.** Use core's `Modal`, sized to its content (`width: fit-content`
between a floor and a measure). Focus returns to the control that opened it.
Review's own `Dialog` is the exception until the Review slice.

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
2. **Review.** Bring the queue and campaign views onto the header contract.
   Decide, with evidence, whether the staff queue keeps the portal's components
   or moves to core's, which means one dialog and one button vocabulary. Keep
   the status pills, the tab filters and server paging.
3. **Packages.** A DataViews list (name, price, placements, active, default)
   with one modal editor, following the Placements pattern.
4. **Inventory.** Cross-link Placements and Outlook, state the outlook's window
   and inventory kind on screen, and regroup the sidebar so the sell-side
   screens sit together (`add_submenu_page`'s position, not boot order).
5. **Reports.** Filters and export together at the top, visible labels, and the
   figure cards and reason tables on the contract.
6. **Organizations, Conversions and Settings polish.** Section headings,
   empty-state wording, and the Billing module toggle's copy until P19 exists.
7. **Final pass.** Measure 320px reflow, 200% zoom and forced colours on every
   screen in the browser suite, including the DataViews `overflow: hidden`
   question, and record the evidence here.

An operational overview screen is deliberately **not** on this list. The data
for an honest one exists: `Pending_Work`, the review tab counts, fill rate and
the outlook's oversold count. But it should come after slices 2–5, so that it
links into screens that already follow the contract. It shows only counts the
domain computes. It shows no revenue until P19 exists, and no figure that
duplicates a screen without linking to it.
