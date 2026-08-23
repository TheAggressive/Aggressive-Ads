<?php
/**
 * Database schema definitions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

/**
 * Produces the plugin's DDL as a string, and executes nothing.
 *
 * Returning SQL rather than running it is what lets the installer tests assert
 * the declared shape without a migration, and compare it against SHOW INDEX
 * afterwards. See docs/data-schema.md and
 * docs/data-schema.md.
 */
final class Schema {

	/**
	 * The schema version, bumped whenever a migration is added.
	 *
	 * Drives the migration walker in Upgrader.
	 */
	public const DB_VERSION = 12;

	/**
	 * The audit table's name, without the site's table prefix.
	 */
	public const AUDIT_TABLE = 'aggr_audit_log';

	/** Organization identity, invitation, and access-request registry. */
	public const ORG_ACCESS_TABLE = 'aggr_org_access';

	/** Append-only fill impressions and clicks. */
	public const EVENTS_TABLE = 'aggr_events';

	/** Campaign/placement/day counters. Reporting reads this, never the event log. */
	public const ROLLUPS_TABLE = 'aggr_rollups';

	/** Delivery strategies owned by campaigns. */
	public const LINE_ITEMS_TABLE = 'aggr_line_items';

	/**
	 * Campaign line items.
	 *
	 * `default_key` is 1 only for the compatibility line item and NULL for all
	 * future siblings. MySQL permits multiple NULL values in a unique index, so
	 * this prevents two default rows without preventing P2 from adding multiple
	 * ordinary line items beneath the same campaign.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Database charset and collation.
	 */
	public static function line_items_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
	organization_id bigint(20) unsigned NOT NULL DEFAULT 0,
	name varchar(191) NOT NULL DEFAULT '',
	status varchar(16) NOT NULL DEFAULT 'draft',
	start_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	end_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	pricing_model varchar(20) NOT NULL DEFAULT 'flat',
	goal_type varchar(20) NOT NULL DEFAULT 'none',
	goal_amount bigint(20) unsigned NOT NULL DEFAULT 0,
	budget_cents bigint(20) unsigned NOT NULL DEFAULT 0,
	daily_cap bigint(20) unsigned NOT NULL DEFAULT 0,
	lifetime_cap bigint(20) unsigned NOT NULL DEFAULT 0,
	priority smallint(5) unsigned NOT NULL DEFAULT 100,
	pacing_mode varchar(16) NOT NULL DEFAULT 'even',
	weight int(10) unsigned NOT NULL DEFAULT 100,
	targeting_rules longtext NULL,
	frequency_policy longtext NULL,
	delivery_settings longtext NULL,
	revision bigint(20) unsigned NOT NULL DEFAULT 1,
	default_key tinyint(1) unsigned NULL DEFAULT NULL,
	created_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY campaign_default (campaign_id,default_key),
	KEY campaign_status (campaign_id,status,id),
	KEY organization_status (organization_id,status,id),
	KEY delivery_window (status,start_at_ts,end_at_ts,id)
) {$charset_collate};";
	}

	/** @return array<int, string> */
	public static function line_items_columns(): array {
		return array(
			'id', 'campaign_id', 'organization_id', 'name', 'status',
			'start_at_ts', 'end_at_ts', 'pricing_model', 'goal_type',
			'goal_amount', 'budget_cents', 'daily_cap', 'lifetime_cap',
			'priority', 'pacing_mode', 'weight', 'targeting_rules',
			'frequency_policy', 'delivery_settings', 'revision',
			'default_key', 'created_at_ts', 'updated_at_ts',
		);
	}

	/** @return array<int, string> */
	public static function line_items_index_names(): array {
		return array( 'PRIMARY', 'campaign_default', 'campaign_status', 'organization_status', 'delivery_window' );
	}

	/**
	 * Organization identity and access workflow table.
	 *
	 * `active_key` is unique. Pending invitations/requests derive it from the
	 * organization, normalized email, and kind, making duplicate creation an
	 * atomic database refusal. Resolved rows replace it with a random digest so
	 * a later legitimate invitation may use the same address.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	public static function org_access_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	org_id bigint(20) unsigned NOT NULL DEFAULT 0,
	kind varchar(16) NOT NULL DEFAULT '',
	status varchar(16) NOT NULL DEFAULT 'pending',
	email varchar(100) NOT NULL DEFAULT '',
	canonical_name varchar(191) NOT NULL DEFAULT '',
	request_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	resolved_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	expires_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	resolved_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	token_hash char(64) NOT NULL DEFAULT '',
	active_key char(64) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	UNIQUE KEY active_key (active_key),
	KEY org_status (org_id,status,id),
	KEY email_status (email,status,id),
	KEY user_status (request_user_id,status,id),
	KEY expiry (status,expires_at_ts)
) {$charset_collate};";
	}

	/**
	 * Organization access table columns.
	 *
	 * @return array<int, string>
	 */
	public static function org_access_columns(): array {
		return array(
			'id',
			'org_id',
			'kind',
			'status',
			'email',
			'canonical_name',
			'request_user_id',
			'created_by_user_id',
			'resolved_by_user_id',
			'created_at_ts',
			'expires_at_ts',
			'resolved_at_ts',
			'token_hash',
			'active_key',
		);
	}

	/**
	 * Organization access table indexes.
	 *
	 * @return array<int, string>
	 */
	public static function org_access_index_names(): array {
		return array( 'PRIMARY', 'token_hash', 'active_key', 'org_status', 'email_status', 'user_status', 'expiry' );
	}

	/**
	 * The audit log's CREATE TABLE statement.
	 *
	 * The formatting below is not style. dbDelta() parses SQL with regular
	 * expressions and is unforgiving in specific ways, all of which this string
	 * satisfies deliberately:
	 *
	 * - **Two spaces** after `PRIMARY KEY`. One space and dbDelta does not
	 *   recognize the key, and tries to add it again on every single run.
	 * - `KEY`, never `INDEX`. `INDEX` is valid MySQL and invisible to dbDelta.
	 * - Every key is **named**. An anonymous key cannot be diffed, so it gets
	 *   recreated forever.
	 * - One field per line, lowercase types matching what SHOW CREATE TABLE
	 *   reports back, so the diff is stable across runs.
	 *
	 * And the one people forget: **dbDelta adds but never drops.** Removing a
	 * column, key or table requires an explicit ALTER in a numbered migration
	 * step. Editing this string alone will not remove anything.
	 *
	 * Every index leads with its selective column and ends in `id`, so
	 * `ORDER BY id DESC LIMIT n` is satisfied from the index alone. The table
	 * is append-only and grows without bound; the query is always "most recent
	 * N for this object, actor or org", and these indexes make that cost
	 * proportional to rows returned rather than rows stored.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Result of wpdb::get_charset_collate().
	 * @return string
	 */
	public static function audit_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at datetime NOT NULL,
	created_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	actor_role varchar(60) NOT NULL DEFAULT '',
	actor_ip_hash char(64) NOT NULL DEFAULT '',
	request_id char(36) NOT NULL DEFAULT '',
	event varchar(64) NOT NULL DEFAULT '',
	object_type varchar(32) NOT NULL DEFAULT '',
	object_id bigint(20) unsigned NOT NULL DEFAULT 0,
	org_id bigint(20) unsigned NOT NULL DEFAULT 0,
	from_state varchar(32) NOT NULL DEFAULT '',
	to_state varchar(32) NOT NULL DEFAULT '',
	outcome varchar(16) NOT NULL DEFAULT 'ok',
	message varchar(255) NOT NULL DEFAULT '',
	context longtext NULL,
	PRIMARY KEY  (id),
	KEY object (object_type,object_id,org_id,id),
	KEY actor (actor_user_id,id),
	KEY org (org_id,id),
	KEY event (event,id),
	KEY created (created_at_ts)
) {$charset_collate};";
	}

	/**
	 * The index names the audit table must carry.
	 *
	 * Declared separately so an integration test can compare this list against
	 * SHOW INDEX on the real table. A dropped index does not break a query, it
	 * just makes it slow — which is invisible until the table is large, and by
	 * then nobody connects the two.
	 *
	 * @return array<int, string>
	 */
	public static function audit_index_names(): array {
		return array( 'PRIMARY', 'object', 'actor', 'org', 'event', 'created' );
	}

	/**
	 * The audit table's column names.
	 *
	 * @return array<int, string>
	 */
	public static function audit_columns(): array {
		return array(
			'id',
			'created_at',
			'created_at_ts',
			'actor_user_id',
			'actor_role',
			'actor_ip_hash',
			'request_id',
			'event',
			'object_type',
			'object_id',
			'org_id',
			'from_state',
			'to_state',
			'outcome',
			'message',
			'context',
		);
	}

	/**
	 * Impression and click events. (token_hash, event) is unique so a replay
	 * of the same event is a database refusal, while one fill may still
	 * record both an impression and a click.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	public static function events_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at_ts bigint(20) unsigned NOT NULL DEFAULT 0,
	event varchar(16) NOT NULL DEFAULT '',
	placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
	campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
	creative_id bigint(20) unsigned NOT NULL DEFAULT 0,
	token_hash char(64) NOT NULL DEFAULT '',
	ip_hash char(64) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY token_event (token_hash,event),
	KEY created (created_at_ts,id),
	KEY campaign_day (campaign_id,created_at_ts,id)
) {$charset_collate};";
	}

	/**
	 * Events table columns.
	 *
	 * @return array<int, string>
	 */
	public static function events_columns(): array {
		return array(
			'id',
			'created_at_ts',
			'event',
			'placement_id',
			'campaign_id',
			'creative_id',
			'token_hash',
			'ip_hash',
		);
	}

	/**
	 * Events table indexes.
	 *
	 * @return array<int, string>
	 */
	public static function events_index_names(): array {
		return array( 'PRIMARY', 'token_event', 'created', 'campaign_day' );
	}

	/**
	 * Per-day counters. Reporting reads this, never the event log.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	public static function rollups_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	day_utc date NOT NULL,
	placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
	campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
	impressions bigint(20) unsigned NOT NULL DEFAULT 0,
	clicks bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY slot_day (placement_id,campaign_id,day_utc),
	KEY campaign_day (campaign_id,day_utc)
) {$charset_collate};";
	}

	/**
	 * Rollups table columns.
	 *
	 * @return array<int, string>
	 */
	public static function rollups_columns(): array {
		return array(
			'id',
			'day_utc',
			'placement_id',
			'campaign_id',
			'impressions',
			'clicks',
		);
	}

	/**
	 * Rollups table indexes.
	 *
	 * @return array<int, string>
	 */
	public static function rollups_index_names(): array {
		return array( 'PRIMARY', 'slot_day', 'campaign_day' );
	}
}
