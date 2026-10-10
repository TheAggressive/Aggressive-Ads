<?php
/**
 * Conversion-ledger database schema.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

/**
 * DDL, column lists and index names for the three conversion tables.
 *
 * Split out of {@see Schema}, which had reached the file-length gate: the
 * conversion family is the one group of tables that shares nothing but a
 * charset with the rest, and each of its three tables carries a long record of
 * why it is shaped as it is. {@see Schema} still owns every table *name*, so
 * there is one registry of what exists; this class owns how three of them are
 * declared. Like {@see Schema} it returns SQL and executes nothing.
 */
final class Schema_Conversions {

	/**
	 * Attributed conversions.
	 *
	 * **Why this is not a row in `aggr_events`.** That table is unique on
	 * `(token_hash, event)`, which is what makes a replay a database refusal
	 * for every other event type and is exactly wrong here: it would permit one
	 * conversion per fill for all time, so a signup and a purchase from the
	 * same click would see the second silently refused as a duplicate. The
	 * obvious escape — a per-definition event type — is worse, because `event`
	 * is `varchar(16)` and `conversion_purchase` is nineteen characters, so it
	 * would truncate on write and never match on read.
	 *
	 * Uniqueness is therefore `(definition_id, idempotency_key)`: the same
	 * atomic duplicate refusal, on the key that actually identifies an outcome.
	 *
	 * `occurred_at_ts` is separate from `created_at_ts` on purpose. A
	 * server-to-server report arrives long after the outcome it describes, and
	 * attributing it to receipt time would put a Monday purchase in Thursday's
	 * report. Receipt is what we observed; occurrence is what the reporter
	 * claims, and the two are not the same fact.
	 *
	 * `value_micros` is an integer for the ordinary reason — money in a float
	 * loses cents — and micros rather than cents because a per-click value is
	 * routinely smaller than one cent.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	public static function conversions_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	occurred_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	definition_id bigint(20) unsigned NOT NULL DEFAULT 0,
	idempotency_key varchar(64) NOT NULL DEFAULT '',
	placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
	campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
	creative_id bigint(20) unsigned NOT NULL DEFAULT 0,
	line_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
	token_hash char(64) NOT NULL DEFAULT '',
	attributed_event varchar(16) NOT NULL DEFAULT '',
	value_micros bigint(20) unsigned NOT NULL DEFAULT 0,
	currency char(3) NOT NULL DEFAULT '',
	source varchar(16) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY definition_key (definition_id,idempotency_key),
	KEY created (created_at_ts,id),
	KEY campaign_day (campaign_id,occurred_at_ts,id),
	KEY token_definition (token_hash,definition_id)
) {$charset_collate};";
	}



	/**
	 * Conversions table columns.
	 *
	 * @return array<int, string>
	 */
	public static function conversions_columns(): array {
		return array(
			'id',
			'created_at_ts',
			'occurred_at_ts',
			'definition_id',
			'idempotency_key',
			'placement_id',
			'campaign_id',
			'creative_id',
			'line_item_id',
			'token_hash',
			'attributed_event',
			'value_micros',
			'currency',
			'source',
		);
	}

	/**
	 * Conversions table indexes.
	 *
	 * @return array<int, string>
	 */
	public static function conversions_index_names(): array {
		return array( 'PRIMARY', 'definition_key', 'created', 'campaign_day', 'token_definition' );
	}

