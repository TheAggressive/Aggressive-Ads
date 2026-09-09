<?php
/**
 * What the creative dimension costs, measured rather than assumed.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Measurement_Event_Type;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

/**
 * P17's first slice adds a creative to the delivery rollup's grain, which
 * multiplies rows by the number of creatives serving a placement. The phase
 * document requires that figure to be a measurement on a seeded fixture rather
 * than a description, because it is the one cost of this phase a reader cannot
 * infer from the code.
 *
 * The breakdown adding up to its total is asserted in the same run. A dimension
 * whose parts do not sum to their whole is the defect P15 shipped and caught,
 * one grain lower — and the sum is what every existing report still reads, so
 * it has to keep being right while the detail becomes available.
 */
final class RollupCreativeGrainTest extends WP_UnitTestCase {

	/**
	 * Projection under test.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * The ledger it is projected from.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	public function set_up(): void {
		parent::set_up();

		$this->rollups = Plugin::instance()->container()->get( Rollup_Repository::class );
		$this->events  = new Event_Repository();

		$this->rollups->install_table();
		$this->events->install_table();
	}

	/**
	 * Rows grow by the number of creatives, and the parts sum to the whole.
	 *
	 * @return void
	 */
	public function test_the_creative_grain_multiplies_rows_by_the_creatives_serving(): void {
		global $wpdb;

		$placement = 11;
		$campaign  = 22;
		$creatives = array( 101, 102, 103, 104 );
		$days      = 7;
		$per_day   = 3;

		$expected_impressions = 0;

		for ( $day = 1; $day <= $days; $day++ ) {
			$timestamp = strtotime( '-' . $day . ' days', strtotime( gmdate( 'Y-m-d' ) . ' 12:00:00 UTC' ) );

			foreach ( $creatives as $creative ) {
				for ( $n = 0; $n < $per_day; $n++ ) {
					$this->insert_at( $placement, $campaign, $creative, $timestamp, "d{$day}-c{$creative}-n{$n}" );

					++$expected_impressions;
				}
			}
		}

		for ( $day = 1; $day <= $days; $day++ ) {
			$this->assertTrue(
				$this->rollups->reconcile_day( gmdate( 'Y-m-d', strtotime( '-' . $day . ' days' ) ) )
			);
		}

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Measuring this plugin's own table.
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Measuring this plugin's own table.
		$distinct_creatives = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT creative_id) FROM {$table}" );

		/*
		 * The measurement. Before the dimension this fixture produced one row
		 * per day per line item — seven. It now produces one per creative per
		 * day, so the multiplier is exactly the number of creatives serving the
		 * placement and nothing else.
		 */
		$this->assertSame( count( $creatives ), $distinct_creatives );
		$this->assertSame( $days * count( $creatives ), $rows, 'Row growth is not the creative count.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Measuring this plugin's own table.
		$summed = (int) $wpdb->get_var( "SELECT COALESCE(SUM(impressions), 0) FROM {$table}" );

		$this->assertSame(
			$expected_impressions,
			$summed,
			'The per-creative rows do not sum to what the ledger recorded, so the breakdown and the total disagree.'
		);

		$totals = $this->rollups->totals_for_campaigns( array( $campaign ) );

		$this->assertSame(
			$expected_impressions,
			(int) $totals[ $campaign ]['impressions'],
			'The campaign total changed when the grain did, so every existing report just moved.'
		);
	}

	/**
	 * Reconciling twice does not multiply the rows it just created.
	 *
	 * The finer grain makes this worth asserting on its own: the repair now
	 * removes rows it supersedes before inserting, and a delete scoped one
	 * notch too wide would drop a creative on every second run.
	 *
	 * @return void
	 */
	public function test_reconciling_twice_leaves_the_same_rows(): void {
		global $wpdb;

		$timestamp = strtotime( '-1 day', strtotime( gmdate( 'Y-m-d' ) . ' 12:00:00 UTC' ) );
		$day       = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		foreach ( array( 201, 202 ) as $creative ) {
			$this->insert_at( 31, 32, $creative, $timestamp, "twice-{$creative}" );
		}

		$table = $this->rollups->table_name();

		$this->assertTrue( $this->rollups->reconcile_day( $day ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Measuring this plugin's own table.
		$first = $wpdb->get_results( "SELECT creative_id, impressions FROM {$table} ORDER BY creative_id ASC", ARRAY_A );

		$this->assertCount( 2, $first );

		$this->assertTrue( $this->rollups->reconcile_day( $day ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Measuring this plugin's own table.
		$second = $wpdb->get_results( "SELECT creative_id, impressions FROM {$table} ORDER BY creative_id ASC", ARRAY_A );

		$this->assertSame( $first, $second );
	}

	/**
	 * An unattributed row is superseded rather than added to.
	 *
	 * A counter written before the dimension existed sits at `creative_id = 0`.
	 * Once the ledger can name the creative, that row is a duplicate of the
	 * same impressions under a different key — and `ON DUPLICATE KEY UPDATE`
	 * cannot correct it, because it is no longer the row the projection lands
	 * on. Left alone the day is counted twice.
	 *
	 * @return void
	 */
	public function test_a_pre_dimension_row_does_not_double_the_day(): void {
		global $wpdb;

		$timestamp = strtotime( '-1 day', strtotime( gmdate( 'Y-m-d' ) . ' 12:00:00 UTC' ) );
		$day       = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$this->insert_at( 41, 42, 301, $timestamp, 'pre-a' );
		$this->insert_at( 41, 42, 301, $timestamp, 'pre-b' );

		// The shape an older release left behind: real counters, no creative.
		$this->assertTrue( $this->rollups->increment( 'impressions', 41, 42, $day ) );
		$this->assertTrue( $this->rollups->increment( 'impressions', 41, 42, $day ) );
		$this->assertTrue( $this->rollups->increment( 'impressions', 41, 42, $day ) );

		$this->assertTrue( $this->rollups->reconcile_day( $day ) );

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Measuring this plugin's own table.
		$summed = (int) $wpdb->get_var( "SELECT COALESCE(SUM(impressions), 0) FROM {$table}" );

		$this->assertSame( 2, $summed, 'The unattributed row survived beside the attributed ones, so the day counts twice.' );
	}

	/**
	 * Inserts one impression and moves it onto a closed day.
	 *
	 * @param int    $placement Placement id.
	 * @param int    $campaign  Campaign id.
	 * @param int    $creative  Revision id.
	 * @param int    $timestamp Unix time to file it under.
	 * @param string $seed      Anything unique; becomes the token.
	 * @return void
	 */
	private function insert_at( int $placement, int $campaign, int $creative, int $timestamp, string $seed ): void {
		global $wpdb;

		$token = hash( 'sha256', $seed );

		$this->assertTrue(
			$this->events->insert(
				Event_Repository::TYPE_IMPRESSION,
				$placement,
				$campaign,
				$creative,
				$token,
				str_repeat( 'f', 64 )
			),
			'Could not insert event fixture ' . $seed . ': ' . $wpdb->last_error
		);

		$table      = $this->events->table_name();
		$normalized = Measurement_Event_Type::normalize( Event_Repository::TYPE_IMPRESSION ) ?? Event_Repository::TYPE_IMPRESSION;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture moves the repository-written row to a closed UTC day.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at_ts = %d WHERE token_hash = %s AND event = %s", $timestamp, $token, $normalized ) );
	}
}
