# Delivery load and soak testing

The Phase 11 HTTP capacity gate exercises the complete native-delivery request
path, not a PHP method in isolation. It sends traffic through the web server,
PHP-FPM, WordPress REST routing, the persistent object cache and MySQL. Every
successful impression is then reconciled against both the durable event ledger
and the reporting rollup.

This is a launch qualification for a named infrastructure profile, not a
universal throughput promise. Repeat it whenever the web tier, PHP worker pool,
object cache, database topology or expected peak traffic changes materially.

## Reference acceptance profile

The checked-in defaults represent **100 completed ad views per second**. Each
view is one fill request followed by one unique impression beacon, so this is at
least 200 application requests per second before unrelated site traffic.

| Measure | Required result |
|---|---:|
| Eligible catalogue | exactly 1,000 live ads on one placement |
| Concurrent connections | 64 |
| Warm fill throughput | at least 250 requests/second |
| Completed fill + impression views | at least 100/second |
| p95 response latency | at most 250 ms |
| p99 response latency | at most 750 ms |
| Unexpected HTTP responses | 0 |
| Operational socket errors | 0 |
| Confirmed beacon durability | exact match to ledger delta |
| Reporting projection | exact match to ledger delta |
| Duplicate token/event rows | 0 |
| New InnoDB deadlocks | 0 |

The default run warms for 20 seconds, measures fill-only capacity for 60
seconds, then holds the mixed fill/impression workload for 15 minutes. Each of
the 64 independent keep-alive clients finishes its current request before
shutdown, so the evaluator requires exact equality among acknowledged beacons,
durable event rows and reporting-rollup increments.

## Environment contract

Run against an isolated staging clone with the same web-server shape, PHP
worker model, OPcache settings, object-cache backend and database topology as
production. The pre/post snapshot fails unless:

- WordPress reports an external object cache;
- an atomic add/increment/delete round trip succeeds;
- all 1,000 candidates are independently visible from the delivery repository;
- event reconciliation and retention are scheduled; and
- the event and rollup tables are available.

The seed script always refuses an environment marked `production`. It also
refuses a non-local host unless `AGGR_LOAD_ALLOW_REMOTE_STAGING=1` is present.
The HTTP runner independently refuses a remote target unless
`AGGR_LOAD_REMOTE_CONFIRM=1` is present.

Beacon limits are intentionally per connecting address. A production-equivalent
test therefore needs distributed injectors or a staging-only trusted proxy that
maps the harness's `X-Aggr-Load-IP` header to `REMOTE_ADDR`. Never add that trust
to a public production listener: the application correctly ignores forwarded
headers because an ordinary client can forge them.

## Reproduce the qualification

Seed only the isolated staging site:

```bash
AGGR_LOAD_ALLOW_REMOTE_STAGING=1 wp eval-file bin/load/seed.php
```

Capture evidence before traffic, run the gate, and capture it again:

```bash
wp eval-file bin/load/snapshot.php > before.json

AGGR_LOAD_BASE_URL=https://ads-staging.example.test \
AGGR_LOAD_REMOTE_CONFIRM=1 \
pnpm test:load

wp eval-file bin/load/snapshot.php > after.json
node bin/load/evaluate.mjs before.json after.json .cache/load/result.json
```

`AGGR_LOAD_DURATION`, `AGGR_LOAD_CONNECTIONS`, and every threshold named in
`bin/load/run.mjs` are configurable. Raising a target is valid; lowering one
creates a different capacity profile and must be recorded as such rather than
described as this reference qualification.

After a passing run, also inspect web, PHP, object-cache and database logs for
worker crashes, restarts outside configured recycling, OOM events, evictions,
connection exhaustion, slow queries and replication lag. The automated result
proves request behavior and write accounting; it cannot see an infrastructure
signal the environment did not expose.

## Recorded reference qualification

The reference profile passed on 23 August 2026. This was an isolated,
single-host staging stack over loopback, not a claim about arbitrary hosting:

- Intel Core i9-13900K, 16 physical cores/32 threads and 31 GiB RAM;
- nginx 1.24.0 with eight workers;
- PHP-FPM 8.5.6 with OPcache enabled, a dynamic 32-child pool and
  `pm.max_requests=10000`;
- WordPress 7.1 and exactly 1,000 independently eligible ads;
- Redis 7.0.15 through a persistent Predis object cache with verified atomic
  counters; and
- MySQL 8.0.46 on the same host.

| Measure | Recorded result |
|---|---:|
| Fill-only throughput | 1,228.93 requests/second |
| Mixed throughput | 926.44 requests/second |
| Completed views | 463.22/second; 416,936 total |
| Mixed latency | p50 66.54 ms; p95 94.31 ms; p99 132.55 ms |
| Slowest completed mixed request | 691.55 ms |
| HTTP/socket/timeout/parser errors | 0 |
| Ledger delta | 416,936 |
| Reporting-rollup delta | 416,936 |
| Duplicate event pairs / new deadlocks | 0 / 0 |

The run crossed repeated cache-expiry and PHP worker-recycling boundaries.
Web-server, PHP-FPM and Redis logs contained no upstream errors, abnormal worker
exits or cache errors. The FPM pool reached its configured ceiling under the
closed-loop capacity load, but the completed-view rate retained more than four
times the 100-view/second acceptance target. Re-run this gate on the actual
production topology before relying on that deployment's capacity.

## Harness regression coverage

`bin/load/evaluate.test.mjs` proves the evidence gate fails closed on ledger or
rollup drift, a missing persistent/atomic cache, a new InnoDB deadlock, or an
unconfirmed remote target. `bin/load/mixed-view.test.mjs` covers duration and
percentile rules, response parsing, and exact fill/beacon accounting through a
real loopback HTTP server. The load fixture and snapshot PHP are included in
PHPCS; the Node tools are included in formatting and the normal tool-test lane.
