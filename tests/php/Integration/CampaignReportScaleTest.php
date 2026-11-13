<?php
/**
 * Actual summary queries must bound projection inputs and paginated candidates.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Report_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

final class CampaignReportScaleTest extends WP_UnitTestCase {

	/** Actual production SQL, not an equivalent copy in the test, is explained. */
	public function test_candidate_query_seeks_tenant_and_cursor_before_limiting(): void {
		global $wpdb;
		$plan  = $this->plan();
		$table = $wpdb->prefix . Schema::LINE_ITEMS_TABLE;
		foreach ( array( 12345, 98765 ) as $org ) {
			$values = array();
			$args   = array( $table );
			for ( $i = 0; $i < 500; ++$i ) {
				$values[] = '(%d,%d)';
				$args[]   = $plan['campaign_id'];
				$args[]   = $org;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded physical fixture; newer foreign rows make a primary-key scan examine more than the eleven-row candidate budget.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (campaign_id,organization_id) VALUES ' . implode( ',', $values ), ...$args ) );
		}
		$sql     = '';
		$capture = static function ( string $query ) use ( &$sql ): string {
			if ( str_starts_with( $query, 'SELECT id,campaign_id,organization_id,LEFT(name' ) ) {
				$sql = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture );
		try {
			$page = ( new Campaign_Report_Repository() )->page( 12345 );
		} finally {
			remove_filter( 'query', $capture );
		}
		$this->assertIsArray( $page );
		$this->assertCount( 10, $page['rows'] );
		$this->assertTrue( $page['more'] );
		$this->assertNotSame( '', $sql );
		$this->assertStringContainsString( 'LIMIT 11', $sql );
		$this->assert_query_examined_at_most( $sql, 11 );
		$second = ( new Campaign_Report_Repository() )->page( 12345, $page['next'] );
		$this->assertIsArray( $second );
		$this->assertLessThan( $page['next'], $second['rows'][0]['id'] );
	}

	/** More than sixty-five thousand input rows refuse progress instead of summing a prefix. */
	public function test_projection_query_limits_input_rows_and_discloses_unavailable_totals(): void {
		global $wpdb;
		$plan = $this->plan();
		$this->assertTrue( ( new Rollup_Repository() )->increment( 'impressions', 1, $plan['campaign_id'], '2026-11-03', $plan['id'], 12345 ) );
		$table = $wpdb->prefix . Schema::ROLLUPS_TABLE;
		for ( $start = 1; $start <= 80000; $start += 500 ) {
			$values = array();
			$args   = array( $table );
			for ( $id = $start; $id < $start + 500; ++$id ) {
				$values[] = '(%s,1,%d,%d,%d,%d,1,0,0)';
				array_push( $args, '2026-11-03', $plan['campaign_id'], $plan['id'], $id, 12345 );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Batch physical rows at the same native grain as the real writer used above; fixture size exceeds the guard independently of its configured constant.
			$this->assertSame( 500, $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day_utc,placement_id,campaign_id,line_item_id,creative_id,org_id,impressions,clicks,viewables) VALUES ' . implode( ',', $values ), ...$args ) ) );
		}
		$period = Report_Period::between( '2026-11-01', '2026-11-07', 'UTC' );
		$this->assertNotNull( $period );
		$reports = new Campaign_Report_Repository();
		$result  = $reports->totals( 12345, array( $plan ), $period, strtotime( '2026-11-16 UTC' ) );
		$sql     = $wpdb->last_query;
		$this->assertSame( 65537, array_sum( array_map( static fn ( object $row ): int => (int) $row->input_rows, $wpdb->last_result ) ), 'Removing the SQL limit must fail even though the summary still refuses oversized totals.' );
		$this->assertLessThanOrEqual( 110, count( $wpdb->last_result ), 'Only bounded aggregates, never raw projection rows, should be returned to PHP.' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['limited'] );
		$this->assertNull( $result['totals'][ $plan['id'] ] );
		$this->assert_query_examined_at_most( $sql, 65537 );
		$summary  = Plugin::instance()->container()->get( \Aggressive\Ads\Portal\Campaign_Report_View_Data::class );
		$settings = Plugin::instance()->container()->get( \Aggressive\Ads\Core\Settings::class );
		$data     = $settings->get();
		$data['modules'][ \Aggressive\Ads\Domain\Settings_Schema::MODULE_REPORTING ] = true;
		$this->assertTrue( $settings->save( $data ) );
		$view = $summary->for_org( 12345, $period, strtotime( '2026-11-16 UTC' ) );
		$this->assertIsArray( $view );
		$this->assertStringContainsString( 'summary read limit', implode( ' ', $view['notes'] ) );
		$this->assertSame( 'Not measured', array_column( $view['rows'][0]['metrics'], 'value', 'label' )['Impressions'] );
	}

	/** Counter overflow is a read failure, rather than a rounded or negative success. */
	public function test_unsigned_values_beyond_php_integer_range_are_refused(): void {
		global $wpdb;
		$plan = $this->plan();
		$this->assertTrue( ( new Rollup_Repository() )->increment( 'impressions', 1, $plan['campaign_id'], '2026-11-03', $plan['id'], 12345 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Corrupt/out-of-range stored input tests the checked integer boundary, not fixture-supplied report values.
		$wpdb->update( $wpdb->prefix . Schema::ROLLUPS_TABLE, array( 'impressions' => '18446744073709551615' ), array( 'org_id' => 12345 ) );
		$this->assertWPError( ( new Campaign_Report_Repository() )->totals( 12345, array( $plan ), null, strtotime( '2026-11-16 UTC' ) ) );
	}

	/**
	 * MySQL's actual rows/loops prove bounds even when an index name stays the same.
	 *
	 * @param string $sql SQL captured from the production repository call.
	 * @param int $limit Maximum examined rows at any iterator.
	 */
	private function assert_query_examined_at_most( string $sql, int $limit ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- EXPLAIN the already-prepared SQL actually issued by the repository, not a copy of its query.
		$plan = implode( "\n", $wpdb->get_col( 'EXPLAIN ANALYZE ' . $sql ) );
		preg_match_all( '/actual time=[^)]*?rows=([0-9]+) loops=([0-9]+)/', $plan, $matches, PREG_SET_ORDER );
		$this->assertNotEmpty( $matches, $plan );
		foreach ( $matches as $iterator ) {
			$this->assertLessThanOrEqual( $limit, (int) $iterator[1] * (int) $iterator[2], $plan );
		}
	}

	/**
	 * A real default plan defines campaign/tenant identity for the bulk fixture.
	 *
	 * @return array<string, mixed>
	 */
	private function plan(): array {
		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => 'aggr_live',
				'post_title'  => 'Scale campaign',
			)
		);
		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, 12345 );
		$item = Plugin::instance()->container()->get( Line_Item_Repository::class )->ensure_default( $campaign );
		$this->assertIsArray( $item );
		return $item;
	}
}
