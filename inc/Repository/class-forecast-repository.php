<?php
/**
 * Immutable, versioned supply forecasts.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Supply_Forecast;
use Aggressive\Ads\Domain\Utc_Day;
use Aggressive\Ads\Install\Planning_Schema;
use Aggressive\Ads\Install\Schema;

/**
 * Stores what a placement was forecast to supply, and what it actually did.
 *
 * **A forecast is a claim made at a moment.** Re-forecasting the same window
 * writes a new version rather than editing the old one, so what a publisher
 * was told in March survives being told something else in April — which is the
 * only thing that makes the error recorded against the March figure mean
 * anything. The contract requires exactly that: *forecasts are immutable
 * snapshots; reforecasting creates a new version and preserves observed error
 * for the old one.*
 *
 * **`actual` is the single exception, and it is write-once.** The forecast half
 * never changes; the outcome is appended when the window has matured.
 * {@see self::record_actual()} refuses a second write rather than trusting a
 * caller not to make one, because a matured figure that can be rewritten is a
 * figure an inconvenient forecast error can be edited out of.
 *
 * Staff-only, like everything else in P16. Nothing here belongs in an
 * advertiser response, an export or an email.
 */
final class Forecast_Repository {

	/** Rows one history read will return. */
	public const MAX_HISTORY = 50;

