<?php
/**
 * Native delivery query budgets at the supported catalogue size.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Fill_Service;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_UnitTestCase;

/**
 * One thousand live creatives must not produce one thousand delivery queries.
 */
final class DeliveryScaleTest extends WP_UnitTestCase {

	private const ACTIVE_ADS = 1_000;

	/**
	 * Cold fill, warm fill, and token validation have explicit query budgets.
	 *
	 * Wall-clock assertions are deliberately absent because shared CI hardware
	 * is noisy. Query count captures the N+1 regression deterministically; the
	 * elapsed measurements remain in failure messages for operational evidence.
	 */
	public function test_one_thousand_active_ads_have_bounded_query_cost(): void {
		global $wpdb;

		$placement_id = $this->placement();

		$container   = Plugin::instance()->container();
		$assignments = $container->get( Creative_Assignment_Repository::class );
		$assignments->install_table();
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$this->seed_live_ads( $placement_id, self::ACTIVE_ADS );

		add_filter(
			'wp_get_attachment_image_src',
			static fn (): array => array( 'https://example.org/creative.png', 728, 90, false )
		);

		$fill = $container->get( Fill_Service::class );
		$container->get( Fill_Cache::class )->delete( $placement_id );
		$this->assertCount(
			min( self::ACTIVE_ADS, 500 ),
			$assignments->candidates_for_placement( $placement_id, time(), 500 )
		);

		$cold_start_queries = $wpdb->num_queries;
		$cold_start_time    = hrtime( true );
		$cold               = $fill->for_slug( 'scale-leaderboard' );
		$cold_ms            = ( hrtime( true ) - $cold_start_time ) / 1_000_000;
		$cold_queries       = $wpdb->num_queries - $cold_start_queries;

		$this->assertIsArray( $cold );
		$this->assertIsArray( $cold['creative'] );
		$this->assertLessThanOrEqual(
			12,
			$cold_queries,
			sprintf( 'Cold 1,000-ad fill used %d queries in %.2fms.', $cold_queries, $cold_ms )
		);

		$warm_start_queries = $wpdb->num_queries;
		$warm_start_time    = hrtime( true );
		$warm               = $fill->for_slug( 'scale-leaderboard' );
		$warm_ms            = ( hrtime( true ) - $warm_start_time ) / 1_000_000;
		$warm_queries       = $wpdb->num_queries - $warm_start_queries;

		$this->assertIsArray( $warm );
		$this->assertLessThanOrEqual(
			4,
			$warm_queries,
			sprintf( 'Warm 1,000-ad fill used %d queries in %.2fms.', $warm_queries, $warm_ms )
		);

		$parsed = ( new Fill_Token() )->parse( (string) $cold['creative']['token'] );
		$this->assertIsArray( $parsed );

		wp_cache_flush();
		$accept_start_queries = $wpdb->num_queries;
		$accept_start_time    = hrtime( true );
		$accepted             = $fill->accepts( $parsed );
		$accept_ms            = ( hrtime( true ) - $accept_start_time ) / 1_000_000;
		$accept_queries       = $wpdb->num_queries - $accept_start_queries;

		$this->assertTrue( $accepted );
		$this->assertLessThanOrEqual(
			8,
			$accept_queries,
			sprintf( 'Token validation used %d queries in %.2fms with 1,000 eligible ads.', $accept_queries, $accept_ms )
		);

		if ( '1' === getenv( 'AGGR_REPORT_PERFORMANCE' ) ) {
			fwrite(
				STDOUT,
				sprintf(
					"\n1,000-ad delivery: cold=%d queries/%.2fms warm=%d queries/%.2fms validate=%d queries/%.2fms\n",
					$cold_queries,
					$cold_ms,
					$warm_queries,
					$warm_ms,
					$accept_queries,
					$accept_ms
				)
			);
		}
	}

	/** Creates the measured placement. */
	private function placement(): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'scale-leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		return $placement_id;
	}

	/**
	 * Creates the realistic one-campaign/one-creative shape.
	 *
	 * @param int $placement_id Placement post id.
	 * @param int $count        Number of active ads.
	 */
	private function seed_live_ads( int $placement_id, int $count ): void {
		global $wpdb;

		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );

		for ( $index = 0; $index < $count; ++$index ) {
			$campaign_id = (int) self::factory()->post->create(
				array(
					'post_type'   => Post_Types::CAMPAIGN,
					'post_status' => Post_Statuses::LIVE,
				)
			);
			add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );

			$creative_id = (int) self::factory()->post->create(
				array(
					'post_type'   => Post_Types::CREATIVE,
					'post_status' => 'publish',
				)
			);
			update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
			update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $placement_id );
			update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, 1 );
			update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/ad/' . $index );
			update_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, 'Advertisement' );
			update_post_meta( $creative_id, Creative_Repository::META_WIDTH, 728 );
			update_post_meta( $creative_id, Creative_Repository::META_HEIGHT, 90 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
			$wpdb->insert(
				$assignments->table_name(),
				array(
					'line_item_id'  => $index + 1,
					'campaign_id'   => $campaign_id,
					'placement_id'  => $placement_id,
					'revision_id'   => $creative_id,
					'status'        => Assignment_Rules::LIVE,
					'weight'        => 100,
					'click_url'     => 'https://example.com/ad/' . $index,
					'attachment_id' => 1,
					'alt_text'      => 'Advertisement',
					'width'         => 728,
					'height'        => 90,
					'revision'      => 1,
				)
			);
		}
	}
}
