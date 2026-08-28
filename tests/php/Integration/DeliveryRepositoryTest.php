<?php
/**
 * Token-validation reads for live creatives.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Delivery_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use WP_UnitTestCase;

/**
 * Beacon and click-hop validation re-read one exact live tuple.
 */
final class DeliveryRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var Delivery_Repository
	 */
	private Delivery_Repository $delivery;

	/**
	 * Fixture placement.
	 *
	 * @var int
	 */
	private int $placement_id;

	public function set_up(): void {
		parent::set_up();

		$this->delivery     = new Delivery_Repository();
		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
	}

	public function test_candidate_returns_a_live_creative_for_token_validation(): void {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);
		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/paid' );
		update_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, 'Paid' );
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, 99 );
		update_post_meta( $creative_id, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative_id, Creative_Repository::META_HEIGHT, 90 );

		$row = $this->delivery->candidate( $creative_id, $this->placement_id, $campaign_id );

		$this->assertIsArray( $row );
		$this->assertSame( $creative_id, $row['creative_id'] );
		$this->assertSame( $campaign_id, $row['campaign_id'] );
		$this->assertSame( $this->placement_id, $row['placement_id'] );
		$this->assertSame( 'https://example.com/paid', $row['click_url'] );
	}

	public function test_candidate_rejects_a_paused_campaign(): void {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::PAUSED,
			)
		);
		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/paused' );

		$this->assertNull( $this->delivery->candidate( $creative_id, $this->placement_id, $campaign_id ) );
	}

	public function test_candidate_rejects_a_wrong_placement(): void {
		$other_placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other_placement, Placement_Repository::META_IS_ACTIVE, 1 );

		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);
		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/wrong-slot' );

		$this->assertNull( $this->delivery->candidate( $creative_id, $other_placement, $campaign_id ) );
	}
}
