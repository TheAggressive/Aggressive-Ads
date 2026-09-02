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

`ReportReadScaleTest` holds the line, and it EXPLAINs `$wpdb->last_query` after
calling the repository rather than a copy of the SQL. A guard that explains its
own string keeps passing after the code it watches has changed — the failure
P13 hit twice. Restoring the missing predicate is what the test was proven
against: with the date filter made non-sargable the values stay correct and the
plan still names `org_day`, and the assertion fails at 12,775 of 18,250.

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
