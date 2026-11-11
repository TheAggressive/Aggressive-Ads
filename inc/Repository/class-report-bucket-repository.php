<?php
/**
 * Local-day reporting projection.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Install\Schema;

/**
 * The reporting projection in fifteen-minute UTC buckets.
 *
 * Daily `aggr_rollups` stay the serving counter: `delivered_today` is a UTC
 * day on purpose, and growing that unique key by an hour would turn every
 * pacing read into a twenty-four-row sum. This table is written beside it and
 * read by `Rollup_Report_Repository`, so a local day can be summed without
 * moving the serving clock.
 */
final class Report_Bucket_Repository {

	/**
	 * Which projector wrote a row's counters. Same number as the daily table
	 * so a pair written together can be told from a pair one half repaired.
	 */
	public const PROJECTOR_VERSION = Rollup_Repository::PROJECTOR_VERSION;

	public const OPTION_SINCE = 'aggr_report_buckets_since';
	public const OPTION_NEXT  = 'aggr_report_buckets_backfill_next';
	public const OPTION_UNTIL = 'aggr_report_buckets_backfill_until';

	/**
	 * SQL that turns a Unix timestamp into a UTC quarter-hour without asking
	 * the session timezone. `FROM_UNIXTIME` would follow `@@session.time_zone`,
	 * and a host that is not UTC would write buckets that no local-day query
	 * could find.
	 */
	private const BUCKET_SQL = "DATE_ADD('1970-01-01 00:00:00', INTERVAL (created_at_ts - (created_at_ts % 900)) SECOND)";

