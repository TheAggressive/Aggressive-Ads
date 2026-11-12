<?php
/**
 * Durable subscription and current delivery state for opt-in report mail.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

/** One subscription per organization and account, with an indexed due cursor. */
final class Schema_Report_Subscriptions {

	/**
	 * Schema for the current site's subscriptions.
	 *
	 * @param string $table   Fully prefixed table name.
	 * @param string $collate WordPress charset/collation clause.
	 */
	public static function ddl( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	org_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	cadence varchar(10) NOT NULL,
	timezone varchar(64) NOT NULL,
	enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
	due_at bigint(20) unsigned NOT NULL DEFAULT 0,
	state varchar(20) NOT NULL DEFAULT 'idle',
	period_start varchar(10) NOT NULL DEFAULT '',
	period_end varchar(10) NOT NULL DEFAULT '',
	attempts smallint(5) unsigned NOT NULL DEFAULT 0,
	last_queued_at bigint(20) unsigned NOT NULL DEFAULT 0,
	last_error varchar(64) NOT NULL DEFAULT '',
	revision bigint(20) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	UNIQUE KEY org_user (org_id,user_id),
	KEY due (enabled,due_at,id),
	KEY recipient (user_id,id)
) {$collate};";
	}

	/**
	 * Physical column contract.
	 *
	 * @return list<string>
	 */
	public static function columns(): array {
		return array( 'id', 'org_id', 'user_id', 'cadence', 'timezone', 'enabled', 'due_at', 'state', 'period_start', 'period_end', 'attempts', 'last_queued_at', 'last_error', 'revision' );
	}
}
