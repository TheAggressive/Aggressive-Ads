# Administering Aggressive Ads

For the person who runs the site, not the person who changes the code. It
covers what the screens do, who can reach them, what runs on a schedule, and
what to do when something looks wrong.

Deploying it for the first time is a different job — see [runbook.md](runbook.md).

## The two audiences

The plugin serves two groups who never share a screen, and that separation is
deliberate rather than cosmetic.

**Advertisers** live entirely at `/advertiser/`. They never see wp-admin —
`Security\Admin_Guard` closes it to them — so everything they can do, including
changing their own password and email, has a portal screen. If you find
yourself telling an advertiser to "go to Users → Profile", something is wrong;
there is a portal route for it.

**Staff** work in wp-admin under a single **Advertising** menu. They do not use
the portal.

## The Advertising menu

Six submenus, each gated by its own capability. A person sees only what their
capability grants — there is no "advertising manager" role that unlocks the
whole menu.

| Screen | Capability | What it is for |
|---|---|---|
| Review | `aggr_review_campaigns` | The queue. Approve, reject, pause, resume, cancel; internal notes; audit history |
| Organizations | `aggr_manage_orgs` | Tenants, membership, suspension |
| Inventory | `aggr_manage_placements` | The placement catalogue — the slots a campaign can be bought into |
| Packages | `aggr_manage_packages` | What advertisers may buy, and at what price |
| Conversions | `aggr_manage_settings` | What counts as a conversion, and the credentials advertisers report them with |
| Settings | `aggr_manage_settings` | Modules, brand, delivery, tracking |

`aggr_access_staff` is **derived**, never granted on a role. It is what the menu
itself tests, and it becomes true when a user holds any of the capabilities
above. Granting it directly will not do what you expect.

The parent slug is `aggr` and is stable; the menu's visible name follows the
Brand product name you set in Settings.

## Settings

One option, `aggr_settings`, behind one screen.

### Modules

Kill-switches. **Off means absent, not hidden** — the routes, menu entries and
fields genuinely do not exist, so switching a module off is not something a
determined user can navigate around.

| Module | Off means |
|---|---|
| Reporting | No impression, click or CTR figures anywhere: no tiles, no sparkline, no table column, no REST fields, no CSV export. Delivery keeps counting; you are choosing not to show the numbers |
| Public signup | `/advertiser/signup/` returns 404 unless the URL carries a valid invitation token |
| Billing | Currency fields still exist on stored campaigns; no checkout is implied anywhere |

**Native delivery is always on and is not a checkbox.** It is how ads are
served, not a feature.

### Brand

Product name, logo, tagline, and the accent and surface colours written as
`--aggr-*` custom properties onto `.aggr-portal` and the staff screens.

**Save rejects colour combinations that fail WCAG AA.** This is not advisory and
there is no override. A contrast failure saved once is a contrast failure on
every advertiser's dashboard until somebody notices, and nobody notices their
own brand colours.

The advertiser-facing default product name is **Advertising**, not "Aggressive
Ads". Advertisers are your customers, not the plugin's.

### Changes to running campaigns

Per-field checkboxes controlling what an advertiser may ask to change on a
campaign that is already scheduled, live or paused: campaign name, advertiser
notes, schedule, destination URL, placements.

**Every one ships off.** Tick nothing and the feature is absent — no button, no
form, and a refusal in the workflow if somebody hand-builds the request.

Nothing here takes effect on its own. A proposal is stored beside the campaign
and the campaign keeps serving exactly what you approved until a reviewer
accepts it, at **Advertising → Review**, on the campaign's own screen.

**Placements is not like the others.** A different placement means a different
required creative size, so approving one stops the campaign serving until the
advertiser uploads a correctly sized creative and it is reviewed again. Turn it
on only if you want advertisers to be able to restart a campaign, not adjust one.

### Delivery and Tracking

Fill cache TTL, house-ad policy, beacon behaviour and retention. The defaults
are the tested configuration; change them only with a reason you could write
down.

## What runs on a schedule

Six recurring events, plus two that run only while an upgrade is finishing. If
none of them are firing, the symptom is that campaigns stop changing status by
themselves — which looks like a bug in approval, not a cron problem.