	/**
	 * Conversion definitions.
	 *
	 * The trusted half of conversion tracking. Value, currency and the
	 * attribution window are read from here and never from the request, which is
	 * what stops an anonymous browser declaring what an outcome was worth.
	 *
	 * `public_key` is a random 128-bit hex identifier rather than the row id,
	 * because the reporting endpoint is public and a sequential id invites
	 * walking the table to discover which definitions exist. It is unique so a
	 * lookup is one indexed read, and unguessable so "no such definition" and
	 * "not your definition" can return the same thing without either being a
	 * lie.
	 *
	 * `status` is `varchar(16)` and its longest value is `archived`, at eight.
	 * Definitions are archived rather than deleted: `aggr_conversions.definition_id`
	 * points here, and deleting a row would strand every conversion it ever
	 * recorded.
	 *
	 * `revision` is the optimistic-concurrency counter, the same mechanism line
	 * items use — two staff editing one definition must not silently overwrite
	 * each other's window.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	public static function conversion_definitions_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	org_id bigint(20) unsigned NOT NULL DEFAULT 0,
	public_key char(32) NOT NULL DEFAULT '',
	name varchar(191) NOT NULL DEFAULT '',
	window_seconds int(10) unsigned NOT NULL DEFAULT 0,
	default_value_micros bigint(20) unsigned NOT NULL DEFAULT 0,
	currency char(3) NOT NULL DEFAULT '',
	allow_s2s tinyint(1) NOT NULL DEFAULT 0,
	status varchar(16) NOT NULL DEFAULT 'active',
	revision int(10) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	UNIQUE KEY public_key (public_key),
	KEY org_status (org_id,status)
) {$charset_collate};";
	}

	/**
	 * Conversion definition columns.
	 *
	 * @return array<int, string>
	 */
	public static function conversion_definitions_columns(): array {
		return array(
			'id',
			'created_at_ts',
			'updated_at_ts',
			'org_id',
			'public_key',
			'name',
			'window_seconds',
			'default_value_micros',
			'currency',
			'allow_s2s',
			'status',
			'revision',
		);
	}

	/**
	 * Conversion definition indexes.
	 *
	 * @return array<int, string>
	 */
	public static function conversion_definitions_index_names(): array {
		return array( 'PRIMARY', 'public_key', 'org_status' );
	}
	/**
	 * Bearer credentials for server-to-server conversion reporting.
	 *
	 * **`token_hash` is a verifier, not an index over something already stored.**
	 * The plaintext exists exactly once, in the response that issues it, and is
	 * never written anywhere. So the digest is salted with `wp_salt( 'auth' )`
	 * rather than the durable lookup salt `aggr_org_access.active_key` moved to
	 * in version 10 — and the difference is deliberate. That column is an index
	 * over `canonical_name`, which sits in the clear beside it, and a salt
	 * rotation silently breaking every lookup was a defect. This one *should*
	 * stop working when the salt rotates: rotating auth salts is how an operator
	 * invalidates outstanding secrets, and a bearer credential that survived it
	 * would be a credential nobody can revoke by rotation.
	 *
	 * `revoked_at_ts` rather than a status column, for two reasons. It answers
	 * "is it revoked" and "when" with one value, and it avoids inventing another
	 * short `varchar` status — `wp_posts.post_status` is `varchar(20)` and this
	 * plugin has already been bitten by a slug that truncated on write and never
	 * matched on read.
	 *
	 * A credential is never deleted. `aggr_conversions.source` records that a
	 * row arrived server-side, and an operator asking which credential reported
	 * it needs the row to still be there.
	 *
	 * `label` is what staff see in a list of otherwise identical secrets. It is
	 * the only human-readable thing here, because the credential itself must not
	 * be readable after issue.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	public static function conversion_credentials_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	last_used_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	revoked_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	org_id bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	token_hash char(64) NOT NULL DEFAULT '',
	label varchar(191) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY org_revoked (org_id,revoked_at_ts)
) {$charset_collate};";
	}

	/**
	 * Conversion credential columns.
	 *
	 * @return array<int, string>
	 */
	public static function conversion_credentials_columns(): array {
		return array(
			'id',
			'created_at_ts',
			'last_used_at_ts',
			'revoked_at_ts',
			'org_id',
			'created_by',
			'token_hash',
			'label',
		);
	}

	/**
	 * Conversion credential indexes.
	 *
	 * @return array<int, string>
	 */
	public static function conversion_credentials_index_names(): array {
		return array( 'PRIMARY', 'token_hash', 'org_revoked' );
	}
}
