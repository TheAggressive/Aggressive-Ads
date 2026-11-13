<?php
/**
 * Explain the SQL issued by the storefront, including records it must not scan.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Inventory_Availability_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;
use WP_UnitTestCase;

final class InventoryReadScaleTest extends WP_UnitTestCase {

	/**
	 * Selected future UTC window.
	 *
	 * @var string
	 */
	private string $from;
	/**
	 * Last selected UTC day.
	 *
	 * @var string
	 */
	private string $to;

	/** Keep future-window fixtures independent of the year the suite runs. */
	public function set_up(): void {
		parent::set_up();
		$now        = time();
		$this->from = gmdate( 'Y-m-d', $now + 10 * DAY_IN_SECONDS );
		$this->to   = gmdate( 'Y-m-d', $now + 12 * DAY_IN_SECONDS );
	}

	/** An old/released prefix still counts toward input work, rather than hiding an unbounded scan. */
	public function test_reservation_inputs_are_bounded_before_filtering_and_aggregation(): void {
		global $wpdb;
		$id = $this->placement();
		$this->forecast( $id );
		$ids = array( $id );
		for ( $count = 1; $count < 100; ++$count ) {
			$next = $this->placement();
			$this->forecast( $next );
			$ids[] = $next;
		}
		$repo  = new Reservation_Repository();
		$claim = $repo->claim(
			array(
				'placement'        => $id,
				'opportunity'      => 'page',
				'from'             => $this->from,
				'to'               => $this->to,
				'campaign'         => 0,
				'org'              => 12,
				'quantity'         => 1,
				'capacity'         => 100,
				'forecast_version' => 1,
			)
		);
		$this->assertGreaterThan( 0, $claim['id'] );
		for ( $batch = 0; $batch < 12; ++$batch ) {
			$values = array_fill( 0, 1000, "(%d,'page','2020-01-01','2020-01-02',1,'released')" );
			$args   = array_merge( array( $repo->table_name() ), array_fill( 0, 1000, $id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded physical fixture at the production ledger grain, larger than the read guard independently of its constant.
			$this->assertSame( 1000, $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (placement_id,opportunity,window_start,window_end,quantity,status) VALUES ' . implode( ',', $values ), ...$args ) ) );
		}
		$this->assertSame( 'limited', ( new Inventory_Availability_Repository() )->assess( $ids, strtotime( $this->from . ' UTC' ), strtotime( $this->to . ' 23:59:59 UTC' ), time() ) );
		$sql = $wpdb->last_query;
		$this->assertSame( 4097, array_sum( array_map( static fn ( object $row ): int => (int) $row->input_rows, $wpdb->last_result ) ) );
		$this->assertCount( 1, $wpdb->last_result, 'Only an aggregate, never the raw input rows, reaches PHP.' );
		$this->assert_examined( $sql, 4097 );
	}

	/** Latest-version seeks are bounded even with thousands of older snapshots. */
	public function test_latest_forecasts_use_one_index_seek_per_selected_placement(): void {
		global $wpdb;
		$ids = array( $this->placement(), $this->placement() );
		foreach ( $ids as $id ) {
			$this->forecast( $id );
			$values = array();
			$args   = array( ( new Forecast_Repository() )->table_name() );
			for ( $version = 2; $version <= 1001; ++$version ) {
				$values[] = "(%d,'page',%s,%s,%d,100,%s)";
				array_push( $args, $id, $this->from, $this->to, $version, gmdate( 'Y-m-d H:i:s' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Real writer establishes the first snapshot; bounded physical older-version fixture tests the same unique native grain.
			$this->assertSame( 1000, $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (placement_id,opportunity,window_start,window_end,version,estimate,made_at) VALUES ' . implode( ',', $values ), ...$args ) ) );
		}
		$queries = array();
		$capture = static function ( string $query ) use ( &$queries ): string {
			if ( str_starts_with( $query, 'SELECT * FROM' ) && str_contains( $query, 'aggr_forecasts' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture );
		try {
			$this->assertSame( 'available', ( new Inventory_Availability_Repository() )->assess( $ids, strtotime( $this->from . ' UTC' ), strtotime( $this->to . ' 23:59:59 UTC' ), time() ) );
		} finally {
			remove_filter( 'query', $capture );
		}
		$this->assertCount( 2, $queries );
		foreach ( $queries as $sql ) {
			$this->assert_examined( $sql, 1 );
		}
	}

	/**
	 * Actual iterators, including those before LIMIT, must stay within the budget.
	 *
	 * @param string $sql Production SQL captured from the repository.
	 * @param int $limit Maximum rows times loops per iterator.
	 */
	private function assert_examined( string $sql, int $limit ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Explain the exact already-prepared production statement, never a second copy of its SQL.
		$plan = implode( "\n", $wpdb->get_col( 'EXPLAIN ANALYZE ' . $sql ) );
		preg_match_all( '/actual time=[^)]*?rows=([0-9.e+\-]+) loops=([0-9.e+\-]+)/', $plan, $matches, PREG_SET_ORDER );
		$this->assertNotEmpty( $matches, $plan );
		foreach ( $matches as $row ) {
			$this->assertLessThanOrEqual( $limit, (float) $row[1] * (float) $row[2], $plan );
		}
	}

	/** A real placement id, rather than a fabricated result row. */
	private function placement(): int {
		return (int) self::factory()->post->create( array( 'post_type' => Post_Types::PLACEMENT ) );
	}

	/**
	 * Establish the native snapshot through its actual writer.
	 *
	 * @param int $id Placement id.
	 */
	private function forecast( int $id ): void {
		$this->assertGreaterThan(
			0,
			( new Forecast_Repository() )->record(
				$id,
				'page',
				$this->from,
				$this->to,
				array(
					'estimate'      => 100,
					'optimistic'    => 200,
					'confidence'    => 'high',
					'days_observed' => 30,
					'days_forecast' => 3,
				)
			)
		);
	}
}
