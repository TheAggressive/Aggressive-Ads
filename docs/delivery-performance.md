# Delivery performance and operations

Native delivery is designed around a large-placement target of **1,000 live
campaigns with one current creative each**. Catalogue size is not traffic
capacity: request rate, cache health, database latency, and event retention are
separate dimensions and must be measured on production-equivalent hardware.

## Measured query budget

`DeliveryScaleTest` builds 1,000 real campaign posts, creative posts, and
assignments in MySQL. It exercises the candidate read cap (up to 500 candidates)
and enforces these budgets:

| Operation | Maximum database queries |
|---|---:|
| Cold fill after candidate-cache deletion | 12 |
| Warm fill | 4 |
| Authoritative token validation after runtime-cache flush | 8 |

Run the regression with measurements visible:

```bash
bash bin/ci/environment.sh exec env AGGR_REPORT_PERFORMANCE=1 \
  php /var/www/html/wp-content/plugins/aggressive-ads/vendor/bin/phpunit \
  -c wp-content/plugins/aggressive-ads/phpunit-integration.xml.dist \
  --filter DeliveryScaleTest
```

Reference result from the PHP 8.4 container on 2026-08-14:

```text
1,000-ad delivery: cold=11 queries/165.29ms warm=3 queries/1.54ms validate=5 queries/1.03ms
```

Elapsed time is reported, not asserted, because shared CI hardware is noisy.
The deterministic gate is query count: it catches a return to campaign-by-
campaign reads. The repeatable Phase 11 concurrent p50/p95/p99 gate, reference
traffic profile and exact ledger/rollup acceptance check are documented in
[load-and-soak-testing.md](load-and-soak-testing.md).

## Serving path

The serving path reads from the `aggr_creative_assignments` custom table via
`Creative_Assignment_Repository::candidates_for_placement()`, capped at 500
candidates by index. A cache miss performs one indexed read for the placement's
live assignments, cached under `assignment_rows`. The `Decision_Engine` executes
the decision pipeline (`Decision_Pipeline`) over these candidates:
evaluating row-level eligibility, pass-through seams (targeting, frequency,
pacing, priority), and deterministic weighted selection (`Weighted_Selection`).
The winning assignment row is mapped to a token-free payload; a fresh signed
token is always minted per fill.

This avoids both N+1 query patterns and cross-tenant data leaks. A short
object-cache mutex limits concurrent rebuilds. Waiters poll for at most 200ms
and then render no paid ad instead of independently rebuilding and stampeding
MySQL. A token presented to the impression or click route is validated by one
exact creative-id/campaign-id/placement-id candidate query against current live
status via `Delivery_Repository::candidate()`, so pause and completion do not
depend on cached identity. Staff trace inspection (`GET /placements/{id}/decision`)
evaluates decisions on demand with exclusion metric recording bypassed.

## Production requirements

- Use a monitored persistent WordPress object-cache drop-in backed by Redis or
  Memcached. Without it, correctness is preserved but every PHP request has a
  cold candidate cache and rate limiting falls back to MySQL advisory locks.
- Run WordPress cron from a real system scheduler. Page-traffic-only WP-Cron is
  not sufficient for reporting repair and retention at scale.
- Run the Site Health delivery-capacity check before launch. It verifies both
  tracking tables, round-trips a representative 1,000-id cache item, proves
  atomic counter support, and checks that reconciliation and retention are
  scheduled.
- Terminate abusive traffic at the CDN/WAF. Cooperative crawler detection and
  application limits improve reporting quality but are not DDoS controls.
- When operating behind a reverse proxy, configure the web tier to restore a
  validated client address into `REMOTE_ADDR`. The plugin intentionally does
  not trust client-supplied forwarded headers.

## Tracking durability and growth

Every accepted impression/click is one append-only `aggr_events` row. The
unique `(token_hash,event)` key is replay protection. Daily `aggr_rollups` are
a reporting projection, not the ledger: the request attempts an immediate
upsert, while an hourly restartable reconciler rebuilds closed UTC days exactly
from raw events after a ten-minute midnight grace period. Its watermark
advances only after success.

**The grain is placement, campaign, line item, creative and UTC day.** The
creative joined it in v30 so two variants of one line item can be compared;
before that a line item held one row per day and its variants shared it. Rows
written before v30 keep `creative_id = 0`, and are not backfilled — for days
outside the ledger's retention the rollup is the only surviving record, so a
backfill would attribute the recent half of a window and leave the rest
unattributed, which changes what a chart means partway along its own x-axis.

