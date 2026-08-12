# Data schema

Post types and their meta live in [domain-model.md](domain-model.md). This document covers the custom tables, the options, and how schema changes ship.

## Audit table

`{$wpdb->prefix}laao_ads_audit_log`

```sql
CREATE TABLE {$wpdb->prefix}laao_ads_audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at    DATETIME        NOT NULL,
  created_at_ts BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  actor_role    VARCHAR(60)     NOT NULL DEFAULT '',
  actor_ip_hash CHAR(64)        NOT NULL DEFAULT '',
  request_id    CHAR(36)        NOT NULL DEFAULT '',
  event         VARCHAR(64)     NOT NULL,
  object_type   VARCHAR(32)     NOT NULL DEFAULT '',
  object_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  org_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  from_state    VARCHAR(32)     NOT NULL DEFAULT '',
  to_state      VARCHAR(32)     NOT NULL DEFAULT '',
  outcome       VARCHAR(16)     NOT NULL DEFAULT 'ok',
  message       VARCHAR(255)    NOT NULL DEFAULT '',
  context       LONGTEXT        NULL,
  PRIMARY KEY  (id),
  KEY object (object_type, object_id, id),
  KEY actor (actor_user_id, id),
  KEY org (org_id, id),
  KEY event (event, id),
  KEY created (created_at_ts)
) {$wpdb->get_charset_collate()};
```

### Why each unusual choice

**Both `created_at` and `created_at_ts`.** The `DATETIME` is for humans reading the table and for `BETWEEN` reporting; the integer is for cheap comparison and matches the UTC-integer rule in [ADR-0016](adr/0016-utc-unix-integer-times.md). Storing both costs 8 bytes and removes every timezone question from every query.

**`actor_ip_hash`, never a raw IP.** `sha256( $ip . wp_salt( 'laao_ads_audit' ) )`. A raw IP is personal data carrying a retention obligation; a salted hash still answers "was this the same client?", which is the only question the log is actually asked. The salt means the hashes are not comparable across installs, which is fine and slightly desirable.

**`request_id`.** One `wp_generate_uuid4()` per request. A single approval writes four or five rows; the request ID is what proves they were one action rather than a coincidence.

**`outcome` carries `denied`.** Denials are the interesting records. An audit log that only records successes cannot show you an attack — it can only show you that the attack did not succeed, which you learn by its absence, which is not a thing you can query for. Every failed capability check, illegal transition, and rejected upload writes a row.

**Every index leads with its selective column and ends in `id`.** `ORDER BY id DESC LIMIT n` is then satisfied from the index alone. The audit table is append-only and grows without bound; the query pattern is always "most recent N for this object / actor / org", and these indexes make that O(N) in the rows returned rather than in the rows stored.

**`context` is `LONGTEXT`, JSON-encoded, and nullable.** It must never contain a file path, a nonce, a session token, a password, or a raw IP. When in doubt, leave it out — the log is read by more people than write it.

### Writes

All writes go through `Audit_Repository`, using `$wpdb->insert()` with an
explicit format array. Direct database access for this table is confined to
that repository.

## Organization identity and access table

`{$wpdb->prefix}laao_ads_org_access`

This table stores three deliberately small row kinds:

| Kind | Purpose | Active state |
|---|---|---|
| `identity` | Unique canonical organization-name reservation | `active` |
| `invite` | Owner/staff-issued, email-bound bearer invitation | `pending` → `processing` → `accepted|revoked` |
| `request` | Possible duplicate-name signup awaiting approval | `pending` → `processing` → `accepted|denied` |

`token_hash` and `active_key` are 64-character salted HMAC-SHA-256 digests.
Raw bearer tokens are returned only to the mail workflow and never persisted.
`token_hash` is unique, while `active_key` atomically prevents duplicate
canonical identities and duplicate pending rows for the same organization,
kind and normalized email. A terminal transition replaces `active_key` with a
random digest, permitting a later legitimate invitation without deleting the
history.

Invitations expire after three days; duplicate-name requests expire after
seven. All timestamps are UTC Unix integers. Reads are bounded to 100 pending
rows per organization and use `(org_id,status,id)`, `(email,status,id)`,
`(request_user_id,status,id)`, and `(status,expires_at_ts)` indexes. Conditional
`pending → processing` updates claim a row before membership side effects so
two requests cannot consume it. Failure restores the claim and compensates only
the membership or role introduced by that attempt.

