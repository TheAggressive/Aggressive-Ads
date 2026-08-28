<?php
/**
 * Campaign catalogue queries.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Campaign_Repository;
use WP_UnitTestCase;

/**
 * Live-set reads must paginate without dropping rows.
 */
final class CampaignQueryRepositoryTest extends WP_UnitTestCase {

	/**
	 * Live ids for one placement return every row, not just the first page.
	 */
	public function test_live_ids_for_placement_does_not_stop_at_one_page(): void {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
		$expected     = array();

		for ( $i = 0; $i < 21; $i++ ) {
			$campaign_id = (int) self::factory()->post->create(
				array(
					'post_type'   => Post_Types::CAMPAIGN,
					'post_status' => Post_Statuses::LIVE,
				)
			);
			add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );
			$expected[] = $campaign_id;
		}

		$this->assertSame(
			$expected,
			( new Campaign_Repository() )->live_ids_for_placement( $placement_id )
		);
	}
}
