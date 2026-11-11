<?php
/**
 * Reporting buckets preserve ledger time, frozen tenancy and restartable repair.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Conversion_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Report_Bucket_Repository;
use Aggressive\Ads\Repository\Rollup_Report_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

final class ReportBucketProjectionTest extends WP_UnitTestCase {

	/**
	 * Cache mode to restore after direct projection assertions.
	 *
	 * @var bool
	 */
	private bool $external_cache = false;

	/** The tables exist before each test opens its transaction. */
	public function set_up(): void {
		( new Rollup_Repository() )->install_table();
		parent::set_up();
		$this->external_cache = true === wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );
		foreach ( array( Report_Bucket_Repository::OPTION_SINCE, Report_Bucket_Repository::OPTION_NEXT, Report_Bucket_Repository::OPTION_UNTIL ) as $option ) {
			delete_option( $option );
		}
	}

	/** Repairs are asserted against the database, not cached pre-repair totals. */
	public function tear_down(): void {
		wp_using_ext_object_cache( $this->external_cache );
		parent::tear_down();
	}

	/** A retained event on a chosen timestamp, not an increment that supplies its own answer. */
	private function event( string $at, int $campaign = 120 ): void {
		$this->assertTrue( ( new Event_Repository() )->insert( 'served', 12, $campaign, 0, hash( 'sha256', $at . $campaign ), hash( 'sha256', 'visitor' ), strtotime( $at . ' UTC' ) ) );
	}

	/** Make the repair fixture empty despite WordPress's temporary-table rewrites. */
	private function empty_projection( Report_Bucket_Repository $buckets ): void {
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			$buckets->drop_table();
			$this->assertFalse( $buckets->table_exists() );
			$buckets->install_table();
		} finally {
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		}
	}

	/** Both sides of midnight must survive a rebuild, including historical ownership. */
	public function test_reconciliation_is_exact_idempotent_and_keeps_frozen_ownership(): void {
		$daily   = new Rollup_Repository();
		$buckets = new Report_Bucket_Repository();
		$reports = new Rollup_Report_Repository();
		$start   = strtotime( '2026-08-12 00:00:00 UTC' );
		foreach ( array( '18:29:59', '18:30:00', '18:59:59' ) as $time ) {
			$this->event( '2026-08-12 ' . $time );
			$this->assertTrue( $daily->increment( 'impressions', 12, 120, '', 77, 1200, 0, strtotime( '2026-08-12 ' . $time . ' UTC' ) ) );
		}
		update_post_meta( 120, Campaign_Repository::META_ORG_ID, 9990 );
		$this->empty_projection( $buckets );

		$this->assertSame( 0, $reports->totals_for_org( 1200, Report_Period::between( '2026-08-13', '2026-08-13', 'Asia/Kolkata' ) )['impressions'], 'The supposedly empty fixture retained the old projection.' );
		$this->assertTrue( $buckets->reconcile_span( $start, $start + DAY_IN_SECONDS ) );
		$this->assertTrue( $buckets->reconcile_span( $start, $start + DAY_IN_SECONDS ) );
		$local = Report_Period::between( '2026-08-13', '2026-08-13', 'Asia/Kolkata' );
		$this->assertSame( 2, $reports->totals_for_org( 1200, $local )['impressions'] );
		$this->assertSame( 0, $reports->totals_for_org( 9990, $local )['impressions'] );
		$this->assertSame( 3, $reports->totals_for_org( 1200, Report_Period::between( '2026-08-12', '2026-08-12' ) )['impressions'], 'A reporting rebuild changed daily pacing/history.' );
	}

	/** A delayed conversion belongs at its outcome time, and conversion-only repair keeps its org. */
	public function test_conversion_only_buckets_are_attributed_at_outcome_time(): void {
		$at         = strtotime( '2026-08-12 18:30:00 UTC' );
		$conversion = array(
			'definition_id'    => 1,
			'idempotency_key'  => hash( 'sha256', 'conversion' ),
			'placement_id'     => 12,
			'campaign_id'      => 120,
			'creative_id'      => 0,
			'line_item_id'     => 0,
			'token_hash'       => hash( 'sha256', 'fill' ),
			'attributed_event' => 'click',
			'occurred_at_ts'   => $at,
			'currency'         => 'USD',
			'source'           => 'browser',
		);
		$this->assertTrue( ( new Conversion_Repository() )->insert( $conversion ) );
		$this->assertTrue( ( new Rollup_Repository() )->increment( 'conversions', 12, 120, '', 0, 1200, 0, $at ) );
		$reports = new Rollup_Report_Repository();
		$local   = Report_Period::between( '2026-08-13', '2026-08-13', 'Asia/Kolkata' );
		$this->assertSame( 1, $reports->totals_for_org( 1200, $local )['conversions'] );
		$buckets = new Report_Bucket_Repository();
		$this->empty_projection( $buckets );
		$start = strtotime( '2026-08-12 00:00:00 UTC' );
		$this->assertNull( $reports->totals_for_org( 1200, $local )['conversions'] );
		$this->assertTrue( $buckets->reconcile_span( $start, $start + DAY_IN_SECONDS ) );
		$this->assertSame( 1, $reports->totals_for_org( 1200, $local )['conversions'] );
		$this->assertNull( $reports->totals_for_org( 1200, $local )['viewables'] );
	}

	/** Upgrade requests do no historical aggregation; retries resume at the last completed day. */
	public function test_backfill_is_bounded_resumable_and_preserves_utc_history(): void {
		$this->event( '2026-08-01 18:30:00' );
		$this->event( '2026-08-12 18:30:00' );
		update_post_meta( 120, Campaign_Repository::META_ORG_ID, 1200 );
		$buckets = new Report_Bucket_Repository();
		$buckets->begin_backfill();
		$this->assertSame( 0, ( new Rollup_Report_Repository() )->totals_for_org( 1200, Report_Period::between( '2026-08-13', '2026-08-13', 'Asia/Kolkata' ) )['impressions'] );
		$this->assertSame( 7, $buckets->backfill_from_events( strtotime( '2026-08-14 00:00:00 UTC' ) ) );
		$this->assertSame( strtotime( '2026-08-08 UTC' ), (int) get_option( Report_Bucket_Repository::OPTION_NEXT ) );
		$this->assertSame( 6, $buckets->backfill_from_events( strtotime( '2026-08-14 00:00:00 UTC' ) ) );
		$this->assertSame( 1, ( new Rollup_Report_Repository() )->totals_for_org( 1200, Report_Period::between( '2026-08-13', '2026-08-13', 'Asia/Kolkata' ) )['impressions'] );
	}
	/** Retention cannot erase a ledger day that its new projection still needs. */
	public function test_retention_waits_for_the_reporting_backfill(): void {
		$old     = gmdate( 'Y-m-d', time() - 140 * DAY_IN_SECONDS );
		$pending = gmdate( 'Y-m-d', time() - 120 * DAY_IN_SECONDS ) . ' 12:00:00';
		$this->event( $old . ' 12:00:00' );
		$this->event( $pending );
		$buckets = new Report_Bucket_Repository();
		$buckets->begin_backfill();
		update_option( \Aggressive\Ads\Workflow\Rollup_Reconciler::OPTION, gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), false );
		\Aggressive\Ads\Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Event_Retention::class )->run();
		$this->assertTrue( ( new Event_Repository() )->exists( 'served', hash( 'sha256', $pending . 120 ) ), 'Retention overtook a pending historical report.' );
		$this->assertFalse( ( new Event_Repository() )->exists( 'served', hash( 'sha256', $old . ' 12:00:00' . 120 ) ), 'Retention was entirely disabled rather than bounded by the cursor.' );
	}
}