Because the creative is part of the unique key, **the reconciler removes the
rows a day supersedes before rebuilding it**. A counter at `creative_id = 0` is
not the row the projection now lands on, so `ON DUPLICATE KEY UPDATE` cannot
correct it and the day would be counted twice. That delete matches only
`(placement, campaign)` pairs the day's ledger still covers — a day purged by
retention keeps its counters rather than being rebuilt as zero — and skips any
row whose creative the ledger still has, so the frozen `org_id` on it survives.

Retention runs hourly in batches of 10,000 rows, up to 100,000 rows per run,
and never deletes beyond the last reconciled day. At sustained rates above
2.4 million retained events per day, increase worker frequency through a real
cron after measuring delete latency; do not increase an individual transaction
without measuring locks and replica lag.

Traffic, not active-ad count, determines raw storage. Before clicks:

| Average impression rate | Events over 90 days |
|---:|---:|
| 1/second | 7,776,000 |
| 10/second | 77,760,000 |
| 100/second | 777,600,000 |

At the upper ranges, measure real row/index bytes, purge throughput, backup
time, replica lag, and restore time. Shorten raw retention or move the ledger
to dedicated analytics infrastructure before the operational limits of the
WordPress database are reached; rollups remain compact and authoritative for
the advertiser UI.

## Report reads are bounded by a range, not by retention

Every org-scoped read of `aggr_rollups` goes through `Rollup_Report_Repository`
and takes a `Domain\Report_Period` — a value object that cannot be constructed
unbounded, capped at 92 days. That is a type, not a convention, because a bound
each caller applies for itself is a bound one caller forgets.

The advertiser dashboard's first tile used to have no date predicate at all. It
summed everything the organization had ever delivered, on every page load, with
nothing to bring it back down as history accumulated:

| read | rows examined at one year |
|---:|---:|
| all-time org total (before) | 12,775 |
| 30-day org total (after) | 1,500 |
| 30-day org series (after) | 1,500 |

Measured on 18,250 rows for one organization — 10 campaigns × 5 placements × 365
days — inside a 25,550-row table. The ranged read costs 50 rows per day in range
and stays flat as the site ages; the unbounded one grows with retention, which
defaults to keeping every day.

The dashboard makes **two** of these reads rather than one, because each tile
carries its change against the preceding window. Two bounded reads beat one
wider read split in PHP, which would pull twice the rows into memory to answer
a question SQL can already scope.

`ReportReadScaleTest` holds the line, and it EXPLAINs `$wpdb->last_query` after
calling the repository rather than a copy of the SQL. A guard that explains its
own string keeps passing after the code it watches has changed — the failure
P13 hit twice. Restoring the missing predicate is what the test was proven
against: with the date filter made non-sargable the values stay correct and the
plan still names `org_day`, and the assertion fails at 12,775 of 18,250.

### Advertiser export resource budgets

Synchronous advertiser CSVs accept at most 5,000 campaign/day data rows,
1,024 UTF-8 bytes per campaign name, and 8 MiB of serialized CSV, within the
existing 31-day export window. `Report_Export_Limits` is the shared policy.
These are per-download limits, not limits on stored campaign names or history.

`Rollup_Report_Repository` groups the tenant/date range before joining names.
The aggregate explicitly selects `org_day` / `org_bucket`: repeated runs showed
the optimizer sometimes preferred a campaign-first plan that did not guarantee
the same tenant/date range bound. The derived aggregate retains at most 5,001
groups so an over-budget result is
detected rather than silently truncated. SQL masks a name over the byte limit
instead of transferring it into the PHP result set. The accepted result keeps
its full names and metrics. `Report_Actions` generates CSV rows without a second
materialized row array, and `Csv_Writer::bounded_document()` counts the actual
UTF-8 bytes after neutralization and quoting, including BOM, header, and CRLF.
The complete document is accepted before attachment headers are sent.

Measured 2026-11-11 on native PHP 8.5.6 / MySQL 8.0.46, one export at a time.
The fixture has one counter per campaign/day and independently varies name
width and catalogue size. Baseline is the export implementation at `02b22a3`.
Setup and fixture allocations are excluded from the peak-memory delta.

