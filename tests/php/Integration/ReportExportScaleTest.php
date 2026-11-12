<?php
/**
 * Reproducible export capacity fixtures; no production seed data.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Report_Export_Limits;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Report_Actions;
use Aggressive\Ads\Repository\Report_Bucket_Repository;
use Aggressive\Ads\Repository\Rollup_Report_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

final class ReportExportScaleTest extends WP_UnitTestCase {

	/**
	 * Exercise row cardinality and payload width independently.
	 *
	 * @return array<string, array{int, int, string}>
	 */
	public static function scenarios(): array {
		return array(
			'large catalogue'  => array( 1000, 64, 'UTC' ),
			'wide catalogue'   => array( 1000, 1024, 'UTC' ),
			'near limit UTC'   => array( 160, 1024, 'UTC' ),
			'near limit local' => array( 160, 1024, 'Asia/Kolkata' ),
		);
	}

	/**
	 * Assert the production export's resource budget, optionally printing measurements.
	 *
	 * @dataProvider scenarios
	 * @param int    $campaigns Campaigns owned by the reporting org.
	 * @param int    $bytes     Campaign title bytes.
	 * @param string $zone     Reporting timezone.
	 */
	public function test_export_resource_cost( int $campaigns, int $bytes, string $zone ): void {
		$this->seed( $campaigns, $bytes );
		$period  = Report_Period::between( '2026-08-01', '2026-08-31', $zone );
		$reports = new Rollup_Report_Repository();
		$export  = Plugin::instance()->container()->get( Report_Actions::class );
		gc_collect_cycles();
		memory_reset_peak_usage();
		$before  = memory_get_usage();
		$start   = hrtime( true );
		$rows    = $reports->daily_rows_for_org( 4242, $period );
		$read    = hrtime( true );
		$csv     = $export->document( $rows, $zone );
		$end     = hrtime( true );
		$metrics = array(
			'campaigns'        => $campaigns,
			'title_bytes'      => $bytes,
			'zone'             => $zone,
			'rows'             => is_array( $rows ) ? count( $rows ) : null,
			'error'            => is_wp_error( $rows ) ? $rows->get_error_code() : null,
			'csv_bytes'        => is_string( $csv ) ? strlen( $csv ) : null,
			'read_ms'          => round( ( $read - $start ) / 1e6, 2 ),
			'csv_ms'           => round( ( $end - $read ) / 1e6, 2 ),
			'peak_added_bytes' => memory_get_peak_usage() - $before,
		);
		if ( '1' === getenv( 'AGGR_REPORT_EXPORT_BENCHMARK' ) ) {
			fwrite( STDERR, 'aggr-export-benchmark: ' . wp_json_encode( $metrics ) . "\n" );
		}
		$this->assertLessThan( 32 * 1024 * 1024, $metrics['peak_added_bytes'] );
		if ( $campaigns * 31 > Report_Export_Limits::MAX_ROWS ) {
			$this->assertWPError( $rows );
			$this->assertSame( 'aggr_report_export_too_large', $rows->get_error_code() );
			$this->assertSame( $rows, $csv );
			global $wpdb;
			$this->assertCount( Report_Export_Limits::MAX_ROWS + 1, $wpdb->last_result );
			$smaller = $reports->daily_rows_for_org( 4242, $period, 1000001 );
			$this->assertIsArray( $smaller );
			$this->assertCount( 31, $smaller );
		} else {
			$this->assertIsArray( $rows );
			$this->assertCount( $campaigns * 31, $rows );
			$this->assertIsString( $csv );
			$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
			$this->assertLessThanOrEqual( Report_Export_Limits::MAX_CSV_BYTES, strlen( $csv ) );
		}
	}

	/** The last allowed row is included; the next row refuses the whole export. */
	public function test_exact_row_boundary_and_recovery_by_narrowing_the_range(): void {
		$this->seed( 162, 64, 9 );
		$reports = new Rollup_Report_Repository();
		$period  = Report_Period::between( '2026-08-01', '2026-08-31' );
		$this->assertCount( 5000, $reports->daily_rows_for_org( 4242, $period ) );
		$this->assertTrue( ( new Rollup_Repository() )->increment( 'impressions', 1, 1000162, '2026-08-10', 0, 4242 ) );
		$too_many = $reports->daily_rows_for_org( 4242, $period );
		$this->assertWPError( $too_many );
		$this->assertSame( 'aggr_report_export_too_large', $too_many->get_error_code() );
		$this->assertSame( 422, $too_many->get_error_data()['status'] );
		$smaller = $reports->daily_rows_for_org( 4242, Report_Period::between( '2026-08-01', '2026-08-30' ) );
		$this->assertCount( 4840, $smaller );
	}

	/** A large stored name is masked in SQL, not transferred and then truncated. */
	public function test_name_limit_counts_utf8_bytes_and_recovery_preserves_the_complete_name(): void {
		global $wpdb;
		$this->seed( 1, 1025 );
		$reports  = new Rollup_Report_Repository();
		$period   = Report_Period::between( '2026-08-01', '2026-08-31' );
		$too_wide = $reports->daily_rows_for_org( 4242, $period );
		$this->assertWPError( $too_wide );
		$this->assertSame( 'aggr_report_export_name_too_large', $too_wide->get_error_code() );
		$this->assertNull( $wpdb->last_result[0]->campaign );
		$this->assertSame( 1, $wpdb->update( $wpdb->posts, array( 'post_title' => str_repeat( 'é', 512 ) ), array( 'ID' => 1000001 ) ) );
		$allowed = $reports->daily_rows_for_org( 4242, $period );
		$this->assertCount( 31, $allowed );
		$this->assertSame( str_repeat( 'é', 512 ), $allowed[0]['campaign'] );
		$this->assertSame( 1, $wpdb->update( $wpdb->posts, array( 'post_title' => str_repeat( 'é', 513 ) ), array( 'ID' => 1000001 ) ) );
		$this->assertWPError( $reports->daily_rows_for_org( 4242, $period ) );
	}

	/** Escaped quotes can exceed the document budget even below both input limits. */
	public function test_serialized_byte_budget_refuses_without_returning_partial_csv(): void {
		$export = Plugin::instance()->container()->get( Report_Actions::class );
		$row    = array(
			'day'         => '2026-08-01',
			'campaign_id' => 1,
			'campaign'    => str_repeat( '"', 1024 ),
			'impressions' => 10,
			'clicks'      => 1,
		);
		$csv    = $export->document( array_fill( 0, 5000, $row ) );
		$this->assertWPError( $csv );
		$this->assertSame( 'aggr_report_export_too_large', $csv->get_error_code() );
		$this->assertSame( 422, $csv->get_error_data()['status'] );
		$row['campaign'] = 'A short name';
		$csv             = $export->document( array_fill( 0, 5000, $row ) );
		$this->assertIsString( $csv );
		$this->assertSame( 5001, substr_count( $csv, "\r\n" ) );
	}

	/** Database errors cannot masquerade as valid zero-row exports, and retries are fresh. */
	public function test_database_failure_returns_a_retryable_error_and_then_recovers(): void {
		global $wpdb;
		$this->seed( 1, 64 );
		$reports    = new Rollup_Report_Repository();
		$period     = Report_Period::between( '2026-08-01', '2026-08-31' );
		$fail       = static fn ( string $query ): string => str_starts_with( $query, 'SELECT q.*, CASE WHEN OCTET_LENGTH' ) ? 'SELECT aggr_missing_export_column' : $query;
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$rows = $reports->daily_rows_for_org( 4242, $period );
			$this->assertWPError( $rows );
			$this->assertSame( 'aggr_report_export_unavailable', $rows->get_error_code() );
			$this->assertSame( 503, $rows->get_error_data()['status'] );
			$this->assertSame( $rows, Plugin::instance()->container()->get( Report_Actions::class )->document( $rows ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppressed );
		}
		$this->assertCount( 31, $reports->daily_rows_for_org( 4242, $period ) );
	}

	/** Foreign oversized data is excluded before either the row or name budget applies. */
	public function test_limits_are_scoped_to_the_frozen_tenant_and_campaign(): void {
		global $wpdb;
		$this->seed( 163, 1025 );
		$daily   = ( new Rollup_Repository() )->table_name();
		$buckets = ( new Report_Bucket_Repository() )->table_name();
		foreach ( array( $daily, $buckets ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Move only this fixture's tenant; table names are repository-owned.
			$this->assertNotFalse( $wpdb->query( "UPDATE {$table} SET org_id=8484 WHERE org_id=4242 AND campaign_id>1000001" ) );
		}
		$this->assertSame( 1, $wpdb->update( $wpdb->posts, array( 'post_title' => 'My campaign' ), array( 'ID' => 1000001 ) ) );
		$reports = new Rollup_Report_Repository();
		foreach ( array( 'UTC', 'Asia/Kolkata' ) as $zone ) {
			$period = Report_Period::between( '2026-08-01', '2026-08-31', $zone );
			$rows   = $reports->daily_rows_for_org( 4242, $period );
			$this->assertCount( 31, $rows );
			$this->assertSame( array( 'My campaign' ), array_values( array_unique( array_column( $rows, 'campaign' ) ) ) );
			$this->assertSame( array(), $reports->daily_rows_for_org( 4242, $period, 1000002 ) );
			$this->assertWPError( $reports->daily_rows_for_org( 8484, $period ) );
			$this->assertSame( array(), $reports->daily_rows_for_org( 0, $period ) );
		}
	}

	/** Bulk writes keep fixture setup out of the measured read/serialization path. */
	private function seed( int $campaigns, int $bytes, int $last_days = 31 ): void {
		global $wpdb;
		$daily   = ( new Rollup_Repository() )->table_name();
		$buckets = ( new Report_Bucket_Repository() )->table_name();
		for ( $id = 1; $id <= $campaigns; ++$id ) {
			$title = sprintf( '%06d ', $id ) . str_repeat( 'a', $bytes - 7 );
			$this->assertSame(
				1,
				$wpdb->insert(
					$wpdb->posts,
					array(
						'ID'                    => 1000000 + $id,
						'post_title'            => $title,
						'post_type'             => 'aggr_campaign',
						'post_status'           => 'publish',
						'post_content'          => '',
						'post_excerpt'          => '',
						'to_ping'               => '',
						'pinged'                => '',
						'post_content_filtered' => '',
					)
				)
			);
		}
		for ( $day = 1; $day <= 31; ++$day ) {
			$values = array();
			for ( $id = 1; $id <= $campaigns; ++$id ) {
				if ( $id === $campaigns && $day > $last_days ) {
					continue;
				}
				$values[] = $wpdb->prepare( '(%s,1,%d,4242,10,1)', sprintf( '2026-08-%02d', $day ), 1000000 + $id );
			}
			foreach ( array_chunk( $values, 500 ) as $chunk ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Disposable bulk fixture, all values prepared above.
				$this->assertNotFalse( $wpdb->query( "INSERT INTO {$daily} (day_utc,placement_id,campaign_id,org_id,impressions,clicks) VALUES " . implode( ',', $chunk ) ) );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Repository-owned table names, bounded fixture org only.
		$this->assertNotFalse( $wpdb->query( "INSERT INTO {$buckets} (bucket_utc,placement_id,campaign_id,org_id,impressions,clicks) SELECT CONCAT(day_utc,' 12:00:00'),placement_id,campaign_id,org_id,impressions,clicks FROM {$daily} WHERE org_id=4242" ) );
	}
}
