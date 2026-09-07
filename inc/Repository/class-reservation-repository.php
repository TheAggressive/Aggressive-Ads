<?php
/**
 * Time-bounded claims against a placement's forecast supply.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Reservation_Rules;
use Aggressive\Ads\Domain\Utc_Day;
use Aggressive\Ads\Install\Planning_Schema;
use Aggressive\Ads\Install\Schema;

/**
 * Books inventory, gives it back, and answers what is left.
 *
 * **The check and the write happen under an advisory lock, and that is the
 * declared consistency model.** The contract requires reservation checks to be
 * atomic and the tolerated oversell bound to be measured rather than assumed;
 * a lock makes the bound zero by construction, which is a stronger claim than
 * any reasoning about isolation levels and is checkable by reading one method.
 *
 * The obvious alternative — `INSERT … SELECT` with the capacity sum in a
 * `WHERE` — reads as atomic and is not: under `REPEATABLE READ` both of two
 * concurrent bookings see the same snapshot, both find room, and both insert.
 * The oversell is silent, appears only under load, and is exactly the failure
 * this phase exists to prevent.
 *
 * The cost is serialising bookings per placement and window. Reservations are
 * made by people negotiating a campaign, not on the fill path, so that costs
 * nothing at the rate they actually happen — which is why the same primitive
 * would be the wrong choice for delivery and is the right one here.
 * `Repository\Rate_Limit_Repository` and `Campaign_Repository` use it for the
 * same reason.
 */
final class Reservation_Repository {

	/** Rows one listing returns. */
	public const MAX_ROWS = 100;

	/** Seconds a booking will wait for another booking to finish. */
	private const LOCK_TIMEOUT = 3;

