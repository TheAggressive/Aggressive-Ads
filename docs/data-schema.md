# Data schema

Post types and their meta live in [domain-model.md](domain-model.md). This document covers the custom tables, the options, and how schema changes ship.

## Audit table

`{$wpdb->prefix}aggr_audit_log`

On a network that is `wp_{blog_id}_aggr_audit_log`. One WordPress site is one
publisher tenant; these tables
are never network-global.

```sql
CREATE TABLE {$wpdb->prefix}aggr_audit_log (
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

**Both `created_at` and `created_at_ts`.** The `DATETIME` is for humans reading the table and for `BETWEEN` reporting; the integer is for cheap comparison and matches the UTC-integer rule. Storing both costs 8 bytes and removes every timezone question from every query.

**`actor_ip_hash`, never a raw IP.** `sha256( $ip . wp_salt( 'aggr_audit' ) )`. A raw IP is personal data carrying a retention obligation; a salted hash still answers "was this the same client?", which is the only question the log is actually asked. The salt means the hashes are not comparable across installs, which is fine and slightly desirable.

**`request_id`.** One `wp_generate_uuid4()` per request. A single approval writes four or five rows; the request ID is what proves they were one action rather than a coincidence.

**`outcome` carries `denied`.** Denials are the interesting records. An audit log that only records successes cannot show you an attack — it can only show you that the attack did not succeed, which you learn by its absence, which is not a thing you can query for. Every failed capability check, illegal transition, and rejected upload writes a row.

**Every index leads with its selective column and ends in `id`.** `ORDER BY id DESC LIMIT n` is then satisfied from the index alone. The audit table is append-only and grows without bound; the query pattern is always "most recent N for this object / actor / org", and these indexes make that O(N) in the rows returned rather than in the rows stored.

**`context` is `LONGTEXT`, JSON-encoded, and nullable.** It must never contain a file path, a nonce, a session token, a password, or a raw IP. When in doubt, leave it out — the log is read by more people than write it.

### Writes

All writes go through `Audit_Repository`, using `$wpdb->insert()` with an
explicit format array. Direct database access for this table is confined to
that repository.

## Organization identity and access table

`{$wpdb->prefix}aggr_org_access`

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
kind and normalized email. Organization rename reserves the destination
`active_key` before deleting the previous identity row for that organization,
so a concurrent signup cannot claim the new name and a failed rename can roll
the registry back without wiping both keys. A terminal invitation/request
transition replaces `active_key` with a random digest, permitting a later
legitimate invitation without deleting the history.

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
statements.

## Native fill events and rollups

`{$wpdb->prefix}aggr_events` is append-only. `(token_hash, event)` is unique, so a
replay of the same event is a database refusal, while one fill may still
record both an impression and a click. `ip_hash` is `HMAC(IP + daily salt)`, never a
raw address. House fills store campaign_id and creative_id as 0.

`{$wpdb->prefix}aggr_rollups` is the reporting source: unique
`(placement_id, campaign_id, day_utc)`. Advertiser tiles appear only when
the reporting module is on.
House rows
(`campaign_id = 0`) are never attributed to an organization. Org totals are
filtered in SQL against campaign `_aggr_org_id`.

Event SQL lives in `Event_Repository`; rollup SQL in `Rollup_Repository`.
The event row is the durable ledger; the synchronous rollup upsert is a
low-latency projection. An hourly restartable reconciler rebuilds closed UTC
days exactly after a ten-minute midnight grace period and stores its non-autoloaded watermark in
`aggr_rollups_reconciled_through`. Retention runs hourly in bounded 10,000-row
deletes and never purges beyond that watermark. Rollups are not purged with
events. See [delivery-performance.md](delivery-performance.md).

## Campaign line items

`{$wpdb->prefix}aggr_line_items` stores delivery strategy beneath a Campaign.
It is a dedicated table because line items are selected and updated on the
serving path; representing them as postmeta would turn bounded indexed reads
into meta joins and make optimistic updates unnecessarily fragile.

Every row carries its campaign and organization, lifecycle status, UTC
schedule, pricing and goal models, integer-cent budget, daily and lifetime
caps, priority, pacing mode, weight, JSON policy fields, revision, and UTC
timestamps. The public repository normalizes policy JSON to arrays and never
exposes the internal compatibility key.

The P1 compatibility row has `default_key = 1`. A unique
`(campaign_id, default_key)` index guarantees exactly one default even when a
background migration and a live read race. Future non-default line items use
`NULL`, which MySQL permits more than once in a unique index. Campaign/status,
organization/status and schedule indexes support the next serving phases
without putting those phases into P1.

### Who owns the name

Every projected field is campaign-owned: `sync_default_from_campaign()` copies
organization, lifecycle status, schedule and budget from the Campaign, and
nothing else may write them. The line-item REST route once accepted
`budget_cents` anyway, which made this paragraph and the code disagree — and the
Campaign won in the end regardless, because any later edit touching the schedule
or the package re-projects over it. An advertiser could set a budget, get a 200,
and lose it on their next unrelated save. The route refuses it now. `name` is the one field with two possible owners —
derived from the campaign title by default, and renameable on the line item
itself — so provenance is a stored fact rather than an inference.
`name_is_derived` starts at 1, and `Line_Item_Repository::update()` clears it the
moment a write includes `name`. The projection re-derives the name only while
the flag is set.

This exists because a campaign renamed after its first detail view kept the
placeholder title the wizard invented. The default line item is created on that
first view, while the title is still "Untitled campaign", and nothing re-derived
it afterwards — so the advertiser's Delivery strategy panel showed a name they
had already changed. The browser suite caught it.

The migration that sets `name_is_derived` on existing rows is a *second* pass,
after the one that creates the default rows: a row has to exist before its name
can be classified. That makes "the migration is finished" two facts, and each
has its own cursor and completion marker — four non-autoloaded options in the
table above. Runtime initialization resumes whichever pass is outstanding,
because the first pass clears the scheduled hook when it completes and a lost
cron event would otherwise strand the second one silently. Its symptom would be
a line item still showing the placeholder name the wizard invented, which reads
as a display bug rather than a stranded migration.

**Provenance is not inferrable from `revision`.** Any edit increments it, so a
line item whose pacing somebody adjusted would have its name frozen forever;
that heuristic was written, rejected, and is pinned against by
`test_an_edit_that_is_not_a_rename_leaves_the_name_derived`.

Database version 13 adds the column and classifies the rows already on disk,
comparing each stored name against the one the repository would generate today.
The comparison calls `default_name()` rather than approximating it in SQL,
because the two must agree exactly: a row misread as derived has a publisher's
rename overwritten on the next projection, and a row misread as overridden keeps
a stale name forever. It is bounded and cursor-driven like the pass before it,
and runs after that pass, since a row must exist before its name can be
classified.

Database version 12 starts a restartable 100-campaign cron backfill. Its
non-autoloaded cursor advances only after the line item exists, or after the
source Campaign was concurrently deleted. Reads also create the default row
idempotently, so an active campaign never waits for the backfill before it can
be viewed. Native serving continues to read the Campaign during P1; switching
the hot path happens only after the creative and decision models exist.

## Options

| Option | Type | Autoload | Purpose |
|---|---|---|---|
| `aggr_db_version` | int | yes | Schema version; drives the migration walker |
| `aggr_plugin_version` | string | yes | Mirrors the plugin header |
| `aggr_roles_version` | int | yes | Bumped when the capability matrix changes |
| `aggr_rewrite_version` | int | yes | Bumped when routes change; triggers one flush |
| `aggr_seed_version` | int | yes | Bumped once placements are fully seeded and mapped |
| `aggr_upgrade_lock` | int | **no** | Transient-ish concurrency guard; see below |
| `aggr_settings` | array | yes | The settings schema's storage |
| `aggr_delivery_rewrite_version` | int | yes | Bumped when the click-hop rule changes; triggers one flush |
| `aggr_rollups_reconciled_through` | `Y-m-d` | **no** | Last closed UTC event day exactly projected into rollups |
| `aggr_line_item_migration_cursor` | int | **no** | Last Campaign id successfully visited by the restartable P1 backfill |
| `aggr_line_item_migration_done` | bool | **no** | Completion marker for the P1 compatibility-row backfill |
| `aggr_line_item_name_cursor` | int | **no** | Last line-item id visited by the P1 name-provenance backfill |
| `aggr_line_item_name_done` | bool | **no** | Completion marker for the P1 name-provenance backfill |
| `aggr_delete_data_on_uninstall` | bool | yes | Opt-in; default off |
| `aggr_org_lookup_salt` | string | **no** | Plugin-owned salt for the organization name index |
| `aggr_creative_key` | string | **no** | Base64 key encrypting creative at rest, when `AGGR_CREATIVE_KEY` is not defined |

Version options autoload because they are read on every request. `aggr_upgrade_lock` does not, because it is written and deleted rather than read, and an autoloaded option that churns is a cache-invalidation cost for nothing. `aggr_creative_key` does not, because an autoloaded secret is one that sits in the object cache of every request on the site, including requests that will never touch a creative.

### The creative key

Creative awaiting review is encrypted at rest. The key is read from the `AGGR_CREATIVE_KEY` constant when wp-config.php defines it — 32 bytes, base64 or hex — and otherwise from `aggr_creative_key`, which is generated on first use.

Defining the constant is the stronger arrangement, and the reason is narrow: it removes the database from the set of things that carry the means to decrypt. A leaked dump, a support copy, or a SQL injection then yields ciphertext and no key. Without the constant the key travels with the database, which still defeats a server that serves the uploads directory, a filesystem backup, or a copy of `wp-content` — but not a full dump.

**It is deliberately not derived from `wp_salt()`.** Salts rotate; that is what they are for. This plugin has already paid for keying durable data with one — the organization name registry was orphaned by a rotation and had to be rekeyed in db version 10 — and a rotation that silently makes every creative awaiting review undecryptable is the same defect over bytes that cannot be rebuilt from anything else the site holds.

**Losing the key means losing the artwork.** Whatever holds it belongs in the backup: the database, or `wp-config.php`, or both. Changing it does not re-encrypt anything; existing files keep the fingerprint of the key that wrote them and report as unreadable, which is why the fingerprint is in the header at all.

Nothing calls `get_option( 'aggr_settings' )` outside `inc/Core/class-settings.php`. The shape is declared once in `Domain\Settings_Schema` and written only through `Core\Settings::save()`, which rejects the whole payload on any error. The WordPress Settings API (`register_setting` / `options.php`) is not used: that screen is gated on `manage_options`, and ours is `aggr_manage_settings`.

## Migrations

`Upgrader::maybe_upgrade()` runs on `plugins_loaded` priority 5. It reads
`aggr_db_version` and walks an ordered map. A network-active install also runs
on `wp_initialize_site` so a brand-new site is not migrated by its first public
fill request.

```php
array(
    2 => install_org_access,
    4 => install_delivery_tables,  // aggr_events + aggr_rollups
    5 => migrate_event_token_uniqueness,
    12 => install_line_items_and_start_backfill,
)
```

There is no rewrite of previous product identifiers. **Each step is idempotent and bumps `aggr_db_version` itself on success.** A fatal partway through the sequence therefore never replays completed work — the next request resumes at the first unfinished step. A single bump after the whole loop would mean any failure re-runs everything from the beginning, which for an `ALTER TABLE` is how you turn one bad deploy into a corrupted schema.

Concurrency is guarded with `add_option( 'aggr_upgrade_lock', time(), '', false )`. `add_option` returns `false` when the row already exists, which is the closest thing to an atomic test-and-set WordPress offers without a direct query. Two simultaneous requests after a deploy do not both migrate. The lock is released in a `finally`, and a stale lock older than five minutes is force-cleared, because a fatal inside a migration would otherwise wedge the site permanently.

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
the audit, organization-access, line-item, events, and rollups tables, removes both roles and all granted
capabilities, and deletes every `aggr_*` option. Dropping access rows removes
outstanding bearer invitations and pending personal data. If business content
is preserved and the plugin is later reinstalled, canonical identities are
rebuilt idempotently from the retained organizations.

**It preserves campaign, creative, and organization content** unless `aggr_delete_data_on_uninstall` is explicitly set. Deleting a plugin should not silently destroy the record of what a business ran and billed for. Someone who genuinely wants that has to ask for it.
