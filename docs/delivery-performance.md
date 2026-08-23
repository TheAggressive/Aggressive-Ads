# Delivery performance and operations

Native delivery is designed around a large-placement target of **1,000 live
campaigns with one current creative each**. Catalogue size is not traffic
capacity: request rate, cache health, database latency, and event retention are
separate dimensions and must be measured on production-equivalent hardware.

## Measured query budget

`DeliveryScaleTest` builds 1,000 real campaign posts, creative posts, and meta
relationships in MySQL. It asserts all 1,000 remain eligible and enforces these
budgets:

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

The source of truth remains campaign and creative posts. A cache miss performs
two index-led reads: live campaign ids for the placement, then active creative
id/campaign pairs. Their integer sets are intersected in PHP. The placement
cache stores only the resulting id vector; each selected creative has its own
small token-free payload key. A fresh signed token is always minted per fill.

This avoids both the former N+1 query shape and transferring/deserializing all
creative URLs and alt text on every request. A short object-cache mutex limits
concurrent rebuilds. Waiters poll for at most 200ms and then render no paid ad
instead of independently rebuilding and stampeding MySQL. A token presented to
the impression or click route is validated by one exact
creative-id/campaign-id/placement-id query against the current live status, so
pause and completion do not depend on cached identity.

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
