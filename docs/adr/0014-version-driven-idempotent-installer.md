# ADR-0014 — Version-driven idempotent installer, not activation-hook-only

**Status:** Accepted — 2026-08-08

## Context

The plugin has install-time work: create the audit table, register roles and capabilities, seed placements, flush rewrite rules.

`register_activation_hook()` is the documented place for it, and it **does not run** in several ordinary situations: a Git or rsync deploy that replaces files while the plugin stays active, a WP-CLI or dashboard update in place, restoring a database from a site whose files are newer, or a multisite network activation reaching a site that was never individually activated.

Every one of those produces a site running new code against old schema. The failure is silent until something reads a column that does not exist.

## Decision

Activation is a **hint**, not the mechanism. `Upgrader::maybe_upgrade()` runs on `plugins_loaded` priority 5, on every request, and does nothing at all when the stored versions match the code's.

Five version options drive it, each guarding one concern:

| Option | Guards |
|---|---|
| `laao_ads_db_version` | Schema, via the migration walker |
| `laao_ads_plugin_version` | Mirrors the plugin header |
| `laao_ads_roles_version` | Bumped when the capability matrix changes |
| `laao_ads_rewrite_version` | Bumped when routes change; triggers one flush |
| `laao_ads_seed_version` | Bumped once placements are seeded and mapped |

Migrations are an ordered map, and **each step is idempotent and bumps `laao_ads_db_version` itself on success**:

```php
private const MIGRATIONS = array( 2 => 'to_2', 3 => 'to_3' );
```

A fatal partway through the sequence therefore never replays completed work — the next request resumes at the first unfinished step. A single bump after the whole loop means any failure re-runs everything from the beginning, which for an `ALTER TABLE` turns one bad deploy into a corrupted schema.

Concurrency uses `add_option( 'laao_ads_upgrade_lock', time(), '', false )`, which returns `false` when the row exists — the closest thing to an atomic test-and-set WordPress offers without a direct query. Released in a `finally`; a lock older than five minutes is force-cleared, because a fatal inside a migration would otherwise wedge the site permanently.

Every migration step writes an audit row with `actor_user_id = 0`.

## Consequences

- A file-only deploy self-heals on the first request. This is the entire point.
- The check costs one autoloaded option read and an integer comparison on requests where nothing has changed.
- Bumping a version constant is the deployment procedure for schema, roles, routes, and seeds. Forgetting to bump is the one remaining failure mode, and it is a visible one-line diff in review.
- `dbDelta` has specific traps that make idempotence conditional on formatting: **two spaces** after `PRIMARY KEY`; `KEY` not `INDEX`; every key named; field types matching `SHOW CREATE TABLE` output exactly. And **`dbDelta` adds but never drops** — removing a column, index, or table requires an explicit `ALTER` in a numbered step. That last one surfaces as "the old column is still there in production six months later." All documented in [data-schema.md](../data-schema.md).
- `Schema::table_ddl()` returns DDL as a string rather than executing it, so `tests/php/Integration/InstallerTest.php` can assert the shape without running a migration and compare declared indexes against `SHOW INDEX` after install.
- The `upgrade` test suite replays 0 → current in order, asserts idempotence, and asserts that a failure stops at the last successful step.
- `uninstall.php` runs only on real uninstall. It drops the audit table, removes both roles and every granted capability, and deletes every `laao_ads_*` option — but **preserves campaign, creative, and organization content** unless `laao_ads_delete_data_on_uninstall` is explicitly set. Deleting a plugin should not silently destroy the record of what a business ran and billed for.

## Alternatives rejected

**Activation hook only.** Misses every scenario above, and the resulting schema mismatch is silent until a query fails.

**One bump after the whole migration loop.** Any failure re-runs completed `ALTER`s.

**A transient as the concurrency lock.** Transients may be backed by a shared object cache with no atomicity guarantee, and can be evicted mid-migration. `add_option` at least fails predictably.

**No lock at all.** Two requests arriving simultaneously after a deploy both migrate. With `dbDelta` this is usually survivable and with a data migration it is not, and "usually" is not a property to build on.
