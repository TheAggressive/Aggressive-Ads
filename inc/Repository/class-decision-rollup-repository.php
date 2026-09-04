<?php
/**
 * Per-placement, per-day decision outcome counters.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Install\Schema;

/**
 * The only code that touches aggr_decision_rollups.
 *
 * Answers "why is this slot empty" from durable, bounded, per-day rows. See
 * docs/platform-p13-event-analytics-schema.md for why this replaced an option.
 */
final class Decision_Rollup_Repository {

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::DECISION_ROLLUPS_TABLE;
	}

	/**
	 * Creates or updates the decision rollups table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::decision_rollups_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );

		$this->drop_legacy_outcome_unique();
	}

	/**
	 * Drops the pre-P15 unique that ignored the opportunity kind.
	 *
	 * `dbDelta` adds an index and never drops one, so `slot_day_outcome` would
	 * survive the upgrade and keep enforcing one row per outcome per day. The
	 * refresh split then fails on insert: a placement's first refresh of the day
	 * collides with its page opportunity for the same outcome, and the counter
	 * silently stops advancing for whichever arrives second.
	 *
	 * Here as well as in the migration, so a repair install heals a site the
	 * upgrade missed — matching how the rollups and events tables drop their own
	 * superseded uniques.
	 *
	 * @return void
	 */
	private function drop_legacy_outcome_unique(): void {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names = is_array( $rows ) ? array_values( array_unique( array_column( $rows, 'Key_name' ) ) ) : array();

		if ( in_array( 'slot_day_outcome', $names, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the pre-P15 unique so a refresh can hold its own row.
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX slot_day_outcome" );
		}
	}

	/**
	 * Whether the decision rollups table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; caching existence is how a failed install looks healthy.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drops the decision rollups table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Adds one request's counts, in a single statement.
	 *
	 * **One statement regardless of how many slots a page declared.** This runs
	 * on the public fill path, so its cost may not grow with what the page asks
	 * for; a page with six slots and three reasons is still one round trip.
	 *
	 * **The increment is the database's, not PHP's.** `ON DUPLICATE KEY UPDATE
	 * events = events + VALUES(events)` is atomic against the unique key, which
	 * is the property the option this replaced could not have: two overlapping
	 * decisions there read the same array and one write won, so counts were
	 * silently low under exactly the traffic that made them worth reading.
	 *
	 * Unstorable outcomes are dropped rather than written, so cardinality stays
	 * bounded by `Decision_Outcome` and no caller can grow the table by
	 * inventing a code.
	 *
	 * @param string             $day_utc    UTC day, `Y-m-d`.
	 * @param int                $placement  Placement post id.
	 * @param array<string, int> $increments Outcome code => count.
	 * @return bool Whether a write was attempted and accepted.
	 */
	public function add( string $day_utc, int $placement, array $increments ): bool {
		global $wpdb;

		if ( $placement <= 0 || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) ) {
			return false;
		}

		$values       = array();
		$placeholders = array();

		foreach ( $increments as $outcome => $count ) {
			if ( ! is_string( $outcome ) || ! Decision_Outcome::is_storable( $outcome ) ) {
				continue;
			}

			$amount = (int) $count;

			if ( $amount <= 0 ) {
				continue;
			}

			$placeholders[] = '(%s, %d, %s, %d)';

			array_push( $values, $day_utc, $placement, $outcome, $amount );
		}

		if ( array() === $placeholders ) {
			return false;
		}

		$table = $this->table_name();

		$sql = "INSERT INTO {$table} (day_utc, placement_id, outcome, events) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE events = events + VALUES(events)';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; the placeholder list is built from a domain-bounded allowlist, never from input, and every value goes through prepare().
		$written = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return false !== $written;
	}

	/**
	 * Outcome totals for one placement across a closed day range.
	 *
	 * Bounded by the unique key's leading column and the day, so the read is an
	 * index range rather than work proportional to the table.
	 *
	 * @param int    $placement Placement post id.
	 * @param string $from_utc  First UTC day, inclusive, `Y-m-d`.
	 * @param string $to_utc    Last UTC day, inclusive, `Y-m-d`.
	 * @return array<string, int> Outcome code => total, highest first.
	 */
	public function totals_for_placement( int $placement, string $from_utc, string $to_utc ): array {
		global $wpdb;

		if ( $placement <= 0 || ! self::is_day( $from_utc ) || ! self::is_day( $to_utc ) ) {
			return array();
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, prefix-derived name; every value is a placeholder. A cached count is how a stale diagnostic looks healthy.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT outcome, SUM(events) AS total FROM {$table} WHERE placement_id = %d AND day_utc BETWEEN %s AND %s GROUP BY outcome", $placement, $from_utc, $to_utc ), ARRAY_A );

		return self::as_totals( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Per-day outcome rows for a range, site-wide or for one placement.
	 *
	 * The shape an export wants and a screen does not: long format, one row per
	 * day and outcome, so a reader can pivot it without the column set changing
	 * whenever a new reason first occurs.
	 *
	 * Both variants stay inside an index range. Filtered by placement the
	 * unique key leads on `placement_id`; site-wide, `day_outcome` leads on the
	 * day the range bounds. Neither grows with the table — only with the window
	 * asked for, which is what makes an export safe to offer at all.
	 *
	 * @param string $from_utc  First UTC day, inclusive, `Y-m-d`.
	 * @param string $to_utc    Last UTC day, inclusive, `Y-m-d`.
	 * @param int    $placement Placement post id, or 0 for every placement.
	 * @return list<array{day: string, placement_id: int, outcome: string, events: int}>
	 */
	public function daily_outcomes( string $from_utc, string $to_utc, int $placement = 0 ): array {
		global $wpdb;

		if ( ! self::is_day( $from_utc ) || ! self::is_day( $to_utc ) ) {
			return array();
		}

		$table = $this->table_name();

		if ( $placement > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, prefix-derived name; every value is a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day_utc, placement_id, outcome, SUM(events) AS events FROM {$table} WHERE placement_id = %d AND day_utc BETWEEN %s AND %s GROUP BY day_utc, placement_id, outcome ORDER BY day_utc ASC, outcome ASC", $placement, $from_utc, $to_utc ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, prefix-derived name; every value is a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day_utc, placement_id, outcome, SUM(events) AS events FROM {$table} WHERE day_utc BETWEEN %s AND %s GROUP BY day_utc, placement_id, outcome ORDER BY day_utc ASC, placement_id ASC, outcome ASC", $from_utc, $to_utc ), ARRAY_A );
		}

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'day'          => (string) ( $row['day_utc'] ?? '' ),
				'placement_id' => (int) ( $row['placement_id'] ?? 0 ),
				'outcome'      => (string) ( $row['outcome'] ?? '' ),
				'events'       => (int) ( $row['events'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Outcome totals across every placement for a closed day range.
	 *
	 * @param string $from_utc First UTC day, inclusive, `Y-m-d`.
	 * @param string $to_utc   Last UTC day, inclusive, `Y-m-d`.
	 * @return array<string, int> Outcome code => total, highest first.
	 */
	public function totals( string $from_utc, string $to_utc ): array {
		global $wpdb;

		if ( ! self::is_day( $from_utc ) || ! self::is_day( $to_utc ) ) {
			return array();
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, prefix-derived name; every value is a placeholder. A cached count is how a stale diagnostic looks healthy.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT outcome, SUM(events) AS total FROM {$table} WHERE day_utc BETWEEN %s AND %s GROUP BY outcome", $from_utc, $to_utc ), ARRAY_A );

		return self::as_totals( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Deletes counters for days on or before a UTC day, in one bounded batch.
	 *
	 * Bounded rather than unlimited for the reason every purge here is: an
	 * unbounded delete on a table this size is a lock somebody notices.
	 *
	 * @param string $through_utc Last UTC day to delete, inclusive.
	 * @param int    $limit       Maximum rows to delete.
	 * @return int Rows deleted.
	 */
	public function purge_through( string $through_utc, int $limit ): int {
		global $wpdb;

		if ( ! self::is_day( $through_utc ) || $limit <= 0 ) {
			return 0;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, prefix-derived name, prepared values.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day_utc <= %s LIMIT %d", $through_utc, $limit ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Shapes grouped rows into a sorted total map.
	 *
	 * Sorted here rather than by every reader, because every reader wants the
	 * same thing first: the outcome to look at.
	 *
	 * **Ties break on the outcome name, not on whatever order the database
	 * grouped them in.** `arsort` alone leaves equal counts in result order,
	 * which MySQL does not promise and which therefore reshuffles between
	 * refreshes — on a diagnostic somebody is comparing to the last time they
	 * looked, that reads as the numbers having changed when they have not.
	 *
	 * @param array<int, array<string, mixed>> $rows Grouped rows.
	 * @return array<string, int>
	 */
	private static function as_totals( array $rows ): array {
		$totals = array();

		foreach ( $rows as $row ) {
			$outcome = (string) ( $row['outcome'] ?? '' );

			if ( '' === $outcome ) {
				continue;
			}

			$totals[ $outcome ] = (int) ( $row['total'] ?? 0 );
		}

		uksort(
			$totals,
			static function ( string $a, string $b ) use ( $totals ): int {
				$by_count = $totals[ $b ] <=> $totals[ $a ];

				return 0 !== $by_count ? $by_count : strcmp( $a, $b );
			}
		);

		return $totals;
	}

	/**
	 * Whether a string is a `Y-m-d` UTC day.
	 *
	 * @param string $day Candidate day.
	 */
	private static function is_day( string $day ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day );
	}
}
