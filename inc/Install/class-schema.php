<?php
/**
 * Database schema definitions.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Install;

/**
 * Produces the plugin's DDL as a string, and executes nothing.
 *
 * Returning SQL rather than running it is what lets the installer tests assert
 * the declared shape without a migration, and compare it against SHOW INDEX
 * afterwards. See docs/data-schema.md and
 * docs/adr/0003-audit-log-in-custom-table.md.
 */
final class Schema {

	/**
	 * The schema version, bumped whenever a migration is added.
	 *
	 * Drives the migration walker in Upgrader.
	 */
	public const DB_VERSION = 1;

	/**
	 * The audit table's name, without the site's table prefix.
	 */
	public const AUDIT_TABLE = 'laao_ads_audit_log';

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
	KEY object (object_type,object_id,id),
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
}
