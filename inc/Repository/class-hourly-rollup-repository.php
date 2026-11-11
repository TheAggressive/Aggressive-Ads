<?php
/**
 * Hourly fill rollups.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Install\Schema;

/**
 * The reporting projection at hour grain.
 *
 * Daily `aggr_rollups` stay the serving counter: `delivered_today` is a UTC
 * day on purpose, and growing that unique key by an hour would turn every
 * pacing read into a twenty-four-row sum. This table is written beside it and
 * read by `Rollup_Report_Repository`, so a local day can be summed without
 * moving the serving clock.
 */
final class Hourly_Rollup_Repository {

	/**
	 * Which projector wrote a row's counters. Same number as the daily table
	 * so a pair written together can be told from a pair one half repaired.
	 */
	public const PROJECTOR_VERSION = Rollup_Repository::PROJECTOR_VERSION;

	/**
	 * SQL that turns a Unix timestamp into a UTC clock hour without asking
	 * the session timezone. `FROM_UNIXTIME` would follow `@@session.time_zone`,
	 * and a host that is not UTC would write hours that no local-day query
	 * could find.
	 */
	private const HOUR_SQL = "DATE_ADD('1970-01-01 00:00:00', INTERVAL (created_at_ts - (created_at_ts % 3600)) SECOND)";

