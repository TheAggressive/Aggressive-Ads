# Production rollout runbook

Deploying Aggressive Ads to a live site, verifying it, and getting back if it
goes wrong.

Written as steps with checks rather than prose, because the value of a runbook
is entirely in being followed at 2am by somebody who did not write it. Every
step that can fail silently has an explicit verification; if a step has no
check, it cannot fail silently.

Day-to-day operation is [administration.md](administration.md).

---

## 0. Before you touch production

| Requirement | Why it is not optional |
|---|---|
| PHP 8.4+ | The floor guard in `aggressive-ads.php` refuses to boot below it |
| Pretty permalinks | The portal is a rewrite rule. Plain permalinks 404 it, and the plugin does not change `permalink_structure` for you |
| A real cron | WordPress cron is request-driven. Without traffic or a real cron, approved campaigns never go live |
| A writable uploads directory | Two-stage private creative storage lives there |
| An outbound mail path | Submission, approval, rejection and ending-soon notifications are the workflow, not extras |
| A tested database backup | Activation writes tables, roles and rewrite rules |

**If the site runs nginx, prepare the deny rule now**, before creative can be
uploaded:

```nginx
location ~ ^/wp-content/uploads(?:/sites/[0-9]+)?/ads-uploads(?:/|$) {
    return 404;
}
```

nginx reads none of the `.htaccess`, `web.config` or `index.php` files the
plugin writes. Adapt the prefix if `upload_url_path` or the uploads directory
is customized. Step 4 verifies this, and it is the one check that must block a
launch.

---

## 1. Verify the artifact before it reaches the server

The archive is the product; the repository is not. Verify the bytes you are
about to install.

```bash
sha256sum -c aggressive-ads-<version>.zip.sha256
```

The release also carries build provenance attestation. A ZIP whose checksum you
cannot verify does not get installed — rebuild it rather than deploying it and
hoping.

---

## 2. Install and activate

Install the ZIP, then activate **once**.

Activation is doing four things that must all happen: migrate schema, install
tables and roles, seed options, and **hard-flush rewrite rules**. Activating is
therefore the reliable path; copying files over an existing installation is not.

```bash
wp plugin install aggressive-ads-<version>.zip
wp plugin activate aggressive-ads
```

**Check.** No fatals, and the version is what you shipped:

```bash
wp plugin list --name=aggressive-ads --fields=name,status,version
wp option get aggr_db_version
```

### If you deployed files without reactivating

That is the supported path for updates, and it covers itself: schema upgrades
run at `plugins_loaded` priority 5, and rewrite rules re-flush once when a
declared rewrite version moves.

The gap is a **route change shipped without a version bump**. Symptom: the
portal 404s and it looks like a broken deploy rather than a stale cache. Step 3
catches it.

---

## 3. Verify the portal resolves

```bash
curl -sI https://example.com/advertiser/ | head -1
```

Expect a redirect to the portal sign-in or a 200 — **not** a 404.

Then open **Tools → Site Health** and confirm **The advertiser portal is
reachable**. If it is critical, use its repair button; if it reports plain
permalinks, fix permalinks first, because flushing without a permalink
structure writes nothing and reports success.

---

## 4. Verify private creative is not publicly readable

**This is the gate that blocks a launch.** Do not accept uploads until it passes.

Open **Tools → Site Health** and read **Unapproved advertising creative is
protected**. It creates a random probe, requests it through the public uploads
URL, requires a 401/403/404/410, and removes it.

- **Good** — the server refuses direct requests. Proceed.
- **Critical** — unreleased creative is downloadable by anyone with the URL.
  Apply the nginx rule from step 0 (or confirm Apache is reading `.htaccess`),
  then re-run the check. **Do not let advertisers upload until this is good.**
- **Recommended** — the probe could not be created or the site could not request
  itself. Neither proves protection. Treat it as unresolved and verify by hand.

This re-runs daily and raises an admin notice if it ever regresses.

---

## 5. Verify cron

Without this, campaigns freeze at approval and nobody gets a notification. It is
the most common way a correct installation looks broken a week later.

```bash
wp cron event list --fields=hook,next_run_relative | grep aggr_
```

Expect six: `aggr_reconcile_campaigns`, `aggr_notify_ending_soon`,
`aggr_reconcile_fill_rollups`, `aggr_purge_fill_events`,
`aggr_purge_private_creatives`, `aggr_verify_private_storage`.