| Hook | Runs | Does |
|---|---|---|
| `aggr_reconcile_campaigns` | hourly | approved → scheduled → live → complete. **Without this, status freezes at approval** |
| `aggr_notify_ending_soon` | hourly | One reminder per end date, seven days out |
| `aggr_reconcile_fill_rollups` | hourly | Rebuilds closed UTC days exactly from the event ledger |
| `aggr_purge_fill_events` | hourly | Bounded deletion of raw events past retention, never beyond the last reconciled day |
| `aggr_purge_private_creatives` | daily | Deletes private creative bytes for terminal campaigns older than ninety days. Records, checksums and Media Library attachments remain |
| `aggr_verify_private_storage` | daily | Re-runs the private-storage probe and raises an admin notice if creative became publicly reachable |
| `aggr_migrate_line_items` | one-off, re-queued a minute after each batch | The P1 backfill. Not recurring: each batch schedules the next, and the last one schedules nothing, so a settled site does not carry it |
| `aggr_migrate_creative_assignments` | one-off, same shape | The P2 backfill: an asset and an assignment row for every existing creative. Nothing serving reads them yet, so an unfinished walk cannot blank a slot |

WordPress cron is request-driven. On a low-traffic site these fire late; on a
site with `DISABLE_WP_CRON` and no real cron they never fire at all. See
[runbook.md](runbook.md).

## Site Health

Seven checks under **Tools → Site Health**. Each states a fact about this
installation rather than a recommendation.

**Unapproved advertising creative is protected.** Creates a harmless random
probe, requests it through the public uploads URL, and requires a 401/403/404/410.

A 2xx is reported as a **recommendation, not a failure**, because stored creative
is encrypted at rest: a server that hands out the file hands out an authenticated
blob, and the key is not in the directory. It is a missing layer rather than an
open door, and WordPress itself serves the media of unpublished posts from the
same directory with no deny rule at all. A red banner on every admin page of
every nginx site — usually in front of somebody with no server access — teaches
people to dismiss Site Health, and that cost is paid by the next warning that
matters.

The rule is still worth adding. The check prints the one for the server it
detects, with the alternatives underneath; for nginx it is:

```nginx
location ~ ^/wp-content/uploads(?:/sites/[0-9]+)?/ads-uploads(?:/|$) {
    return 404;
}
```

Adapt the prefix if `upload_url_path` or the uploads directory is customized. On
Apache the plugin already wrote a `.htaccess` that says this, so a site reaching
this state has a server told to ignore it — the fix there is `AllowOverride`, not
a new rule.

**The advertiser portal is reachable.** Reads the installed rewrite rules and
names any path that would 404. Offers administrators a button to reinstall them.
It deliberately does not trust the recorded rewrite version, because a restored
database leaves that version current and the rules gone.

**Delivery dependencies.** The tables and services native fill needs.

**Advertising viewability is being measured.** Reports the viewable-to-served
ratio for the last closed UTC day, and is the only place that can tell a dead
measuring script from a day nobody looked — the portal tile shows `0.0%` for
both. Four answers and one warning: nothing delivered and nothing measured are
ordinary states, a real rate is reported as the number, and a whole day of
impressions with no views at all is the one worth chasing. Across a day that is
almost never "nobody looked"; it is usually JavaScript being stripped by a cache
or an optimizer.

**Every creative has a delivery assignment.** Counts creatives with no assignment
row, and — the part that makes it useful — distinguishes a backfill still running
from one that finished and left creatives behind. The first is progress and needs
nothing; the second is the only version worth investigating. A check that
reported both as the same number would be noise for however many hours the walk
takes, and noise is what teaches people to stop reading Site Health.

**Advertising serving path.** Whether native fill is actually serving from
creative assignments, which is the condition that failed silently once and took
a release to notice — an assignment that never reached `live` matches no
candidate, so the slot is empty and nothing anywhere reports an error.