	/**
	 * Conversion ledger uses `occurred_at_ts`, not `created_at_ts`.
	 */
	private const CONVERSION_HOUR_SQL = "DATE_ADD('1970-01-01 00:00:00', INTERVAL (occurred_at_ts - (occurred_at_ts % 3600)) SECOND)";

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::HOURLY_ROLLUPS_TABLE;
	}

	/**
	 * Creates or updates the hourly rollups table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::hourly_rollups_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/**
	 * Whether the hourly rollups table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; caching existence is how a failed install looks healthy.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drops the hourly rollups table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Increments one hour's counter for one slot, campaign and line item.
	 *
	 * @param string $column       impressions|clicks|viewables|conversions.
	 * @param int    $placement_id Placement id.
	 * @param int    $campaign_id  Campaign id, or 0 for house.
	 * @param string $hour_utc     UTC `Y-m-d H:00:00`, or a `Y-m-d` (midnight), or '' for the current hour.
	 * @param int    $line_item_id Line item the delivery is spent against, or 0.
	 * @param int    $org_id       Owning organization, frozen onto the row.
	 * @param int    $creative_id  Revision that served, or 0 when none is known.
	 */
	public function increment( string $column, int $placement_id, int $campaign_id, string $hour_utc = '', int $line_item_id = 0, int $org_id = 0, int $creative_id = 0 ): bool {
		global $wpdb;

		if ( ! in_array( $column, array( 'impressions', 'clicks', 'viewables', 'conversions' ), true ) ) {
			return false;
		}

		$table = $this->table_name();
		$hour  = self::normalize_hour( $hour_utc );

		$viewables_value   = 'conversions' === $column ? 'NULL' : (string) ( 'viewables' === $column ? 1 : 0 );
		$conversions_value = 'conversions' === $column ? '1' : 'NULL';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; column and the two literals are allowlisted to impressions|clicks|viewables|conversions.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (hour_utc, placement_id, campaign_id, line_item_id, creative_id, org_id, impressions, clicks, viewables, conversions, projector_version)
				VALUES (%s, %d, %d, %d, %d, %d, %d, %d, {$viewables_value}, {$conversions_value}, %d)
				ON DUPLICATE KEY UPDATE {$column} = COALESCE({$column}, 0) + 1,
					org_id = IF(org_id = 0, VALUES(org_id), org_id),
					projector_version = VALUES(projector_version)",
				$hour,
				$placement_id,
				$campaign_id,
				$line_item_id,
				max( 0, $creative_id ),
				max( 0, $org_id ),
				'impressions' === $column ? 1 : 0,
				'clicks' === $column ? 1 : 0,
				self::PROJECTOR_VERSION
			)
		);
		// phpcs:enable

		return false !== $written;
	}

	/**
	 * Rebuilds every UTC hour in `[start, end)` from the event ledger.
	 *
	 * Same projector as the daily rebuild, one grain finer. The daily table is
	 * not touched: a repair here must not reset a live cap.
	 *
	 * @param int $start Inclusive Unix timestamp.
	 * @param int $end   Exclusive Unix timestamp.
	 */
	public function reconcile_span( int $start, int $end ): bool {
		if ( $start <= 0 || $end <= $start ) {
			return false;
		}

		global $wpdb;

		$rollups     = $this->table_name();
		$events      = $wpdb->prefix . Schema::EVENTS_TABLE;
		$assignments = $wpdb->prefix . 'aggr_creative_assignments';
		$meta        = $wpdb->postmeta;
		$start_hour  = gmdate( 'Y-m-d H:00:00', $start );
		$end_hour    = gmdate( 'Y-m-d H:00:00', $end );
		$since       = (string) get_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE, '' );
		$hour_sql    = str_replace( 'created_at_ts', 'e.created_at_ts', self::HOUR_SQL );
		$stale_hour  = str_replace( 'created_at_ts', 'k.created_at_ts', self::HOUR_SQL );
		$since_day   = '' === $since ? '' : $since . ' 00:00:00';

		/*
		 * Rows this span's ledger supersedes are removed before it is rebuilt,
		 * for the same reason the daily reconciler does it: a `creative_id = 0`
		 * aggregate and a per-creative rebuild are different keys, so leaving
		 * the old row would count the span twice.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table names are prefix+constant; every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE r FROM {$rollups} r
				JOIN (
					SELECT DISTINCT placement_id, campaign_id
					FROM {$events}
					WHERE created_at_ts >= %d AND created_at_ts < %d
				) covered
					ON covered.placement_id = r.placement_id AND covered.campaign_id = r.campaign_id
				WHERE r.hour_utc >= %s AND r.hour_utc < %s
					AND NOT EXISTS (
						SELECT 1 FROM {$events} k
						WHERE k.created_at_ts >= %d AND k.created_at_ts < %d
							AND k.placement_id = r.placement_id
							AND k.campaign_id = r.campaign_id
							AND k.creative_id = r.creative_id
							AND {$stale_hour} = r.hour_utc
					)",
				$start,
				$end,
				$start_hour,
				$end_hour,
				$start,
				$end
			)
		);

		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$rollups} (hour_utc, placement_id, campaign_id, line_item_id, creative_id, org_id, impressions, clicks, viewables, projector_version)
				SELECT {$hour_sql}, e.placement_id, e.campaign_id, COALESCE(a.line_item_id, 0), e.creative_id, COALESCE(MAX(m.meta_value), 0),
					SUM(CASE WHEN e.event IN (%s, %s) THEN 1 ELSE 0 END),
					SUM(CASE WHEN e.event = %s THEN 1 ELSE 0 END),
					IF(%s = '' OR {$hour_sql} < %s, NULL, SUM(CASE WHEN e.event = %s THEN 1 ELSE 0 END)),
					%d
				FROM {$events} e
				LEFT JOIN {$assignments} a
					ON a.revision_id = e.creative_id AND a.placement_id = e.placement_id
				LEFT JOIN {$meta} m
					ON m.post_id = e.campaign_id AND m.meta_key = %s
				WHERE e.created_at_ts >= %d AND e.created_at_ts < %d
				GROUP BY {$hour_sql}, e.placement_id, e.campaign_id, COALESCE(a.line_item_id, 0), e.creative_id
				ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks),
					viewables = IF(VALUES(viewables) = 0 AND viewables IS NULL, NULL, VALUES(viewables)),
					org_id = IF(org_id = 0, VALUES(org_id), org_id),
					projector_version = VALUES(projector_version)",
				Event_Repository::TYPE_SERVED,
				Event_Repository::TYPE_IMPRESSION,
				Event_Repository::TYPE_CLICK,
				$since,
				$since_day,
				Event_Repository::TYPE_VIEWABLE,
				self::PROJECTOR_VERSION,
				Campaign_Repository::META_ORG_ID,
				$start,
				$end
			)
		);
		// phpcs:enable

		return false !== $written && $this->reconcile_conversions( $start, $end );
	}

	/**
	 * Rebuilds hourly conversion counters from the conversion ledger.
	 *
	 * @param int $start Inclusive Unix timestamp.
	 * @param int $end   Exclusive Unix timestamp.
	 */
	private function reconcile_conversions( int $start, int $end ): bool {
		global $wpdb;

		$rollups     = $this->table_name();
		$conversions = $wpdb->prefix . Schema::CONVERSIONS_TABLE;
		$hour_sql    = str_replace( 'occurred_at_ts', 'c.occurred_at_ts', self::CONVERSION_HOUR_SQL );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Idempotent projection repair between this plugin's two custom tables.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$rollups} (hour_utc, placement_id, campaign_id, line_item_id, creative_id, impressions, clicks, viewables, conversions)
				SELECT {$hour_sql}, c.placement_id, c.campaign_id, c.line_item_id, c.creative_id, 0, 0, NULL, COUNT(*)
				FROM {$conversions} c
				WHERE c.occurred_at_ts >= %d AND c.occurred_at_ts < %d
				GROUP BY {$hour_sql}, c.placement_id, c.campaign_id, c.line_item_id, c.creative_id
				ON DUPLICATE KEY UPDATE conversions = VALUES(conversions)",
				$start,
				$end
			)
		);
		// phpcs:enable

		return false !== $written;
	}

	/**
	 * Projects closed hours from the event ledger up to, not including, `$before_ts`.
	 *
	 * History the ledger no longer holds is not invented from daily rows: a
	 * UTC day cannot be split honestly, and stuffing every daily total into
	 * hour 00:00 would make a local-day chart look precise about a guess.
	 * Days outside retention stay on the daily table for pacing and lifetime
	 * tiles; they do not appear in a local-day report.
	 *
	 * @param int $before_ts Exclusive Unix upper bound, typically midnight UTC today.
	 * @return int Days whose hours were rebuilt.
	 */
	public function backfill_from_events( int $before_ts ): int {
		$day  = ( new Event_Repository() )->earliest_day_between( 0, $before_ts );
		$done = 0;

		while ( null !== $day ) {
			$start = strtotime( $day . ' 00:00:00 UTC' );

			if ( false === $start || $start >= $before_ts ) {
				break;
			}

			$end = min( $start + DAY_IN_SECONDS, $before_ts );

			if ( ! $this->reconcile_span( $start, $end ) ) {
				break;
			}

			++$done;
			$day = $end >= $before_ts ? null : gmdate( 'Y-m-d', $end );
		}

		return $done;
	}

	/**
	 * A stored UTC hour, or the current one.
	 *
	 * A bare `Y-m-d` becomes midnight of that UTC day so a test that already
	 * named a daily increment lands on a single hour the UTC report still
	 * finds. Live traffic passes '' and is stored on the hour the event
	 * happened, which is the whole reason this table exists.
	 *
	 * @param string $hour_utc Candidate hour or day.
	 */
	public static function normalize_hour( string $hour_utc ): string {
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:00:00$/', $hour_utc ) ) {
			return $hour_utc;
		}

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $hour_utc ) ) {
			return $hour_utc . ' 00:00:00';
		}

		return gmdate( 'Y-m-d H:00:00' );
	}
}