If `DISABLE_WP_CRON` is set — and on any site with real traffic it should be —
confirm the system cron actually runs:

```bash
*/5 * * * * cd /path/to/site && wp cron event run --due-now --quiet
```

**Check.** Run one by hand and confirm it completes:

```bash
wp cron event run aggr_reconcile_campaigns
```

---

## 6. Configure, then verify the configuration took

Advertising → Settings.

1. **Brand** — product name, logo, colours. Save. A WCAG AA failure is rejected
   at save; that is the check, and there is no override.
2. **Modules** — decide Reporting and Public signup deliberately. Off means the
   surface is absent, so switching Reporting on later is safe and reversible.
3. **Inventory** — create the placements that match the slots in the theme.
4. **Packages** — create what advertisers may buy.

**Check.** `wp option get aggr_settings` returns what you set.

---

## 7. Place the slots in the theme

Editors place a **slot**, never a campaign: the `aggr/placement` block, the
`aggr_placement( 'header-728x90' )` helper, or the shortcode.

Cached HTML holds a reserved box and a placement id. Fill happens after paint,
so a creative is never frozen into a page cache.

**Check.** Load a public page and confirm the reserved box appears with the
right dimensions, then confirm `GET /aggr/v1/fill/{slot}` returns a payload.

> An empty reserved box on every page usually means the theme has not been
> updated to embed `aggr/placement` yet. That is a theme change, not a plugin
> fault.

---

## 8. Verify the cache boundary

Two routes must never be cached, and a CDN in front of the site is the usual
place this goes wrong:

- `POST /aggr/v1/i` — the impression beacon. Cached, it counts once, forever.
- `/ads/c/{token}` — the click hop. Cached, every click credits one campaign.

Fill (`GET /aggr/v1/fill/{slot}`) may be cached only for its short TTL, and
state-machine transitions delete that placement's fill key in the same request —
so a paused campaign must not ride out a CDN TTL. If your CDN ignores origin
cache headers, exclude these paths explicitly.

**Check.** Load a page twice and confirm the impression count increments twice.
If it increments once, the beacon is being cached.

---

## 9. Smoke test the real workflow

Do this on production, once, with a real advertiser account. Every step above
verifies a component; this verifies the product.

1. Create an advertiser and organization.
2. Sign in at `/advertiser/`. Create a campaign, upload creative, submit.
3. As staff, open Review. Confirm the private creative preview renders and the
   advertiser cannot reach the file directly.
4. Approve. Confirm the advertiser receives the email.
5. Wait for `aggr_reconcile_campaigns`, or run it by hand. Confirm the campaign
   goes live and fills its slot.
6. Click the ad. Confirm it lands on the destination and the click is counted.
7. If Reporting is on, confirm the dashboard tile moves and the CSV export
   downloads.

---

## Rollback

**Deactivate first, and only deactivate.**

```bash
wp plugin deactivate aggressive-ads
```

Deactivation stops all behaviour and leaves every table, option and post intact.
Ads stop serving; nothing is lost; reactivating restores service.

**Do not "roll back" by deleting the plugin.** Deleting runs `uninstall.php`,
which drops the audit table, the event and rollup tables, the roles and the
options. It deletes campaigns, creatives and organizations only if
`aggr_delete_data_on_uninstall` was set — but everything else goes regardless,
and the audit trail is not recoverable from a page cache.

To return to a previous version, deactivate, install the older ZIP, and
reactivate. Note that **schema migrations are forward-only**: an older build
against a newer database is not a supported configuration, so restore the
database from backup if the version you are returning to predates a migration.

### If the portal 404s after a deploy

Not a rollback. Tools → Site Health → reinstall the rewrite rules, or:

```bash
wp rewrite flush --hard
```

### If creative became publicly readable

Treat it as an incident, not a deploy problem. Apply the server deny rule,
re-run the Site Health check, and assume any private URL that existed during the
window was exposed.

---

## Deployment-specific qualification

The Phase 11 authorization review, audit-table volume test, and reference HTTP
load/soak qualification are complete. The recorded result in
[load-and-soak-testing.md](load-and-soak-testing.md) proves the reference stack,
not this deployment. Before launch, run the same fail-closed harness against an
isolated staging clone of the actual web tier, PHP worker pool, object cache and
database topology. Record that result with the release evidence, and do not
extrapolate the reference throughput to materially different hardware.