| Fixture | Before: added peak MiB | After: added peak MiB | After result |
|---|---:|---:|---|
| 1,000 campaigns × 31 days, 64-byte names, UTC | 43.81 | 3.77 | whole export refused |
| 1,000 campaigns × 31 days, 1,024-byte names, UTC | 136.17 | 14.15 | whole export refused |
| 160 campaigns × 31 days, 1,024-byte names, UTC | 21.94 | 20.83 | 4,960 rows; 5,227,913 CSV bytes |
| Same accepted fixture, Asia/Kolkata days | 21.94 | 20.80 | 4,960 rows; 5,227,922 CSV bytes |

The wide oversized read dropped from 522 ms to 98 ms in these samples; the
accepted UTC read took 38 ms plus 19 ms serialization. These are single-run
local measurements, not production latency percentiles or a concurrency claim.
CI's pinned PHP/MySQL versions remain authoritative for compatibility.

`ReportExportScaleTest` keeps the measured cases under 32 MiB of added PHP peak
memory and covers the exact row boundary, UTF-8 name bytes, escaped-quote byte
expansion, another tenant's oversized catalogue, and recovery after a refusal
or failed database query. `ReportReadScaleTest` EXPLAINs the actual UTC and local
export queries against a year of multi-tenant history; it requires a range scan
on `org_day` / `org_bucket` and limits estimated examined rows. The browser test
checks the actual refusal status, absence of attachment headers, and a complete
successful retry after shortening a name.

To print measurements, set `AGGR_REPORT_EXPORT_BENCHMARK=1` when running
`ReportExportScaleTest` through the existing WordPress integration runner.
For a complete native integration run with those measurements:

```sh
AGGR_REPORT_EXPORT_BENCHMARK=1 pnpm test:php:native:integration
```

These limits bound the returned PHP data and serialized document. SQL still
aggregates the indexed tenant/date range; work depends on that range's counter
cardinality. They do not impose a server query deadline or rate-limit repeated
authorized downloads. Production query timeouts, worker capacity, and abuse
controls still need deployment-specific validation. Publisher fill-outcome
exports use their own read path and are outside this advertiser capacity result.

### A campaign's reads are the organization's, narrowed

A campaign page reads the same two ranged totals and series with
`AND ( %d = 0 OR r.campaign_id = %d )` beside the tenant predicate. With no
campaign the condition folds to true and the plan is the organization read
above; with one it narrows rows already scoped by `org_day`. It never replaces
the organization predicate, so a campaign id alone reads nothing.

### Reused for five minutes, only where a cache persists

`totals_for_org()` and `series_for_org()` go through `remember()`: keyed by the
read, the organization, the campaign and both days of the range, and kept for
five minutes in the `aggr_reports` group — but only when
`wp_using_ext_object_cache()` is true. Without a persistent cache WordPress's
cache lasts one request, in which each read already happens once, so caching
would only add work. Five minutes is WordPress VIP's floor for object cache
lifetimes; the card already says recent days are still coming in, so a figure a
few minutes old is one the reader was told to expect. The CSV export is never
cached: a download is the figure somebody files.

## The campaigns list is a handful of queries, not one per row

Each list row reads a campaign's post and a dozen meta keys, then its package
and placements by id. Built one row at a time that was about two and a half
queries a row. `Campaign_List_View_Data` loads a page's posts and meta together
(`_prime_post_caches()`), then its packages and placements together, before
building any row; dashboard tiles count with a one-row `found_posts` query; and
the dashboard asks for the five rows it shows rather than a page of twenty.

Measured on the Studio site, advertiser with 46 campaigns, cold cache:

| read | before | after |
|---|---:|---:|
| campaigns list, page of 20 | 49 queries | 13 |
| needs-your-attention card | 46 queries | 10 |
| whole dashboard | 76 queries | 40 |

## Frequency capping needs the object cache more than the rest

`Transient_Frequency_Store` uses `wp_cache_incr()` when a persistent object
cache is installed, which is atomic and costs no database write. Without one it
falls back to transients, and a transient with an expiry is a row in
`wp_options` — so every capped impression becomes an options write, on top of
the event insert and the rollup upsert the beacon already does.

The rows are not autoloaded and expire on their own, so this is a write-volume
cost rather than a memory one, and the counter stays best-effort either way: the
read-then-write fallback loses races under exactly the concurrency that makes a
cap worth having.

Frequency capping is the one delivery feature whose correctness, not just its
speed, improves with Redis or Memcached. A site running caps at volume should
have one.
