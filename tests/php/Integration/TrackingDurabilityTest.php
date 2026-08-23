<?php
/**
 * Event-ledger durability, rollup repair, and bounded retention.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Event_Retention;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_UnitTestCase;

/**
 * Raw events remain authoritative when the reporting projection needs repair.
 */
final class TrackingDurabilityTest extends WP_UnitTestCase {

	/**
	 * Durable ledger.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Reporting projection.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * Closed-day repair workflow.
	 *
	 * @var Rollup_Reconciler
	 */
	private Rollup_Reconciler $reconciler;

	/** Resolves the installed ledger and projection. */
	public function set_up(): void {
		parent::set_up();

		$container        = Plugin::instance()->container();
		$this->events     = $container->get( Event_Repository::class );
		$this->rollups    = $container->get( Rollup_Repository::class );
		$this->reconciler = $container->get( Rollup_Reconciler::class );
		delete_option( Rollup_Reconciler::OPTION );
	}

	/** Removes the reconciliation watermark between tests. */
	public function tear_down(): void {
		delete_option( Rollup_Reconciler::OPTION );
		parent::tear_down();
	}

	/** A closed day is rebuilt exactly and re-running cannot double count it. */
	public function test_closed_day_reconciliation_repairs_the_projection_exactly(): void {
		$day       = gmdate( 'Y-m-d', time() - ( 2 * DAY_IN_SECONDS ) );
		$timestamp = (int) strtotime( $day . ' 12:00:00 UTC' );

		$this->insert_at( Event_Repository::TYPE_IMPRESSION, 11, 22, 33, $timestamp, 'a' );
		$this->insert_at( Event_Repository::TYPE_CLICK, 11, 22, 33, $timestamp, 'b' );
		$this->insert_at( Event_Repository::TYPE_IMPRESSION, 11, 22, 33, $timestamp, 'c' );

		for ( $count = 0; $count < 5; ++$count ) {
			$this->assertTrue( $this->rollups->increment( 'impressions', 11, 22, $day ) );
		}

		$this->assertGreaterThanOrEqual( 1, $this->reconciler->run() );
		$this->assertGreaterThanOrEqual( $day, $this->reconciler->reconciled_through() );

		$totals = $this->rollups->totals_for_campaigns( array( 22 ) );
		$this->assertSame( 2, $totals[22]['impressions'] );
		$this->assertSame( 1, $totals[22]['clicks'] );

		$this->assertTrue( $this->rollups->reconcile_day( $day ) );
		$again = $this->rollups->totals_for_campaigns( array( 22 ) );
		$this->assertSame( $totals, $again );
	}

	/** One retention statement never deletes more than its explicit batch. */
	public function test_event_purge_is_bounded_by_row_count(): void {
		global $wpdb;

		$old = time() - ( 40 * DAY_IN_SECONDS );

		for ( $index = 0; $index < 25; ++$index ) {
			$this->insert_at( Event_Repository::TYPE_IMPRESSION, 1, 2, 3, $old, dechex( $index + 1 ) );
		}

		$this->assertSame( 10, $this->events->purge_before( time() - ( 30 * DAY_IN_SECONDS ), 10 ) );

		$table = $this->events->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration assertion against this plugin's ledger.
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 15, $remaining );
	}

	/** Repository diagnostics never alter the caller's database error policy. */
	public function test_event_writes_and_replay_checks_restore_database_error_suppression(): void {
		global $wpdb;

		$token = hash( 'sha256', 'suppression-state' );
		$ip    = str_repeat( 'e', 64 );
		$prior = $wpdb->suppress_errors( true );

		try {
			$this->assertTrue( $this->events->insert( Event_Repository::TYPE_IMPRESSION, 51, 52, 53, $token, $ip ) );
			$this->assertTrue( $wpdb->suppress_errors, 'A successful insert changed the caller\'s suppression state.' );
			$this->assertTrue( $this->events->exists( Event_Repository::TYPE_IMPRESSION, $token ) );
			$this->assertTrue( $wpdb->suppress_errors, 'A replay check changed the caller\'s suppression state.' );

			$wpdb->suppress_errors( false );

			$this->assertFalse( $this->events->insert( Event_Repository::TYPE_IMPRESSION, 51, 52, 53, $token, $ip ) );
			$this->assertFalse( $wpdb->suppress_errors, 'A duplicate insert changed the caller\'s suppression state.' );
			$this->assertTrue( $this->events->exists( Event_Repository::TYPE_IMPRESSION, $token ) );
			$this->assertFalse( $wpdb->suppress_errors, 'A replay check enabled suppression for its caller.' );
		} finally {
			$wpdb->suppress_errors( $prior );
		}
	}

	/** Retention cannot overtake the last day known to match the ledger. */
	public function test_retention_waits_for_the_reconciliation_watermark(): void {
		global $wpdb;

		$timestamp = time() - ( 120 * DAY_IN_SECONDS );
		$seed      = 'retention-watermark';
		$token     = hash( 'sha256', $seed );
		$this->insert_at( Event_Repository::TYPE_IMPRESSION, 41, 42, 43, $timestamp, $seed );

		update_option( Rollup_Reconciler::OPTION, gmdate( 'Y-m-d', time() - ( 150 * DAY_IN_SECONDS ) ), false );
		Plugin::instance()->container()->get( Event_Retention::class )->run();

		$table = $this->events->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact integration assertion for the watermark fixture.
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE token_hash = %s", $token ) ) );

		$this->assertTrue( $this->rollups->reconcile_day( gmdate( 'Y-m-d', $timestamp ) ) );
		update_option( Rollup_Reconciler::OPTION, gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), false );
		Plugin::instance()->container()->get( Event_Retention::class )->run();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact integration assertion for the watermark fixture.
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE token_hash = %s", $token ) ) );
	}

	/** Projection and retention schedules are both wired and hourly. */
	public function test_tracking_maintenance_jobs_are_registered(): void {
		$this->reconciler->ensure_scheduled();
		$retention = Plugin::instance()->container()->get( Event_Retention::class );
		$retention->ensure_scheduled();

		$this->assertNotFalse( has_action( Rollup_Reconciler::HOOK, array( $this->reconciler, 'run_scheduled' ) ) );
		$this->assertSame( Rollup_Reconciler::RECURRENCE, wp_get_schedule( Rollup_Reconciler::HOOK ) );
		$this->assertSame( Event_Retention::RECURRENCE, wp_get_schedule( Event_Retention::HOOK ) );
	}

	/**
	 * Inserts a valid event and moves it to an explicit test timestamp.
	 *
	 * @param string $type      Event type.
	 * @param int    $placement Placement id.
	 * @param int    $campaign  Campaign id.
	 * @param int    $creative  Creative id.
	 * @param int    $timestamp UTC Unix timestamp.
	 * @param string $seed      Unique hexadecimal seed.
	 */
	private function insert_at( string $type, int $placement, int $campaign, int $creative, int $timestamp, string $seed ): void {
		global $wpdb;

		$token = hash( 'sha256', $seed );
		$ip    = str_repeat( 'f', 64 );

		$this->assertTrue(
			$this->events->insert( $type, $placement, $campaign, $creative, $token, $ip ),
			'Could not insert event fixture ' . $seed . ': ' . $wpdb->last_error
		);

		$table = $this->events->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture moves the repository-written row to a closed UTC day.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at_ts = %d WHERE token_hash = %s AND event = %s", $timestamp, $token, $type ) );
	}
}
