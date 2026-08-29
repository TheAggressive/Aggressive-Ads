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

`{$wpdb->prefix}aggr_rollups` is the reporting source and the pacing counter:
unique `(placement_id, campaign_id, line_item_id, day_utc)`. Advertiser tiles
appear only when the reporting module is on.

**`viewables` is schema 17, and it is nullable on purpose.** NULL means nobody
was measuring — every row written before P11 — while zero means we were and saw
none. Projecting zero onto history would make an unimplemented feature look
identical to a day on which not one ad was seen, which is the more alarming
reading and the wrong one. An impression sets the column to zero, so a delivery
is what marks a day as measured.

**`conversions` is schema 18, and it is nullable for the same reason one phase
later.** A day before conversions were measured did not convert nobody; nobody
was counting. See [Attributed conversions — P12](#attributed-conversions--p12)
for why a conversion is not an `aggr_events` row.

**`line_item_id` is schema 16, and it exists because a cap belongs to a line
item.** Counting deliveries per campaign was correct only while a campaign had
exactly one line item — true then, and not a property to build on: a second
would have spent its sibling's impressions against its own cap and stopped
delivering early with nothing reporting why. The event ledger records the
creative rather than the line item, so the live counter resolves it from the
assignment that served the fill, and the daily reconcile recovers the same
attribution with a join. That join is durable because withdrawal retires an
assignment rather than deleting it.
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
    16 => migrate_line_item_attribution,  // rollups gain line_item_id
    17 => install_delivery_tables,        // rollups gain viewables (additive)
    18 => install_conversions,            // aggr_conversions + rollups gain conversions
    19 => install_conversions,            // aggr_conversion_definitions
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


## Creative assets and assignments — P2

Two tables, added at database version 14. **Empty on arrival**: nothing reads
them yet, and the backfill that fills them ships with the code that reads them.
That ordering is deliberate — a table filled in one release and read in another
is never a site serving from a migration that has not finished.

Ownership of the fields is decided in
[platform-p2-creative-model.md](platform-p2-creative-model.md#decision-everything-reviewed-belongs-to-the-revision):
the revision owns bytes, click URL and alternative text; the assignment owns
weight, window and status; the asset owns identity.

### `aggr_creative_assets`

Tenant-owned identity, and nothing renderable. It exists so "the same artwork,
reused" has something concrete to point at, and so organization and site
ownership have one home rather than being re-derived from whichever revision
was asked about.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned | |
| `root_creative_id` | bigint unsigned | Oldest creative in the replacement chain — the stable identity every revision of one artwork shares |
| `organization_id` | bigint unsigned | Owning tenant |
| `blog_id` | bigint unsigned | Site scope, matching the fill-token convention |
| `name` | varchar(191) | Advertiser-facing label |
| `created_at_ts` / `updated_at_ts` | bigint unsigned | UTC Unix seconds |

Indexes: `root_site (root_creative_id, blog_id)` UNIQUE,
`organization_site (organization_id, blog_id, id)`.

The unique key is what makes the backfill and lazy self-healing safe to run at
the same time: two requests resolving the same artwork race to insert, one wins,
and the loser reads the winner's row instead of failing.

### `aggr_creative_assignments`

The serving-path table. Its shape is derived from the query P3 will run —
placement, status, delivery window, with a stable id last so ordering and
pagination are deterministic — rather than from what a creative happens to look
like.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned | |
| `line_item_id`, `campaign_id`, `organization_id` | bigint unsigned | Scope, all server-derived |
| `asset_id`, `revision_id`, `placement_id` | bigint unsigned | What is served, and where |
| `status` | varchar(16) | Assignment lifecycle |
| `weight` | int unsigned | Positive. P2 stores it; P3 defines how it competes |
| `start_at_ts` / `end_at_ts` | bigint unsigned | **0 means inherit the line item's window.** A non-zero value must fall *within* the parent and may only narrow it |
| `click_url`, `alt_text`, `width`, `height`, `attachment_id` | — | **Denormalized from the approved revision at approval time** |
| `revision` | bigint unsigned | Optimistic concurrency |
| `compat_key` | tinyint unsigned **NULL** | Nullable-unique compatibility marker |
| `created_at_ts` / `updated_at_ts` | bigint unsigned | UTC Unix seconds |

Indexes: `line_item_placement_compat (line_item_id, placement_id, compat_key)`
UNIQUE, `delivery (placement_id, status, start_at_ts, end_at_ts, id)`,
`line_item_status`, `campaign_status`, `organization_status`,
`revision_lookup`.

**Why the delivery columns are duplicated.** Native fill reaches the same facts
today through seven-plus postmeta joins per ad, which is exactly what the P3
read contract forbids. Copying them onto the assignment at approval collapses
that into one indexed row read.

Duplication is normally how data drifts, and here it cannot: the source is an
immutable revision. A revision's bytes, click URL and alternative text can never
change, so a copy taken at approval stays correct for as long as the assignment
points at that revision. Editing means a new revision, which means a new
approval, which writes a new copy.

**Why `compat_key` is nullable.** The same trick `campaign_default` uses on the
line-item table: NULL values do not collide in a UNIQUE index, so exactly one
row per (line item, placement) can be marked as the compatibility assignment
while every other row stays unconstrained. That is what allows many creatives
per placement — the P1 limitation P2 exists to remove — while still making
concurrent lazy creation and the background backfill idempotent by database rule
rather than by application care.


### Backfill options

| Option | Autoloaded | Purpose |
|---|---|---|
| `aggr_creative_assignment_cursor` | **no** | Last creative id visited by the P2 backfill |
| `aggr_creative_assignment_done` | **no** | Completion marker for the P2 backfill |

Both are removed by a destructive uninstall, and the backfill runs on
`aggr_migrate_creative_assignments` — a single event re-queued a minute after
each batch, which schedules nothing once finished.


## Attributed conversions — P12

`{$wpdb->prefix}aggr_conversions` is append-only, schema 18, and it is
deliberately **not** a row in `aggr_events`.

That table is unique on `(token_hash, event)`. The key is what makes a replay a
database refusal for every other event type, and it is exactly wrong for a
conversion: it would permit **one conversion per fill for all time**, so a signup
and a purchase from the same click would see the second silently refused as a
duplicate — indistinguishable, in the code and in the logs, from correct
deduplication.

The obvious escape is worse. `aggr_events.event` is `varchar(16)`, and
`conversion_purchase` is nineteen characters, so a per-definition event type
would truncate on write and never match on read: the `varchar(20)` trap recorded
below, one size smaller.

So uniqueness here is `(definition_id, idempotency_key)` — the same atomic
duplicate refusal, on the key that actually identifies an outcome. Two
definitions from one click both count; the same outcome reported twice does not.
`ConversionLedgerTest` asserts both, and the first of those is the assertion that
fails if conversions are ever moved back.

| Column | Why |
|---|---|
| `created_at_ts` | Server receipt. What we observed. |
| `occurred_at_ts` | When the reporter says it happened. A server-to-server report arrives long after the outcome, and counting by receipt would move a Monday purchase into Thursday. Reporting counts on this one. |
| `definition_id` | Which conversion definition. Half the unique key. |
| `idempotency_key` | `varchar(64)`, and validated in `Domain\Conversion_Rules` before any write — outside strict mode MySQL truncates an over-long value rather than refusing it, which would collapse two different outcomes onto one key. |
| `token_hash` | The fill this is attributed to. Attribution derives from the signed token, never from a client-supplied campaign id. |
| `attributed_event` | `click` or `viewable`, the interaction that opened the window. |
| `value_micros` | Millionths of a currency unit. An integer, because money in a float loses cents; micros rather than cents, because a per-click value is routinely smaller than one cent. |
| `currency` | ISO 4217, or empty for a valueless conversion such as a signup. |
| `source` | `browser` or `s2s`. Each is authorized differently. |

The attribution **window is measured against the server-recorded interaction**,
never against the fill token's `exp`. `Fill_Token::TTL_SECONDS` is five minutes
and bounds when reporting may *start*; a window is days and bounds how long
attribution remains true. Reading one as the other would expire every conversion.

Conversion SQL lives in `Conversion_Repository`. `Workflow\Conversion_Recorder`
is the only thing that writes it, from `POST /aggr/v1/conversions`.

The rollup's `conversions` column is projected on the write and repaired by
`Rollup_Repository::reconcile_day()`, which runs a **second statement** against
this table rather than widening the event reconcile's join. Two ledgers with
different grains in one aggregate would multiply the impression counts by the
number of conversions — the classic fan-out, and it would silently inflate every
other column.

**That repair needs no measurement-boundary option, and the asymmetry with
viewability is worth knowing.** The event reconcile writes a row for every day
that has *events*, so a pre-P11 day was swept up and its `viewables` set to a
measured zero — hence `aggr_viewability_since`. The conversion reconcile selects
from the conversion ledger, so a day with none produces no rows, fires no
`ON DUPLICATE KEY UPDATE`, and leaves an existing NULL exactly as it was. A
boundary option was written and then deleted, because sabotaging it changed no
test: a guard that cannot fail is not protecting anything.

A conversion also leaves `viewables` NULL rather than 0, which is the subtle
half. It is attributed to the day the outcome happened, routinely days after the
click, so it can create a row for a day this site served nothing — and writing 0
there would invent a day of impressions nobody saw.

### `aggr_conversion_definitions`

Schema 19, and the trusted half of conversion tracking. Value, currency and the
attribution window are read from here and never from a request, which is what
stops an anonymous browser declaring what an outcome was worth or how long a
click stays creditable.

`public_key` is a random 128-bit hex identifier, **not** the row id. The
reporting endpoint is public, and a sequential id invites walking the table to
discover which definitions exist. Unique, so a lookup is one indexed read;
unguessable, so "no such definition" and "not your definition" can return the
same answer without either being a lie. It is minted by
`Conversion_Definition_Repository::create()` and is not in any write path a
client can reach — asserted directly, because it is the one guard that actually
holds: the REST field allowlist and the domain validator's fixed return shape
both refuse it too, and deleting either leaves the behaviour correct.

The key is deliberately absent from every audit context. It is a credential, and
an audit log is read by more people and kept longer than the screen that shows
it.

`status` is `varchar(16)`; its longest value is `archived`, at eight. Definitions
are archived rather than deleted, because `aggr_conversions.definition_id` points
here and deleting a row would strand every conversion it recorded.

`revision` is optimistic concurrency, the same mechanism line items use. The
check is part of the `WHERE` clause, never a read-then-write: two staff saving
one definition would both read revision 4 and both believe they were current.

`MAX_DEFINITIONS` bounds the table at 200. Not a licence limit — it is what makes
the unpaged staff listing and the per-request public lookup safe without either
growing a pagination story.

## The P3 candidate read contract

`Creative_Assignment_Repository::candidates_for_placement( $placement_id, $now, $limit )`
is the query P3's decision engine consumes. It is documented here rather than
left to be inferred, so P3 does not grow a second definition of "deliverable".

**Inputs.** A placement id, an evaluation time in UTC seconds, and a limit
(clamped to 500). Nothing else — the caller supplies the clock so a decision can
be replayed.

**Output.** One row per candidate, carrying `id`, `line_item_id`, `campaign_id`,
`organization_id`, `asset_id`, `revision_id`, `placement_id`, `status`,
`weight`, `start_at_ts`, `end_at_ts`, `click_url`, `alt_text`, `width`,
`height`, `attachment_id`. Everything a decision needs is on the row, because
approval denormalized it from an immutable revision — P3 never fetches a
creative to learn its size or destination.

**Visibility.** Only `live` assignments whose window contains the given time. A
zero bound means "inherit the parent" and is open here; the end is exclusive, so
an assignment ending exactly now has stopped. Every other status — `draft`,
`ready`, `paused`, `completed`, `cancelled` — is excluded.

**Ordering.** Ascending `id`, which is stable across reads. P3 pages this, and
without a stable trailing key two rows sharing a window can swap between pages
so a candidate is seen twice or not at all.

**Cost.** One query, whatever the candidate count. `delivery
(placement_id, status, start_at_ts, end_at_ts, id)` serves the whole predicate
and the ordering; `EXPLAIN` is asserted to choose it. Measured against 1,000
rows: two queries cold — the second being the memoised `SHOW TABLES` existence
check — and one warm, with no per-candidate read.

**Wired.** Native fill reads this contract through `Workflow\Decision_Engine`
after the P2 assignment backfill completes. Token validation still re-reads the
winning creative through `Delivery_Repository::candidate()`.
