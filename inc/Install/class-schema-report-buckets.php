<?php
/**
 * Reporting-bucket database schema.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

/**
 * DDL, columns and indexes for `aggr_report_buckets`.
 *
 * Split out of {@see Schema} the same way {@see Planning_Schema} was: the
 * daily table stays the pacing and lifetime counter, and this one exists so a
 * report can be cut on a local day. The name and `DB_VERSION` stay on
 * {@see Schema}; this class owns how the reporting table is declared. Like
 * {@see Schema} it returns SQL and executes nothing.
 */
final class Schema_Report_Buckets {

	/**
	 * Fifteen-minute counters. Reporting reads this; pacing still reads the daily table.
	 *
	 * Same grain as `aggr_rollups` plus `bucket_utc` instead of `day_utc`. The
	 * daily unique key cannot grow an hour without turning every pacing read
	 * into a 24-row sum and breaking `delivered_today`. Two tables, two jobs.
	 *
	 * `bucket_utc` is a UTC datetime rounded down to a quarter hour. The
	 * reconciler writes it from `created_at_ts - (created_at_ts % 900)` so the
	 * value does not depend on the session timezone — `FROM_UNIXTIME` would.
	 *
	 * Nullable `viewables` and `conversions` mean the same thing they mean on
	 * the daily table: nobody was measuring, not that nothing happened.
	 *
	 * @param string $table_name      Fully prefixed table name.
	 * @param string $charset_collate Database charset and collation.
	 */
	public static function table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	bucket_utc datetime NOT NULL,
	placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
	campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
	line_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
	creative_id bigint(20) unsigned NOT NULL DEFAULT 0,
	org_id bigint(20) unsigned NOT NULL DEFAULT 0,
	impressions bigint(20) unsigned NOT NULL DEFAULT 0,
	clicks bigint(20) unsigned NOT NULL DEFAULT 0,
	viewables bigint(20) unsigned NULL DEFAULT NULL,
	conversions bigint(20) unsigned NULL DEFAULT NULL,
	projector_version smallint(5) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY slot_creative_bucket (placement_id,campaign_id,line_item_id,creative_id,bucket_utc),
	KEY campaign_bucket (campaign_id,bucket_utc),
	KEY line_item_bucket (line_item_id,bucket_utc),
	KEY org_bucket (org_id,bucket_utc),
	KEY org_campaign_line_bucket (org_id,campaign_id,line_item_id,bucket_utc),
	KEY creative_bucket (creative_id,bucket_utc)
) {$charset_collate};";
	}

	/**
	 * Reporting buckets columns.
	 *
	 * @return list<string>
	 */
	public static function columns(): array {
		return array(
			'id',
			'bucket_utc',
			'placement_id',
			'campaign_id',
			'line_item_id',
			'creative_id',
			'org_id',
			'impressions',
			'clicks',
			'viewables',
			'conversions',
			'projector_version',
		);
	}

	/**
	 * Reporting buckets indexes, in the order MySQL reports them on a fresh table.
	 *
	 * @return list<string>
	 */
	public static function index_names(): array {
		return array( 'PRIMARY', 'slot_creative_bucket', 'campaign_bucket', 'line_item_bucket', 'org_bucket', 'org_campaign_line_bucket', 'creative_bucket' );
	}
}
