<?php
/**
 * Bounded reads of the existing P16 planning facts.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Inventory_Availability;
use Aggressive\Ads\Domain\Reservation_Rules;
use Aggressive\Ads\Domain\Utc_Day;
use Aggressive\Ads\Install\Schema;

/** Private planning numbers stop here; the caller receives only a derived state. */
final class Inventory_Availability_Repository {

	public const MAX_INPUT_ROWS = 4096;

	/**
	 * Assess whether any page opportunities remain; never quote forecast quantities.
	 *
	 * @param array<int, int> $placements Validated package placements; normalized to a unique list here.
	 * @param int             $start Local-flight start as UTC seconds.
	 * @param int             $end Local-flight end as UTC seconds.
	 * @param int             $now Assessment clock.
	 * @return string|\WP_Error
	 */
	public function assess( array $placements, int $start, int $end, int $now ): string|\WP_Error {
		$ids = array_values( array_unique( $placements ) );
		if ( array() === $ids || count( $ids ) > Creative_Repository::MAX_PER_CAMPAIGN || min( $ids ) <= 0 || $start <= 0 || $end < $start ) {
			return $this->error();
		}
		$from = gmdate( 'Y-m-d', $start );
		$to   = gmdate( 'Y-m-d', $end );
		if ( ! Utc_Day::is_window( $from, $to ) ) {
			return $this->error();
		}
		global $wpdb;
		$forecasts = array();
		$source    = new Forecast_Repository();
		foreach ( $ids as $id ) {
			$forecast = $this->forecast( $source, $id, $from, $to );
			if ( is_wp_error( $forecast ) ) {
				return $forecast;
			}
			if ( null !== $forecast ) {
				$forecasts[ $id ] = $forecast;
			}
		}
		$slots    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$statuses = implode( ',', array_fill( 0, count( Reservation_Rules::consuming() ), '%s' ) );
		// Filtering status/end after the inner LIMIT bounds examined inputs, including old or released rows.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated bounded id/status placeholders and an existing index in its declared column order; LIMIT prevents derived-table merging before aggregation.
		$reservations = $this->read(
			$wpdb->prepare(
				"SELECT placement_id,COUNT(*) AS input_rows,
			SUM(CASE WHEN status IN ({$statuses}) AND window_start=%s AND window_end=%s THEN quantity ELSE 0 END) AS committed,
			MAX(CASE WHEN status IN ({$statuses}) AND window_end>=%s AND NOT(window_start=%s AND window_end=%s) THEN 1 ELSE 0 END) AS other_window
			FROM (SELECT placement_id,window_start,window_end,status,quantity FROM %i FORCE INDEX (slot_window_status)
			WHERE placement_id IN ({$slots}) AND opportunity='page' AND window_start<=%s
			ORDER BY placement_id,opportunity,window_start,window_end,status LIMIT %d) request_input GROUP BY placement_id",
				...array_merge( Reservation_Rules::consuming(), array( $from, $to ), Reservation_Rules::consuming(), array( $from, $from, $to, $wpdb->prefix . Schema::RESERVATIONS_TABLE ), $ids, array( $to, self::MAX_INPUT_ROWS + 1 ) )
			)
		);
		// phpcs:enable
		if ( is_wp_error( $reservations ) ) {
			return $reservations;
		}
		if ( array_sum( array_column( $reservations, 'input_rows' ) ) > self::MAX_INPUT_ROWS ) {
			return Inventory_Availability::LIMITED;
		}
		$reservations = array_column( $reservations, null, 'placement_id' );
		$partial_days = '00:00:00' !== gmdate( 'H:i:s', $start ) || '23:59:59' !== gmdate( 'H:i:s', $end );
		$states       = array();
		foreach ( $ids as $id ) {
			$forecast  = $forecasts[ $id ] ?? null;
			$capacity  = null === $forecast ? null : filter_var( $forecast['estimate'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
			$committed = filter_var( $reservations[ $id ]['committed'] ?? 0, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
			if ( false === $capacity || false === $committed ) {
				return $this->error();
			}
			$made      = null === $forecast ? false : strtotime( $forecast['made_at'] . ' UTC' );
			$uncertain = $start <= $now || $partial_days || false === $made || $made < $now - DAY_IN_SECONDS || $made > $now || ! empty( $reservations[ $id ]['other_window'] );
			$states[]  = Inventory_Availability::placement_state( $capacity, $committed, $uncertain );
		}
		return Inventory_Availability::package_state( $states );
	}

	/**
	 * Keep the existing constant-window seek and distinguish failed SQL from absent history.
	 *
	 * @param Forecast_Repository $source Native snapshot reader.
	 * @param int                 $id Validated placement.
	 * @param string              $from First UTC day.
	 * @param string              $to Last UTC day.
	 * @return array<string, mixed>|null|\WP_Error
	 */
	private function forecast( Forecast_Repository $source, int $id, string $from, string $to ): array|null|\WP_Error {
		global $wpdb;
		$row = $source->latest( $id, 'page', $from, $to );
		return '' !== $wpdb->last_error ? $this->error() : $row;
	}

	/**
	 * Distinguish a failed query from an empty planning table.
	 *
	 * @param string $sql Prepared internal SQL.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	private function read( string $sql ): array|\WP_Error {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Internal callers prepare bounded SQL; stale cached availability would conceal a new reservation.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return '' !== $wpdb->last_error ? $this->error() : $rows;
	}

	/** Public errors contain no planning figures or database details. */
	private function error(): \WP_Error {
		return new \WP_Error( 'aggr_inventory_check_failed', __( 'Availability could not be checked. Please try again or ask the publisher.', 'aggressive-ads' ) );
	}
}