An expired duplicate-name request may be submitted again only by the same
subscriber email for the same canonical organization. This narrow retry keeps
expiry from permanently stranding the WordPress address while preserving the
ordinary rule that existing-email signup sends no mail.

All access-table SQL lives in `Org_Access_Repository`; every query uses
`$wpdb->prepare()` or a format array, except fixed prefix-derived schema
statements. See
[ADR-0019](adr/0019-private-organization-matching-and-approved-membership.md).

## Options

| Option | Type | Autoload | Purpose |
|---|---|---|---|
| `laao_ads_db_version` | int | yes | Schema version; drives the migration walker |
| `laao_ads_plugin_version` | string | yes | Mirrors the plugin header |
| `laao_ads_roles_version` | int | yes | Bumped when the capability matrix changes |
| `laao_ads_rewrite_version` | int | yes | Bumped when routes change; triggers one flush |
| `laao_ads_seed_version` | int | yes | Bumped once placements are fully seeded and mapped |
| `laao_ads_upgrade_lock` | int | **no** | Transient-ish concurrency guard; see below |
| `laao_ads_settings` | array | yes | The settings schema's storage |
| `laao_ads_delete_data_on_uninstall` | bool | yes | Opt-in; default off |

Version options autoload because they are read on every request. `laao_ads_upgrade_lock` does not, because it is written and deleted rather than read, and an autoloaded option that churns is a cache-invalidation cost for nothing.

Nothing calls `get_option()` outside `inc/Core/class-settings.php`. Settings are declared once in a schema consumed by *both* `register_setting()` and the read API, so a registration default and a read fallback cannot drift apart — the bug where the settings form shows one value and the site behaves as another.

## Migrations

`Upgrader::maybe_upgrade()` runs on `plugins_loaded` priority 5. It reads `laao_ads_db_version` and walks an ordered map:

```php
private const MIGRATIONS = array(
    2 => 'to_2',
    3 => 'to_3',
);
```

**Each step is idempotent and bumps `laao_ads_db_version` itself on success.** A fatal partway through the sequence therefore never replays completed work — the next request resumes at the first unfinished step. A single bump after the whole loop would mean any failure re-runs everything from the beginning, which for an `ALTER TABLE` is how you turn one bad deploy into a corrupted schema.

Concurrency is guarded with `add_option( 'laao_ads_upgrade_lock', time(), '', false )`. `add_option` returns `false` when the row already exists, which is the closest thing to an atomic test-and-set WordPress offers without a direct query. Two simultaneous requests after a deploy do not both migrate. The lock is released in a `finally`, and a stale lock older than five minutes is force-cleared, because a fatal inside a migration would otherwise wedge the site permanently.

Every migration step writes an audit row with `actor_user_id = 0`.

### `dbDelta` traps

`dbDelta()` parses SQL with regular expressions and is unforgiving in specific ways:

- **Two spaces** after `PRIMARY KEY`. One space and it will not recognize the key, and will try to add it again on every run.
- `KEY`, not `INDEX`. `INDEX` is valid MySQL and invisible to `dbDelta`.
- Every key must be **named**. Anonymous keys cannot be diffed, so they get recreated forever.
- Field types must match `SHOW CREATE TABLE` output exactly, including MySQL's own normalizations — `bigint(20) unsigned` versus `BIGINT UNSIGNED` is a difference `dbDelta` will act on repeatedly.
- **`dbDelta` adds but never drops.** It will not remove a column, an index, or a table. Dropping anything requires an explicit `ALTER` in a numbered migration step. This is the one people forget, and it surfaces as "the old column is still there in production six months later."

Schema DDL methods return strings rather than executing them, so
`tests/php/Integration/InstallerTest.php` can compare every declared column and
index against live `SHOW COLUMNS` / `SHOW INDEX` results after install.

## Uninstall

`uninstall.php` runs only on a real uninstall, never on deactivation. It drops
the audit and organization-access tables, removes both roles and all granted
capabilities, and deletes every `laao_ads_*` option. Dropping access rows removes
outstanding bearer invitations and pending personal data. If business content
is preserved and the plugin is later reinstalled, canonical identities are
rebuilt idempotently from the retained organizations.

**It preserves campaign, creative, and organization content** unless `laao_ads_delete_data_on_uninstall` is explicitly set. Deleting a plugin should not silently destroy the record of what a business ran and billed for. Someone who genuinely wants that has to ask for it.