	/** Fully prefixed table name. */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::RESERVATIONS_TABLE;
	}

	/** Creates or upgrades the table. */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Planning_Schema::reservations_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
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
	 * Opportunities already spoken for in one window.
	 *
	 * Sums only the statuses `Domain\Reservation_Rules` says consume capacity,
	 * asked rather than restated — a second list here would be a second chance
	 * to disagree about whether a hold counts, and the disagreement would
	 * surface as a placement that oversells or one that refuses everything.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $opportunity `Domain\Opportunity` kind.
	 * @param string $from_utc    First day of the window, `Y-m-d`.
	 * @param string $to_utc      Last day of the window, `Y-m-d`.
	 * @return int
	 */
	public function committed( int $placement, string $opportunity, string $from_utc, string $to_utc ): int {
		global $wpdb;

		if ( $placement <= 0 || ! Opportunity::is_valid( $opportunity ) ) {
			return 0;
		}

		if ( ! Utc_Day::is_window( $from_utc, $to_utc ) ) {
			return 0;
		}

		$table        = $this->table_name();
		$consuming    = Reservation_Rules::consuming();
		$placeholders = implode( ',', array_fill( 0, count( $consuming ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Prefix-derived table. The status placeholders are generated from `Reservation_Rules::consuming()`, a closed domain vocabulary, so their count is not a literal the sniff can read; `wpdb::prepare()` accepts the single array form and every value in it is bound.
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity), 0) FROM {$table} WHERE placement_id = %d AND opportunity = %s AND window_start = %s AND window_end = %s AND status IN ({$placeholders})", array_merge( array( $placement, $opportunity, $from_utc, $to_utc ), $consuming ) ) );

		return (int) $total;
	}

	/**
	 * Claims inventory if the window has room, atomically.
	 *
	 * **The capacity check and the insert are one critical section.** Two staff
	 * booking the last thousand opportunities at the same moment would both
	 * pass a check made outside a lock, and the publisher would learn about it
	 * when the window failed to deliver.
	 *
	 * `$capacity` is supplied rather than read here, because what a placement
	 * can supply is a forecast and forecasting is not this class's job. That
	 * also lets a caller pass a deliberately raised figure when staff override
	 * an oversell warning — the override is recorded by naming the forecast
	 * version this claim was checked against.
	 *
	 * @param array{placement: int, opportunity: string, from: string, to: string, campaign: int, org: int, quantity: int, capacity: int, forecast_version: int} $claim What to book.
	 * @return array{id: int, refused: string} Row id, or 0 with a reason.
	 */
	public function claim( array $claim ): array {
		global $wpdb;

		$refusal = $this->unbookable( $claim );

		if ( '' !== $refusal ) {
			return array(
				'id'      => 0,
				'refused' => $refusal,
			);
		}

		$lock = $this->lock_name( $claim );

		if ( ! $this->acquire( $lock ) ) {
			return array(
				'id'      => 0,
				'refused' => 'busy',
			);
		}

		try {
			$taken = $this->committed( $claim['placement'], $claim['opportunity'], $claim['from'], $claim['to'] );

			if ( $taken + $claim['quantity'] > $claim['capacity'] ) {
				return array(
					'id'      => 0,
					'refused' => 'capacity',
				);
			}

			$now = gmdate( 'Y-m-d H:i:s' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the surrounding advisory lock is the concurrency control.
			$written = $wpdb->insert(
				$this->table_name(),
				array(
					'placement_id'     => $claim['placement'],
					'opportunity'      => $claim['opportunity'],
					'window_start'     => $claim['from'],
					'window_end'       => $claim['to'],
					'campaign_id'      => $claim['campaign'],
					'org_id'           => $claim['org'],
					'quantity'         => $claim['quantity'],
					'status'           => Reservation_Rules::HELD,
					'forecast_version' => max( 0, $claim['forecast_version'] ),
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
			);

			return array(
				'id'      => false === $written ? 0 : (int) $wpdb->insert_id,
				'refused' => false === $written ? 'write' : '',
			);
		} finally {
			$this->release( $lock );
		}
	}

	/**
	 * Moves one reservation to another status.
	 *
	 * The current status is part of the `WHERE`, so the transition is checked
	 * and applied in one statement. Reading the status, deciding, and then
	 * writing would let two requests both see `held` and both act on it —
	 * releasing a reservation somebody else had just confirmed.
	 *
	 * @param int    $id   Reservation row id.
	 * @param string $from Status it must currently hold.
	 * @param string $to   Status to move it to.
	 * @return bool Whether it moved.
	 */
	public function move( int $id, string $from, string $to ): bool {
		global $wpdb;

		if ( $id <= 0 || ! Reservation_Rules::may_move( $from, $to ) ) {
			return false;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder. The status predicate is what makes the move atomic.
		$moved = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s", $to, gmdate( 'Y-m-d H:i:s' ), $id, $from ) );

		return is_int( $moved ) && $moved > 0;
	}

	/**
	 * Expires held reservations whose window has passed.
	 *
	 * Only holds. A confirmed reservation whose window ended is a delivered
	 * booking, not an abandoned one, and expiring it would rewrite history into
	 * something that looks like a claim nobody honoured.
	 *
	 * @param string $through_utc Last day that counts as passed, `Y-m-d`.
	 * @param int    $limit       Rows in one pass.
	 * @return int Rows expired.
	 */
	public function expire_through( string $through_utc, int $limit ): int {
		global $wpdb;

		if ( ! Utc_Day::is_day( $through_utc ) || $limit <= 0 ) {
			return 0;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder.
		$expired = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s AND window_end < %s LIMIT %d", Reservation_Rules::EXPIRED, gmdate( 'Y-m-d H:i:s' ), Reservation_Rules::HELD, $through_utc, min( self::MAX_ROWS, $limit ) ) );

		return is_int( $expired ) ? $expired : 0;
	}

	/**
	 * One reservation, or null.
	 *
	 * @param int $id Reservation row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; the id is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Reservations belonging to one organization, newest window first.
	 *
	 * @param int $org_id Organization post id.
	 * @param int $limit  Rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_org( int $org_id, int $limit = self::MAX_ROWS ): array {
		global $wpdb;

		if ( $org_id <= 0 || $limit <= 0 ) {
			return array();
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix-derived table; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE org_id = %d ORDER BY window_end DESC, id DESC LIMIT %d", $org_id, min( self::MAX_ROWS, $limit ) ), ARRAY_A );

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$out[] = self::shape( $row );
			}
		}

		return $out;
	}

	/**
	 * Why a claim cannot be booked at all, or '' when it can.
	 *
	 * Checked before the lock is taken, so a malformed claim never serialises
	 * anybody else's booking behind it.
	 *
	 * @param array<string, mixed> $claim Candidate claim.
	 */
	private function unbookable( array $claim ): string {
		if ( (int) ( $claim['placement'] ?? 0 ) <= 0 || ! Opportunity::is_valid( (string) ( $claim['opportunity'] ?? '' ) ) ) {
			return 'unknown_placement';
		}

		if ( ! Utc_Day::is_window( (string) ( $claim['from'] ?? '' ), (string) ( $claim['to'] ?? '' ) ) ) {
			return 'window';
		}

		if ( ! Reservation_Rules::is_quantity( (int) ( $claim['quantity'] ?? 0 ) ) ) {
			return 'quantity';
		}

		if ( (int) ( $claim['capacity'] ?? 0 ) < 0 ) {
			return 'capacity';
		}

		return '';
	}

	/**
	 * The advisory lock guarding one placement and window.
	 *
	 * Scoped to the window rather than the placement, so two campaigns booking
	 * different months of the same slot do not wait on each other — and to the
	 * blog, because a multisite network's tenants share a MySQL server and an
	 * unscoped name would let one site's booking block another's.
	 *
	 * @param array<string, mixed> $claim Candidate claim.
	 */
	private function lock_name( array $claim ): string {
		return 'aggr_reserve_' . get_current_blog_id() . '_' . (int) $claim['placement'] . '_' . (string) $claim['opportunity'] . '_' . (string) $claim['from'] . '_' . (string) $claim['to'];
	}

	/**
	 * Takes the advisory lock, waiting briefly.
	 *
	 * @param string $lock Lock name.
	 */
	private function acquire( string $lock ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The advisory lock is the atomic cross-request serialization primitive.
		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Name and timeout are prepared.
		);
	}

	/**
	 * Releases the advisory lock.
	 *
	 * @param string $lock Lock name.
	 */
	private function release( string $lock ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only the exact advisory lock this request acquired.
		$wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Releases the exact prepared advisory lock.
		);
	}

	/**
	 * One row as typed values.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		return array(
			'id'               => (int) ( $row['id'] ?? 0 ),
			'placement_id'     => (int) ( $row['placement_id'] ?? 0 ),
			'opportunity'      => (string) ( $row['opportunity'] ?? Opportunity::PAGE ),
			'window_start'     => (string) ( $row['window_start'] ?? '' ),
			'window_end'       => (string) ( $row['window_end'] ?? '' ),
			'campaign_id'      => (int) ( $row['campaign_id'] ?? 0 ),
			'org_id'           => (int) ( $row['org_id'] ?? 0 ),
			'quantity'         => (int) ( $row['quantity'] ?? 0 ),
			'status'           => (string) ( $row['status'] ?? Reservation_Rules::HELD ),
			'forecast_version' => (int) ( $row['forecast_version'] ?? 0 ),
			'created_at'       => (string) ( $row['created_at'] ?? '' ),
			'updated_at'       => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
