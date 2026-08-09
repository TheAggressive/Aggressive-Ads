# ADR-0003 — Audit log in a custom indexed table

**Status:** Accepted — 2026-08-08

## Context

[ADR-0002](0002-private-cpts-behind-repositories.md) puts every business entity in a post type. The audit log is the one place that reasoning inverts, and it is worth recording why rather than having the inconsistency read as an oversight.

The log is append-only, grows without bound, and is queried in exactly one shape: *most recent N rows for this object, actor, or organization*. A single campaign approval writes four or five rows. It also has to record denials — failed capability checks, illegal transitions, rejected uploads — which means volume scales with attack traffic, not with business activity.

## Decision

One custom table, `{$wpdb->prefix}laao_ads_audit_log`, with the DDL in [data-schema.md](../data-schema.md).

The choices that are not obvious:

**Both `created_at` DATETIME and `created_at_ts` BIGINT.** The datetime is for humans reading the table and for `BETWEEN` reporting; the integer is for cheap comparison and matches [ADR-0016](0016-utc-unix-integer-times.md). Eight extra bytes removes every timezone question from every query.

**`actor_ip_hash`, never a raw IP.** `sha256( $ip . wp_salt( 'laao_ads_audit' ) )`. A raw IP is personal data with a retention obligation; the hash still answers "same client?", which is the only question the log is ever asked.

**`request_id`** — one `wp_generate_uuid4()` per request, so the five rows an approval writes are provably one action.

**`outcome` carries `denied`.** A log of successes cannot show an attack; it can only fail to show one, and absence is not queryable.

**Every index leads with its selective column and ends in `id`,** so `ORDER BY id DESC LIMIT n` is satisfied from the index alone — cost proportional to rows returned, not rows stored.

All writes go through one method on `Audit_Repository` using `$wpdb->insert()` with an explicit format array. The `WordPress.DB.DirectDatabaseQuery` phpcs suppression appears on that one method with a reasoned comment and nowhere else; a second occurrence is a review failure.

## Consequences

- The plugin owns a schema, which means it owns migrations. The version-driven walker in [ADR-0014](0014-version-driven-idempotent-installer.md) exists largely to serve this table.
- `uninstall.php` drops it. Audit history does not survive deliberate uninstall, and that is the correct default for a log containing hashed client identifiers.
- Audit reads are filtered with `WHERE org_id = ?` in SQL, never by filtering a fetched array in PHP — the array approach works until someone adds pagination, at which point page 2 is inexplicably empty.
- `context` is JSON in `LONGTEXT` and must never contain a file path, nonce, token, password, or raw IP. When in doubt, leave it out: the log is read by more people than write it.

## Alternatives rejected

**A CPT per audit event.** Every entry becomes a `wp_posts` row plus several `wp_postmeta` rows, sharing indexes with real site content and bloating `wp_posts` at attack volume. "Last 20 events for campaign 412" becomes a multi-join meta query against the largest table on the site.

**A log file.** Not queryable from the admin, lost on ephemeral filesystems, and invisible to the review screen that needs to render a timeline.

**Monolog or another logging package.** A runtime Composer dependency, forbidden by [ADR-0011](0011-no-composer-runtime-dependencies.md), to produce output nothing in WordPress can read.

**Only logging successes.** Denials are the records with security value. This was never seriously on the table; it is recorded because it is the default a logging library would have given us.
