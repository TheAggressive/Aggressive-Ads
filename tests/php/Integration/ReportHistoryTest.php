<?php
/**
 * Local report boundaries and missing-history disclosure are shared by UI and export.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Portal\Report_Actions;
use Aggressive\Ads\Repository\Report_Bucket_Repository;
use Aggressive\Ads\Workflow\Reporting_Read;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_UnitTestCase;

final class ReportHistoryTest extends WP_UnitTestCase {

	/** Each request uses the site's current timezone unless UTC was explicitly selected. */
	public function test_site_days_are_the_default_and_utc_is_preserved_for_export(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve the test request while exercising read-only date filters.
		$before        = $_GET;
		$original_zone = get_option( 'timezone_string', '' );
		try {
			update_option( 'timezone_string', 'Asia/Kathmandu' );
			$_GET     = array(
				'from' => '2026-08-01',
				'to'   => '2026-08-07',
			);
			$delivery = Plugin::instance()->container()->get( Delivery_View_Data::class );
			$this->assertSame( 'Asia/Kathmandu', $delivery->period()->timezone );
			$this->assertSame( 'Asia/Kathmandu', $delivery->export_period()->timezone );
			$this->assertStringContainsString( 'Asia/Kathmandu', $delivery->range_label() );
			$_GET['tz'] = 'utc';
			$this->assertSame( 'UTC', $delivery->period()->timezone );
			$this->assertSame( 'UTC', $delivery->export_period()->timezone );
			$this->assertStringContainsString( '(UTC)', $delivery->range_label() );
		} finally {
			$_GET = $before;
			update_option( 'timezone_string', $original_zone );
		}
	}

	/** Partial retained history cannot be described as a fully reconstructed local report. */
	public function test_portal_and_csv_disclose_unrecoverable_history(): void {
		$container = Plugin::instance()->container();
		$read      = $container->get( Reporting_Read::class );
		$local     = Report_Period::between( '2026-08-01', '2026-08-07', 'Asia/Kolkata' );
		update_option( Report_Bucket_Repository::OPTION_SINCE, strtotime( '2026-08-04 12:30:00 UTC' ), false );
		delete_option( Report_Bucket_Repository::OPTION_NEXT );
		update_option( Rollup_Reconciler::OPTION, '2026-08-20', false );
		$this->assertFalse( $read->history_complete( $local ) );
		$note = $read->history_note( $local );
		$this->assertStringContainsString( '2026-08-04 12:30', $note );
		$this->assertStringContainsString( 'cannot be reconstructed', $note );
		$this->assertSame( $note, $container->get( Delivery_View_Data::class )->freshness_note( $local ) );
		$csv = $container->get( Report_Actions::class )->document( array(), $local->timezone, $note );
		$this->assertStringContainsString( 'Asia/Kolkata', $csv );
		$this->assertStringContainsString( 'cannot be reconstructed', $csv );
		$this->assertSame( '', $read->history_note( Report_Period::between( '2026-08-01', '2026-08-07' ) ) );
		$this->assertTrue( $read->history_complete( Report_Period::between( '2026-08-05', '2026-08-07', 'Asia/Kolkata' ) ) );
	}

	/** A daily watermark alone does not make a pending local backfill complete. */
	public function test_pending_backfill_has_a_distinct_explanation(): void {
		$read = Plugin::instance()->container()->get( Reporting_Read::class );
		update_option( Report_Bucket_Repository::OPTION_NEXT, strtotime( '2026-08-01 UTC' ), false );
		$period = Report_Period::between( '2026-08-05', '2026-08-07', 'Asia/Kolkata' );
		$this->assertFalse( $read->history_complete( $period ) );
		$this->assertStringContainsString( 'being rebuilt', $read->history_note( $period ) );
	}
	/** Cached partial totals cannot survive an advancing backfill cursor. */
	public function test_a_backfill_advance_invalidates_cached_local_totals(): void {
		$external = true === wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );
		try {
			$reports = new \Aggressive\Ads\Repository\Rollup_Report_Repository();
			$period  = Report_Period::between( '2026-08-13', '2026-08-13', 'Asia/Kolkata' );
			$this->assertSame( 0, $reports->totals_for_org( 8172, $period )['impressions'] );
			$this->assertTrue( ( new \Aggressive\Ads\Repository\Rollup_Repository() )->increment( 'impressions', 817, 817, '', 0, 8172, 0, strtotime( '2026-08-12 18:30:00 UTC' ) ) );
			$this->assertSame( 0, $reports->totals_for_org( 8172, $period )['impressions'], 'The first read was not cached, so the cursor check would prove nothing.' );
			update_option( Report_Bucket_Repository::OPTION_NEXT, strtotime( '2026-08-14 UTC' ), false );
			$this->assertSame( 1, $reports->totals_for_org( 8172, $period )['impressions'] );
		} finally {
			wp_cache_flush_group( \Aggressive\Ads\Repository\Rollup_Report_Repository::CACHE_GROUP );
			wp_using_ext_object_cache( $external );
			$this->assertSame( $external, wp_using_ext_object_cache() );
		}
	}
	/** A range entirely before retained history is not a measured zero. */
	public function test_missing_local_history_is_not_displayed_as_zero(): void {
		$container = Plugin::instance()->container();
		$settings  = $container->get( \Aggressive\Ads\Core\Settings::class );
		$document  = $settings->get();
		$document['modules'][ \Aggressive\Ads\Domain\Settings_Schema::MODULE_REPORTING ] = true;
		$this->assertTrue( $settings->save( $document ) );
		update_option( Report_Bucket_Repository::OPTION_SINCE, strtotime( '2026-08-20 UTC' ), false );
		$period = Report_Period::between( '2026-08-01', '2026-08-07', 'Asia/Kolkata' );
		$this->assertFalse( $container->get( Reporting_Read::class )->history_available( $period ) );
		$tiles = $container->get( Delivery_View_Data::class )->counts( 100, $period );
		$this->assertSame( array_fill( 0, 5, 'Not measured' ), array_column( $tiles, 'value' ) );
	}
}