	/** Fully prefixed table name. */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::FORECASTS_TABLE;
	}

	/** Creates or upgrades the table. */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Planning_Schema::forecasts_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/** Whether the table exists. */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe; a cached answer is how a missing table looks present.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** Drops the table. Uninstall only. */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall. The name is built from $wpdb->prefix and a class constant; identifiers cannot be bound as parameters.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Stores one forecast as the next version of its window.
	 *
	 * **The version is claimed by the unique key, not by the read that
	 * suggested it.** Two staff re-forecasting the same window at once would
	 * both read the same highest version and both write it; the key refuses
	 * the second insert, and this returns 0 rather than reporting a success
	 * that overwrote a snapshot somebody had already been quoted. A caller
	 * that wants the row may read again — the point is that neither of them
	 * silently loses.
	 *
	 * @param int                                                                                                         $placement   Placement post id.
	 * @param string                                                                                                      $opportunity `Domain\Opportunity` kind.
	 * @param string                                                                                                      $from_utc    First day of the window, `Y-m-d`.
	 * @param string                                                                                                      $to_utc      Last day of the window, `Y-m-d`.
	 * @param array{estimate: int|null, optimistic: int|null, confidence: string, days_observed: int, days_forecast: int} $forecast    What to store.
	 * @return int Version written, or 0 when nothing was.
	 */
	public function record( int $placement, string $opportunity, string $from_utc, string $to_utc, array $forecast ): int {
		global $wpdb;

		if ( $placement <= 0 || ! Opportunity::is_valid( $opportunity ) ) {
			return 0;
		}

		if ( ! Utc_Day::is_window( $from_utc, $to_utc ) ) {
			return 0;
		}

		/*
		 * A forecast with no estimate is not stored. There is nothing to be
		 * wrong about later, so a row would only add a version whose error can
		 * never be computed — and a history full of them would make a placement
		 * look re-forecast rather than unmeasurable.
		 */
		if ( null === $forecast['estimate'] ) {
			return 0;
		}

		$version = $this->next_version( $placement, $opportunity, $from_utc, $to_utc );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the unique key is the concurrency control.
		$written = $wpdb->insert(
			$this->table_name(),
			array(
				'placement_id'  => $placement,
				'opportunity'   => $opportunity,
				'window_start'  => $from_utc,
				'window_end'    => $to_utc,
				'version'       => $version,
				'estimate'      => max( 0, (int) $forecast['estimate'] ),
				'optimistic'    => max( 0, (int) ( $forecast['optimistic'] ?? 0 ) ),
				'confidence'    => (string) $forecast['confidence'],
				'days_observed' => max( 0, (int) $forecast['days_observed'] ),
				'days_forecast' => max( 0, (int) $forecast['days_forecast'] ),
				'made_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s' )
		);

		return false === $written ? 0 : $version;
	}

	/**
	 * Records what the window actually supplied, once.
	 *
	 * Applied to every version of that window, because each of them forecast
	 * the same days and each one's error is measured against the same outcome.
	 * Versions already carrying a figure are left alone by the `IS NULL`
	 * predicate rather than by a read-then-write, so two maturing runs racing
	 * each other cannot produce a rewrite between them.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param string $from_utc    First day of the window, `Y-m-d`.
	 * @param string $to_utc      Last day of the window, `Y-m-d`.
	 * @param int    $actual      Opportunities the window really produced.
	 * @return int Versions updated.
	 */
	public function record_actual( int $placement, string $opportunity, string $from_utc, string $to_utc, int $actual ): int {
		global $wpdb;

		if ( $placement <= 0 || ! Opportunity::is_valid( $opportunity ) || $actual < 0 ) {
			return 0;
		}

		if ( ! Utc_Day::is_window( $from_utc, $to_utc ) ) {
			return 0;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder. The IS NULL is what makes this write-once.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET actual = %d, actual_at = %s WHERE placement_id = %d AND opportunity = %s AND window_start = %s AND window_end = %s AND actual IS NULL", $actual, gmdate( 'Y-m-d H:i:s' ), $placement, $opportunity, $from_utc, $to_utc ) );

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * The newest version of one window, or null when there is none.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param string $from_utc    First day of the window, `Y-m-d`.
	 * @param string $to_utc      Last day of the window, `Y-m-d`.
	 * @return array<string, mixed>|null
	 */
	public function latest( int $placement, string $opportunity, string $from_utc, string $to_utc ): ?array {
		global $wpdb;

		if ( $placement <= 0 || ! Utc_Day::is_window( $from_utc, $to_utc ) ) {
			return null;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE placement_id = %d AND opportunity = %s AND window_start = %s AND window_end = %s ORDER BY version DESC LIMIT 1", $placement, $opportunity, $from_utc, $to_utc ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * The newest forecast of one window for many placements, in one query.
	 *
	 * A screen listing the catalogue needs this figure per placement, and
	 * calling {@see self::latest()} in a loop would issue one query each — two
	 * hundred at the catalogue ceiling, for a page meant to be a glance. The
	 * same reason `Decision_Rollup_Repository::totals_by_placement()` exists.
	 *
	 * @param array<int, int> $placements  Placement post ids.
	 * @param string          $opportunity `Domain\Opportunity` kind.
	 * @param string          $from_utc    First day of the window, `Y-m-d`.
	 * @param string          $to_utc      Last day of the window, `Y-m-d`.
	 * @return array<int, array<string, mixed>> Placement id to its newest snapshot.
	 */
	public function latest_by_placement( array $placements, string $opportunity, string $from_utc, string $to_utc ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $placements ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( array() === $ids || ! Opportunity::is_valid( $opportunity ) || ! Utc_Day::is_window( $from_utc, $to_utc ) ) {
			return array();
		}

		$table  = $this->table_name();
		$slots  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$values = array_merge( $ids, array( $opportunity, $from_utc, $to_utc ), $ids, array( $opportunity, $from_utc, $to_utc ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Prefix-derived table. The id placeholders are generated from a bounded, integer-cast list and every value is bound; the sniff cannot count a generated set.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT f.* FROM {$table} f JOIN (SELECT placement_id, MAX(version) AS version FROM {$table} WHERE placement_id IN ({$slots}) AND opportunity = %s AND window_start = %s AND window_end = %s GROUP BY placement_id) newest ON newest.placement_id = f.placement_id AND newest.version = f.version WHERE f.placement_id IN ({$slots}) AND f.opportunity = %s AND f.window_start = %s AND f.window_end = %s", $values ), ARRAY_A );

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$shaped = self::shape( $row );

			$out[ $shaped['placement_id'] ] = $shaped;
		}

		return $out;
	}

	/**
	 * Every version of one window, oldest first.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param string $from_utc    First day of the window, `Y-m-d`.
	 * @param string $to_utc      Last day of the window, `Y-m-d`.
	 * @return array<int, array<string, mixed>>
	 */
	public function versions( int $placement, string $opportunity, string $from_utc, string $to_utc ): array {
		global $wpdb;

		if ( $placement <= 0 || ! Utc_Day::is_window( $from_utc, $to_utc ) ) {
			return array();
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE placement_id = %d AND opportunity = %s AND window_start = %s AND window_end = %s ORDER BY version ASC LIMIT %d", $placement, $opportunity, $from_utc, $to_utc, self::MAX_HISTORY ), ARRAY_A );

		return array_map(
			static fn ( array $row ): array => self::shape( $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Matured snapshots for one placement, newest window first.
	 *
	 * Only rows that have an outcome, because a summary is over what has been
	 * judged. An open window is not an accurate one, and including it so the
	 * caller can skip it again would make the row count and the judged count
	 * disagree for no benefit.
	 *
	 * **The join is what excludes an open window**, not a second predicate
	 * beside it. Its subquery only considers versions carrying an outcome, so a
	 * window with none joins nothing and never appears — and repeating
	 * `actual IS NOT NULL` in the outer clause would be a condition no row can
	 * fail, which reads as a case somebody thought about rather than one that
	 * cannot arise.
	 *
	 * **The newest version of each window, not every version.** A window
	 * forecast four times would otherwise contribute four measurements of one
	 * outcome, weighting a much-revised window four times as heavily as a
	 * window nobody looked at twice — and revision usually means somebody was
	 * uncertain, which is the opposite of the weighting anybody would choose.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param int    $limit       Windows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function matured( int $placement, string $opportunity, int $limit = self::MAX_HISTORY ): array {
		global $wpdb;

		if ( $placement <= 0 || ! Opportunity::is_valid( $opportunity ) || $limit <= 0 ) {
			return array();
		}

		$table = $this->table_name();
		$rows  = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder. The join keeps one row per window rather than one per version.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT f.* FROM {$table} f JOIN (SELECT window_start, window_end, MAX(version) AS version FROM {$table} WHERE placement_id = %d AND opportunity = %s AND actual IS NOT NULL GROUP BY window_start, window_end) newest ON newest.window_start = f.window_start AND newest.window_end = f.window_end AND newest.version = f.version WHERE f.placement_id = %d AND f.opportunity = %s ORDER BY f.window_end DESC LIMIT %d", $placement, $opportunity, $placement, $opportunity, min( self::MAX_HISTORY, $limit ) ), ARRAY_A );

		foreach ( is_array( $found ) ? $found : array() as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = self::shape( $row );
			}
		}

		return $rows;
	}

	/**
	 * Windows that have ended and have no outcome recorded yet.
	 *
	 * Bounded, and ordered by the oldest window rather than the oldest row, so
	 * a backlog is worked through in the order the evidence became available.
	 *
	 * @param string $through_utc Last day that counts as matured, `Y-m-d`.
	 * @param int    $limit       Rows to return.
	 * @return array<int, array{placement_id: int, opportunity: string, window_start: string, window_end: string}>
	 */
	public function awaiting_actuals( string $through_utc, int $limit ): array {
		global $wpdb;

		if ( ! Utc_Day::is_day( $through_utc ) || $limit <= 0 ) {
			return array();
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT placement_id, opportunity, window_start, window_end FROM {$table} WHERE actual IS NULL AND window_end <= %s ORDER BY window_end ASC LIMIT %d", $through_utc, min( self::MAX_HISTORY, $limit ) ), ARRAY_A );

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'placement_id' => (int) ( $row['placement_id'] ?? 0 ),
				'opportunity'  => (string) ( $row['opportunity'] ?? Opportunity::PAGE ),
				'window_start' => (string) ( $row['window_start'] ?? '' ),
				'window_end'   => (string) ( $row['window_end'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Removes snapshots for windows that ended before a day.
	 *
	 * @param string $through_utc Last day to remove, `Y-m-d`.
	 * @param int    $limit       Rows removed in one pass.
	 * @return int Rows removed.
	 */
	public function purge_through( string $through_utc, int $limit ): int {
		global $wpdb;

		if ( ! Utc_Day::is_day( $through_utc ) || $limit <= 0 ) {
			return 0;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder.
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE window_end <= %s LIMIT %d", $through_utc, $limit ) );

		return is_int( $removed ) ? $removed : 0;
	}

	/**
	 * The version a new snapshot of this window should claim.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param string $from_utc    First day of the window, `Y-m-d`.
	 * @param string $to_utc      Last day of the window, `Y-m-d`.
	 */
	private function next_version( int $placement, string $opportunity, string $from_utc, string $to_utc ): int {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder.
		$highest = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE placement_id = %d AND opportunity = %s AND window_start = %s AND window_end = %s", $placement, $opportunity, $from_utc, $to_utc ) );

		return (int) $highest + 1;
	}

	/**
	 * One row as typed values.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		$actual = $row['actual'] ?? null;

		return array(
			'placement_id'  => (int) ( $row['placement_id'] ?? 0 ),
			'opportunity'   => (string) ( $row['opportunity'] ?? Opportunity::PAGE ),
			'window_start'  => (string) ( $row['window_start'] ?? '' ),
			'window_end'    => (string) ( $row['window_end'] ?? '' ),
			'version'       => (int) ( $row['version'] ?? 0 ),
			'estimate'      => (int) ( $row['estimate'] ?? 0 ),
			'optimistic'    => (int) ( $row['optimistic'] ?? 0 ),
			'confidence'    => (string) ( $row['confidence'] ?? Supply_Forecast::CONFIDENCE_NONE ),
			'days_observed' => (int) ( $row['days_observed'] ?? 0 ),
			'days_forecast' => (int) ( $row['days_forecast'] ?? 0 ),
			'made_at'       => (string) ( $row['made_at'] ?? '' ),

			/*
			 * Null rather than zero for a window that has not matured. A window
			 * that supplied nothing and a window nobody has measured are
			 * different facts, and only one of them is a forecast error.
			 */
			'actual'        => null === $actual ? null : (int) $actual,
		);
	}
}
