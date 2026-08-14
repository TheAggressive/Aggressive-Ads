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
	 * Increments today's counter for one slot and campaign.
	 *
	 * @param string $column       impressions|clicks.
	 * @param int    $placement_id Placement id.
	 * @param int    $campaign_id  Campaign id, or 0 for house.
	 * @param string $day_utc      Optional UTC Y-m-d. Invalid values use today.
	 */
	public function increment( string $column, int $placement_id, int $campaign_id, string $day_utc = '' ): void {
		global $wpdb;

		if ( ! in_array( $column, array( 'impressions', 'clicks' ), true ) ) {
			return;
		}

		$table = $this->table_name();
		$day   = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) ? $day_utc : gmdate( 'Y-m-d' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefix+constant; column is allowlisted to impressions|clicks.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day_utc, placement_id, campaign_id, impressions, clicks) VALUES (%s, %d, %d, %d, %d)
				ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
				$day,
				$placement_id,
				$campaign_id,
				'impressions' === $column ? 1 : 0,
				'clicks' === $column ? 1 : 0
			)
		);
		// phpcs:enable
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
