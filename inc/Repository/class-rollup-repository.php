<?php
/**
 * Native fill rollups.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Install\Schema;

/**
 * The only code that touches aggr_rollups.
 */
final class Rollup_Repository {

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::ROLLUPS_TABLE;
	}

	/**
	 * Creates or updates the rollups table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::rollups_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
		$this->drop_legacy_slot_day_index();
	}

	/**
	 * Whether the rollups table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; caching existence is how a failed install looks healthy.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drops the rollups table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Adds the line-item column and re-keys the table.
	 *
	 * `dbDelta` adds the column and the new unique key, and drops neither the
	 * old `slot_day` unique nor its meaning: left in place it would refuse a
	 * second line item's row for the same slot and day, which is exactly the
	 * case this migration exists to allow. The same shape as the v5 token index.
	 *
	 * Existing rows are attributed to the campaign's default line item, which is
	 * correct because until now a campaign had exactly one — that assumption is
	 * what made counting by campaign work, and it is what makes the backfill
	 * safe.
	 *
	 * @return void
	 */
	public function migrate_line_item_attribution(): void {
		global $wpdb;

		// Creates the column and drops the pre-v16 unique.
		$this->install_table();

		if ( ! $this->table_exists() ) {
			return;
		}

		$table      = $this->table_name();
		$line_items = $wpdb->prefix . Schema::LINE_ITEMS_TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table names are prefix+constant; there is no caller input in this statement.
		$wpdb->query(
			"UPDATE {$table} r
			JOIN {$line_items} l ON l.campaign_id = r.campaign_id AND l.default_key = 1
			SET r.line_item_id = l.id
			WHERE r.line_item_id = 0"
		);
		// phpcs:enable
	}

	/**
	 * `dbDelta` adds `slot_line_day` but will not drop the pre-v16 `slot_day`.
	 *
	 * Left in place it refuses a second line item's row for the same slot and
	 * day — exactly the case the new key exists to allow. Dropped here rather
	 * than only in the migration so a repair install heals it too, matching how
	 * the events table handles its own superseded unique.
	 *
	 * @return void
	 */
	private function drop_legacy_slot_day_index(): void {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names = is_array( $rows ) ? array_values( array_unique( array_column( $rows, 'Key_name' ) ) ) : array();

		if ( in_array( 'slot_day', $names, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the pre-v16 unique so a second line item can hold its own row.
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX slot_day" );
		}
	}

	/**
	 * Increments today's counter for one slot, campaign and line item.
	 *
	 * @param string $column       impressions|clicks.
	 * @param int    $placement_id Placement id.
	 * @param int    $campaign_id  Campaign id, or 0 for house.
	 * @param string $day_utc      Optional UTC Y-m-d. Invalid values use today.
	 * @param int    $line_item_id Line item the delivery is spent against, or 0.
	 * @return bool
	 */
	public function increment( string $column, int $placement_id, int $campaign_id, string $day_utc = '', int $line_item_id = 0 ): bool {
		global $wpdb;

		if ( ! in_array( $column, array( 'impressions', 'clicks', 'viewables' ), true ) ) {
			return false;
		}

		$table = $this->table_name();
		$day   = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) ? $day_utc : gmdate( 'Y-m-d' );

		/*
		 * `viewables` starts at 0 on any row an impression touches, so the day
		 * is marked as measured even before anything is seen — NULL has to keep
		 * meaning "nobody was measuring" rather than "nothing seen yet today".
		 *
		 * COALESCE on update for the same reason: a row carried over from
		 * before the column existed becomes 0, not NULL + 1.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; column is allowlisted to impressions|clicks|viewables.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day_utc, placement_id, campaign_id, line_item_id, impressions, clicks, viewables) VALUES (%s, %d, %d, %d, %d, %d, %d)
				ON DUPLICATE KEY UPDATE {$column} = COALESCE({$column}, 0) + 1",
				$day,
				$placement_id,
				$campaign_id,
				$line_item_id,
				'impressions' === $column ? 1 : 0,
				'clicks' === $column ? 1 : 0,
				'viewables' === $column ? 1 : 0
			)
		);
		// phpcs:enable

		return false !== $written;
	}

	/**
	 * Rebuilds one closed UTC day's counters exactly from the event ledger.
	 *
	 * INSERT ... SELECT is one atomic statement. Re-running it is idempotent;
	 * it repairs a synchronous projection failure without replaying an event.
	 *
	 * @param string $day_utc Closed UTC Y-m-d.
	 */
	public function reconcile_day( string $day_utc ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) ) {
			return false;
		}

		$start = strtotime( $day_utc . ' 00:00:00 UTC' );

		if ( false === $start ) {
			return false;
		}

		global $wpdb;

		$rollups = $this->table_name();
		$events  = $wpdb->prefix . Schema::EVENTS_TABLE;
		$end     = $start + DAY_IN_SECONDS;

		/*
		 * The event ledger records the creative, not the line item, so the
		 * attribution is recovered by joining the assignment that served it.
		 * That join is durable because withdrawal retires an assignment rather
		 * than deleting it — history keeps something to point at.
		 */
		$assignments = $wpdb->prefix . 'aggr_creative_assignments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Idempotent projection repair between this plugin's two custom tables.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$rollups} (day_utc, placement_id, campaign_id, line_item_id, impressions, clicks, viewables)
				SELECT %s, e.placement_id, e.campaign_id, COALESCE(a.line_item_id, 0),
					SUM(CASE WHEN e.event IN (%s, %s) THEN 1 ELSE 0 END),
					SUM(CASE WHEN e.event = %s THEN 1 ELSE 0 END),
					SUM(CASE WHEN e.event = %s THEN 1 ELSE 0 END)
				FROM {$events} e
				LEFT JOIN {$assignments} a
					ON a.revision_id = e.creative_id AND a.placement_id = e.placement_id
				WHERE e.created_at_ts >= %d AND e.created_at_ts < %d
				GROUP BY e.placement_id, e.campaign_id, COALESCE(a.line_item_id, 0)
				ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks), viewables = VALUES(viewables)",
				$day_utc,
				Event_Repository::TYPE_SERVED,
				Event_Repository::TYPE_IMPRESSION,
				Event_Repository::TYPE_CLICK,
				Event_Repository::TYPE_VIEWABLE,
				$start,
				$end
			)
		);
		// phpcs:enable

		return false !== $written;
	}

	/**
	 * Org-scoped totals across every day and placement.
	 *
	 * The join is the isolation boundary. Summing a PHP list of campaign ids
	 * would under-count as soon as the dashboard is paged, and house rows
	 * (campaign_id = 0) never have organization meta so they cannot leak in.
	 *
	 * @param int $org_id Owning organization.
	 * @return array{impressions: int, clicks: int}
	 */
	public function totals_for_org( int $org_id ): array {
		$empty = array(
			'impressions' => 0,
			'clicks'      => 0,
		);

		if ( $org_id <= 0 ) {
			return $empty;
		}

		global $wpdb;

		$table = $this->table_name();
		$meta  = $wpdb->postmeta;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are prefix+constant / core postmeta; org id is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(r.impressions), 0) AS impressions, COALESCE(SUM(r.clicks), 0) AS clicks
				FROM {$table} r
				INNER JOIN {$meta} m
					ON m.post_id = r.campaign_id
					AND m.meta_key = %s
					AND m.meta_value = %s
				WHERE r.campaign_id > 0",
				Campaign_Repository::META_ORG_ID,
				(string) $org_id
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'impressions' => (int) $row['impressions'],
			'clicks'      => (int) $row['clicks'],
		);
	}

	/**
	 * Per-campaign totals for a trusted id list.
	 *
	 * Callers must pass only ids already authorized for the current user.
	 * This is a SUM, not an ownership check.
	 *
	 * @param array<int, int> $campaign_ids Campaign post ids.
	 * @return array<int, array{impressions: int, clicks: int}>
	 */
	/**
	 * Lifetime and same-day impressions per line item, for pacing.
	 *
	 * Keyed by line item because that is what carries a cap. Counting by
	 * campaign was correct only while every campaign had exactly one line item —
	 * the moment a second exists, each would count its siblings' impressions
	 * against its own cap and stop delivering early.
	 *
	 * One query for the whole candidate set rather than one per candidate: the
	 * decision path may not do work proportional to the number of candidates.
	 *
	 * The day is UTC, matching how rollups are keyed, so "today" means the same
	 * thing here as it does in the row being read.
	 *
	 * @param array<int, int> $line_item_ids Line-item ids.
	 * @param string          $day_utc       UTC day, `Y-m-d`; defaults to today.
	 * @return array<int, array{lifetime: int, today: int}> Keyed by line-item id.
	 */
	public function delivery_totals_for_line_items( array $line_item_ids, string $day_utc = '' ): array {
		$ids = array();

		foreach ( $line_item_ids as $line_item_id ) {
			$id = (int) $line_item_id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( array() === $ids ) {
			return array();
		}

		global $wpdb;

		$table        = $this->table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$day          = '' !== $day_utc ? $day_utc : gmdate( 'Y-m-d' );
		$args         = array_merge( array( $day ), $ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is prefix+constant; placeholders are a fixed %d list matching $ids.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT line_item_id,
					SUM(impressions) AS lifetime,
					SUM(CASE WHEN day_utc = %s THEN impressions ELSE 0 END) AS today
				FROM {$table}
				WHERE line_item_id IN ({$placeholders})
				GROUP BY line_item_id",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$totals = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$totals[ (int) ( $row['line_item_id'] ?? 0 ) ] = array(
					'lifetime' => max( 0, (int) ( $row['lifetime'] ?? 0 ) ),
					'today'    => max( 0, (int) ( $row['today'] ?? 0 ) ),
				);
			}
		}

		return $totals;
	}

	/**
	 * Lifetime impressions and clicks per campaign.
	 *
	 * @param array<int, int> $campaign_ids Campaign post ids.
	 * @return array<int, array{impressions: int, clicks: int}> Keyed by campaign id.
	 */
	public function totals_for_campaigns( array $campaign_ids ): array {
		$ids = array();

		foreach ( $campaign_ids as $campaign_id ) {
			$id = (int) $campaign_id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( array() === $ids ) {
			return array();
		}

		global $wpdb;

		$table        = $this->table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is prefix+constant; placeholders are a fixed %d list matching $ids.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT campaign_id, SUM(impressions) AS impressions, SUM(clicks) AS clicks
				FROM {$table}
				WHERE campaign_id IN ({$placeholders})
				GROUP BY campaign_id",
				...$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$totals = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$campaign_id = (int) $row['campaign_id'];

				$totals[ $campaign_id ] = array(
					'impressions' => (int) $row['impressions'],
					'clicks'      => (int) $row['clicks'],
				);
			}
		}

		return $totals;
	}

	/**
	 * Org-scoped per-campaign, per-day rows for export, oldest day first.
	 *
	 * Unlike `series_for_org()` this does not pad missing days. A sparkline
	 * needs a bar for every day so the shape is honest; a spreadsheet does not
	 * need a row asserting that nothing happened, and 90 days times every
	 * campaign of zeros would bury the days that did.
	 *
	 * The campaign title is joined here rather than looked up per row, because
	 * the alternative is one `get_post()` per row inside an export loop.
	 *
	 * @param int $org_id Owning organization.
	 * @param int $days   Window length, 1–31.
	 * @return list<array{day: string, campaign_id: int, campaign: string, impressions: int, clicks: int}>
	 */
	public function daily_rows_for_org( int $org_id, int $days = 31 ): array {
		$keys = Reporting_Rules::utc_day_keys( $days, gmdate( 'Y-m-d' ) );

		if ( array() === $keys || $org_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$table = $this->table_name();
		$meta  = $wpdb->postmeta;
		$posts = $wpdb->posts;
		$first = $keys[0];
		$last  = $keys[ array_key_last( $keys ) ];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are prefix+constant / core posts and postmeta; bounds and org id are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.day_utc AS day,
					r.campaign_id AS campaign_id,
					p.post_title AS campaign,
					COALESCE(SUM(r.impressions), 0) AS impressions,
					COALESCE(SUM(r.clicks), 0) AS clicks
				FROM {$table} r
				INNER JOIN {$meta} m
					ON m.post_id = r.campaign_id
					AND m.meta_key = %s
					AND m.meta_value = %s
				INNER JOIN {$posts} p
					ON p.ID = r.campaign_id
				WHERE r.campaign_id > 0
					AND r.day_utc >= %s
					AND r.day_utc <= %s
				GROUP BY r.day_utc, r.campaign_id, p.post_title
				ORDER BY r.day_utc ASC, p.post_title ASC",
				Campaign_Repository::META_ORG_ID,
				(string) $org_id,
				$first,
				$last
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'day'         => (string) $row['day'],
				'campaign_id' => (int) $row['campaign_id'],
				'campaign'    => (string) $row['campaign'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
			);
		}

		return $out;
	}

	/**
	 * Org-scoped daily totals for a closed UTC window, oldest day first.
	 *
	 * Missing days are zeros. House rows cannot join organization meta, so they
	 * cannot appear. Callers that need a sparkline must still gate on Reporting.
	 *
	 * @param int $org_id Owning organization.
	 * @param int $days   Length, 1–31. Values outside that range are empty.
	 * @return list<array{day: string, impressions: int, clicks: int}>
	 */
	public function series_for_org( int $org_id, int $days = 7 ): array {
		$keys = Reporting_Rules::utc_day_keys( $days, gmdate( 'Y-m-d' ) );

		if ( array() === $keys || $org_id <= 0 ) {
			return array();
		}

		$padded = array();

		foreach ( $keys as $day ) {
			$padded[ $day ] = array(
				'day'         => $day,
				'impressions' => 0,
				'clicks'      => 0,
			);
		}

		global $wpdb;

		$table = $this->table_name();
		$meta  = $wpdb->postmeta;
		$first = $keys[0];
		$last  = $keys[ array_key_last( $keys ) ];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are prefix+constant / core postmeta; bounds and org id are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.day_utc AS day, COALESCE(SUM(r.impressions), 0) AS impressions, COALESCE(SUM(r.clicks), 0) AS clicks
				FROM {$table} r
				INNER JOIN {$meta} m
					ON m.post_id = r.campaign_id
					AND m.meta_key = %s
					AND m.meta_value = %s
				WHERE r.campaign_id > 0
					AND r.day_utc >= %s
					AND r.day_utc <= %s
				GROUP BY r.day_utc",
				Campaign_Repository::META_ORG_ID,
				(string) $org_id,
				$first,
				$last
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$day = (string) $row['day'];

				if ( ! isset( $padded[ $day ] ) ) {
					continue;
				}

				$padded[ $day ]['impressions'] = (int) $row['impressions'];
				$padded[ $day ]['clicks']      = (int) $row['clicks'];
			}
		}

		return array_values( $padded );
	}
}