	/**
	 * Conversion ledger uses `occurred_at_ts`, not `created_at_ts`.
	 */
	private const CONVERSION_BUCKET_SQL = "DATE_ADD('1970-01-01 00:00:00', INTERVAL (occurred_at_ts - (occurred_at_ts % 900)) SECOND)";

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::REPORT_BUCKETS_TABLE;
	}

	/**
	 * Creates or updates the reporting bucket table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::report_buckets_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/**
	 * Whether the reporting bucket table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; caching existence is how a failed install looks healthy.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drops the reporting bucket table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Increments one bucket's counter for one slot, campaign and line item.
	 *
	 * @param string $column       impressions|clicks|viewables|conversions.
	 * @param int    $placement_id Placement id.
	 * @param int    $campaign_id  Campaign id, or 0 for house.
	 * @param string $bucket_utc     UTC timestamp, date (midnight), or empty for the current bucket.
	 * @param int    $line_item_id Line item the delivery is spent against, or 0.
	 * @param int    $org_id       Owning organization, frozen onto the row.
	 * @param int    $creative_id  Revision that served, or 0 when none is known.
	 */
	public function increment( string $column, int $placement_id, int $campaign_id, string $bucket_utc = '', int $line_item_id = 0, int $org_id = 0, int $creative_id = 0 ): bool {
		global $wpdb;

		if ( ! in_array( $column, array( 'impressions', 'clicks', 'viewables', 'conversions' ), true ) ) {
			return false;
		}

		$table = $this->table_name();
		$hour  = self::normalize_bucket( $bucket_utc );

		$viewables_value   = 'conversions' === $column ? 'NULL' : (string) ( 'viewables' === $column ? 1 : 0 );
		$conversions_value = 'conversions' === $column ? '1' : 'NULL';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; column and the two literals are allowlisted to impressions|clicks|viewables|conversions.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket_utc, placement_id, campaign_id, line_item_id, creative_id, org_id, impressions, clicks, viewables, conversions, projector_version)
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
	 * Rebuilds every UTC bucket in `[start, end)` from the event ledger.
	 *
	 * Same projector as the daily rebuild, one grain finer. The daily table is
	 * not touched: a repair here must not reset a live cap.
	 *
	 * @param int $start Inclusive Unix timestamp.
	 * @param int $end   Exclusive Unix timestamp.
	 */
	public function reconcile_span( int $start, int $end ): bool {
		if ( $start <= 0 || $end <= $start || 0 !== $start % DAY_IN_SECONDS || 0 !== $end % DAY_IN_SECONDS ) {
			return false;
		}

		global $wpdb;

		$rollups     = $this->table_name();
		$events      = $wpdb->prefix . Schema::EVENTS_TABLE;
		$assignments = $wpdb->prefix . 'aggr_creative_assignments';
		$meta        = $wpdb->postmeta;
		$owners      = $this->ownership_sql( $start, $end );
		$start_hour  = self::normalize_bucket( gmdate( 'Y-m-d H:i:s', $start ) );
		$end_hour    = self::normalize_bucket( gmdate( 'Y-m-d H:i:s', $end ) );
		$since       = (string) get_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE, '' );
		$hour_sql    = str_replace( 'created_at_ts', 'e.created_at_ts', self::BUCKET_SQL );
		$stale_hour  = str_replace( 'created_at_ts', 'k.created_at_ts', self::BUCKET_SQL );
		$since_day   = '' === $since ? '' : $since . ' 00:00:00';

		/*
		 * Rows this span's ledger supersedes are removed before it is rebuilt,
		 * for the same reason the daily reconciler does it: a `creative_id = 0`
		 * aggregate and a per-creative rebuild are different keys, so leaving
		 * the old row would count the span twice.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table names are prefix+constant; every value is a placeholder.
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE r FROM {$rollups} r
				JOIN (
					SELECT DISTINCT placement_id, campaign_id
					FROM {$events}
					WHERE created_at_ts >= %d AND created_at_ts < %d
				) covered
					ON covered.placement_id = r.placement_id AND covered.campaign_id = r.campaign_id
				WHERE r.bucket_utc >= %s AND r.bucket_utc < %s
					AND NOT EXISTS (
						SELECT 1 FROM {$events} k
						WHERE k.created_at_ts >= %d AND k.created_at_ts < %d
							AND k.placement_id = r.placement_id
							AND k.campaign_id = r.campaign_id
							AND k.creative_id = r.creative_id
							AND {$stale_hour} = r.bucket_utc
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
				"INSERT INTO {$rollups} (bucket_utc, placement_id, campaign_id, line_item_id, creative_id, org_id, impressions, clicks, viewables, projector_version)
				SELECT {$hour_sql}, e.placement_id, e.campaign_id, COALESCE(a.line_item_id, 0), e.creative_id, COALESCE(MAX(d.org_id), MAX(m.meta_value), 0),
					SUM(CASE WHEN e.event IN (%s, %s) THEN 1 ELSE 0 END),
					SUM(CASE WHEN e.event = %s THEN 1 ELSE 0 END),
					IF(%s = '' OR {$hour_sql} < %s, NULL, SUM(CASE WHEN e.event = %s THEN 1 ELSE 0 END)),
					%d
				FROM {$events} e
				LEFT JOIN {$assignments} a
					ON a.revision_id = e.creative_id AND a.placement_id = e.placement_id
				LEFT JOIN ({$owners}) d ON d.day_utc = DATE({$hour_sql})
					AND d.placement_id = e.placement_id AND d.campaign_id = e.campaign_id
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

		return false !== $removed && false !== $written && $this->reconcile_conversions( $start, $end );
	}

	/**
	 * Rebuilds conversion buckets from the conversion ledger.
	 *
	 * @param int $start Inclusive Unix timestamp.
	 * @param int $end   Exclusive Unix timestamp.
	 */
	private function reconcile_conversions( int $start, int $end ): bool {
		global $wpdb;

		$rollups     = $this->table_name();
		$conversions = $wpdb->prefix . Schema::CONVERSIONS_TABLE;
		$hour_sql    = str_replace( 'occurred_at_ts', 'c.occurred_at_ts', self::CONVERSION_BUCKET_SQL );
		$owners      = $this->ownership_sql( $start, $end );
		$meta        = $wpdb->postmeta;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Idempotent projection repair between this plugin's two custom tables.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$rollups} (bucket_utc, placement_id, campaign_id, line_item_id, creative_id, org_id, impressions, clicks, viewables, conversions, projector_version)
				SELECT {$hour_sql}, c.placement_id, c.campaign_id, c.line_item_id, c.creative_id, COALESCE(MAX(d.org_id), MAX(m.meta_value), 0), 0, 0, NULL, COUNT(*), %d
				FROM {$conversions} c
				LEFT JOIN ({$owners}) d ON d.day_utc = DATE({$hour_sql})
					AND d.placement_id = c.placement_id AND d.campaign_id = c.campaign_id
				LEFT JOIN {$meta} m ON m.post_id = c.campaign_id AND m.meta_key = %s
				WHERE c.occurred_at_ts >= %d AND c.occurred_at_ts < %d
				GROUP BY {$hour_sql}, c.placement_id, c.campaign_id, c.line_item_id, c.creative_id
				ON DUPLICATE KEY UPDATE conversions = VALUES(conversions),
					org_id = IF(org_id = 0, VALUES(org_id), org_id), projector_version = VALUES(projector_version)",
				self::PROJECTOR_VERSION,
				Campaign_Repository::META_ORG_ID,
				$start,
				$end
			)
		);
		// phpcs:enable

		return false !== $written;
	}

	/**
	 * Historical tenant evidence survives cleaned-up assignments and legacy creative 0.
	 * One row per placement/campaign/day avoids multiplying ledger aggregates.
	 * Ambiguous historical ownership stays unattributed rather than naming a tenant.
	 *
	 * @param int $start Inclusive Unix timestamp.
	 * @param int $end Exclusive Unix timestamp.
	 */
	private function ownership_sql( int $start, int $end ): string {
		global $wpdb;
		$daily = $wpdb->prefix . Schema::ROLLUPS_TABLE;

		return $wpdb->prepare(
			'SELECT day_utc, placement_id, campaign_id,
				CASE WHEN COUNT(DISTINCT org_id) = 1 THEN MAX(org_id) ELSE 0 END AS org_id
			FROM %i WHERE day_utc >= %s AND day_utc < %s
			GROUP BY day_utc, placement_id, campaign_id',
			$daily,
			gmdate( 'Y-m-d', $start ),
			gmdate( 'Y-m-d', $end )
		);
	}

	/**
	 * Projects closed buckets from the event ledger up to, not including, `$before_ts`.
	 *
	 * History the ledger no longer holds is not invented from daily rows: a
	 * UTC day cannot be split honestly, and stuffing every daily total into
	 * bucket 00:00 would make a local-day chart look precise about a guess.
	 * Days outside retention stay on the daily table for pacing and lifetime
	 * tiles; they do not appear in a local-day report.
	 *
	 * @param int $before_ts Exclusive Unix upper bound, typically midnight UTC today.
	 * @return int Closed UTC days rebuilt (at most seven per invocation).
	 */
	public function backfill_from_events( int $before_ts ): int {
		if ( ! $this->begin_backfill() ) {
			return 0;
		}

		$next  = (int) get_option( self::OPTION_NEXT, 0 );
		$until = min( $before_ts, (int) get_option( self::OPTION_UNTIL, 0 ) );
		$done  = 0;

		while ( $next > 0 && $next < $until && $done < 7 ) {
			$end = min( $next + DAY_IN_SECONDS, $until );

			if ( ! $this->reconcile_span( $next, $end ) ) {
				return $done;
			}

			$next = $end;
			update_option( self::OPTION_NEXT, $next, false );
			++$done;
		}

		if ( $next >= (int) get_option( self::OPTION_UNTIL, 0 ) ) {
			delete_option( self::OPTION_NEXT );
		}

		return $done;
	}

	/**
	 * Initializes a restartable backfill without aggregating on an upgrade request.
	 * The oldest retained timestamp is the limit of what can be reconstructed.
	 */
	public function begin_backfill(): bool {
		if ( false !== get_option( self::OPTION_SINCE, false ) ) {
			return true;
		}

		global $wpdb;

		$events      = $wpdb->prefix . Schema::EVENTS_TABLE;
		$conversions = $wpdb->prefix . Schema::CONVERSIONS_TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One indexed MIN per durable ledger to establish the migration cursor; names are prefix+constant.
		$event = $wpdb->get_row( "SELECT MIN(created_at_ts) AS oldest FROM {$events}", ARRAY_A );
		if ( ! is_array( $event ) ) {
			return false;
		}
		$conversion = $wpdb->get_row( "SELECT MIN(occurred_at_ts) AS oldest FROM {$conversions}", ARRAY_A );
		if ( ! is_array( $conversion ) ) {
			return false;
		}
		// phpcs:enable

		$now    = time();
		$oldest = min( array_filter( array( (int) $event['oldest'], (int) $conversion['oldest'], $now ), static fn ( int $at ): bool => $at > 0 ) );
		$start  = $oldest - ( $oldest % DAY_IN_SECONDS );
		// The upgrade day's earlier traffic also needs repair. Keep it pending
		// until that day closes; rebuilding it now would race live increments.
		$until = $now - ( $now % DAY_IN_SECONDS ) + DAY_IN_SECONDS;

		update_option( self::OPTION_UNTIL, $until, false );
		update_option( self::OPTION_NEXT, $start, false );
		update_option( self::OPTION_SINCE, $oldest, false );

		return true;
	}

	/**
	 * The fifteen-minute UTC bucket containing a timestamp.
	 *
	 * A bare `Y-m-d` becomes midnight of that UTC day so a test that already
	 * named a daily increment lands on a single bucket the UTC report still
	 * finds. Live traffic passes '' and is stored in the bucket the event
	 * happened, which is the whole reason this table exists.
	 *
	 * @param string $bucket_utc Candidate UTC timestamp or date.
	 */
	public static function normalize_bucket( string $bucket_utc ): string {
		$timestamp = '' === $bucket_utc ? time() : strtotime( $bucket_utc . ' UTC' );

		if ( false === $timestamp ) {
			$timestamp = time();
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp - ( $timestamp % 900 ) );
	}
}