Three answers, and the middle one is the reason the check is worth having. If
the assignment backfill is still running it says so and calls it ordinary: paid
slots stay empty until every creative has an assignment, while house ads keep
serving. If the backfill reports finished and the serving table is missing, that
is critical and the repair is to deactivate and reactivate the plugin, which
reinstalls the schema.

**Advertising conversions are being recorded.** The check that answers "why is
this integration recording nothing", which is otherwise a question nobody can
answer from the outside. It separates the states that look identical from a
report: no conversion defined at all, no clicks yesterday to attribute against, a
day that predates conversion tracking, and — the one worth chasing — clicks
yesterday with no conversions recorded.

That last one is usually not "nobody converted". It is usually the reporting key
missing from the advertiser's page, or the `aggr_ct` parameter being stripped
from the destination URL before it arrives; a redirect service or a link
shortener in front of the landing page will do that silently. Check that a click
lands on a URL still carrying `aggr_ct`.

The check also names, in words, why reports were **refused** — a key this site
does not have, a conversion since archived, one belonging to another advertiser,
a report carrying no click this site recorded, one that arrived after the window
closed, a server report for a conversion that does not accept them, or a
credential scoped to another advertiser. Those counts are approximate and
deliberately so: they inform the description and never the status, because a
status is what makes somebody act and the exact record is the ledger.

## When something looks wrong

**Advertisers get 404 at `/advertiser/`.** Site Health → "The advertiser portal
is reachable", then its repair button. If the site uses plain permalinks, that
is the cause and the button cannot fix it: set any other permalink structure
first.

**An advertiser says they cannot edit their live campaign.** By design — the
approval describes what is being served. Either enable the fields you are happy
for them to propose under Settings → Changes to running campaigns, or have them
use the creative replacement flow. A campaign still awaiting review can be
pulled back with **Withdraw and edit** until a reviewer opens it.

**A campaign was approved but never went live.** `aggr_reconcile_campaigns` is
not running. Approval sets the campaign up for the clock; it does not transition
it. Check cron.

**All numbers are zero.** Either Reporting is off (then there would be no tiles
at all, not zeros) or delivery is not being counted. Zeros with tiles present
mean the beacon is not reaching `POST /aggr/v1/i` — check for a page cache in
front of the REST route, which must never be cached.

**Reviewers cannot see the Review screen.** Check the capability, not the role.
`aggr_access_staff` is derived and cannot be granted.

**A campaign's delivery settings show a name nobody chose.** Every campaign has
one line item — its delivery strategy — and by default the line item's name
follows the campaign title. Renaming the line item breaks that link on purpose:
from then on it keeps the publisher's name and stops following the campaign.
There is no way to re-link it from the UI, because a rename is a deliberate act
and silently undoing it would be worse than leaving it.

On a site upgraded to this version, names catch up in the background rather than
at once — see the note on the line-item migration below.

**The line-item migration seems stuck.** Upgrading creates one line item per
existing campaign, a batch at a time on `aggr_migrate_line_items`, then makes a
second pass to work out which names were chosen and which were inherited. On a
large catalogue that is many cron ticks. Nothing about it is urgent: serving,
editing and reporting all read campaigns and do not wait for it, so a
half-finished migration is invisible except that some line items still show the
name the wizard invented.

Its progress lives in four options — `aggr_line_item_migration_cursor`,
`aggr_line_item_migration_done`, `aggr_line_item_name_cursor` and
`aggr_line_item_name_done`. If the hook is not scheduled and the two `_done`
markers are not both set, cron is not running; the next admin request repairs
the schedule on its own once it is.

**Translations are not appearing.** A plugin's catalog must be named
`aggressive-ads-<locale>.mo`. The unprefixed `<locale>.mo` naming is correct for
themes and produces a file WordPress silently never opens here — see
[i18n.md](i18n.md).

## Uninstalling

Deleting the plugin removes its tables, roles, cron events and options.

**It does not delete campaigns, creatives or organizations unless you opt in.**
That is deliberate: an accidental deactivate-and-delete would otherwise destroy
every advertiser's history irreversibly. To opt in, set the
`aggr_delete_data_on_uninstall` option to a truthy value before deleting.

Private creative files and Media Library attachments follow WordPress's normal
rules for the posts they belong to.
