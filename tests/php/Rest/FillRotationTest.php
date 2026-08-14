<?php
/**
 * Rotation fill and rollup attribution.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Two live campaigns on one slot rotate; counts follow the filled token.
 */
final class FillRotationTest extends WP_UnitTestCase {

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Fixture placement.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Enables native delivery and a sized slot.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
		( new Installer( new Audit_Repository(), new Roles() ) )->install_delivery_tables();

		$this->settings     = Plugin::instance()->container()->get( Settings::class );
		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'leaderboard',
				'post_title'  => 'Leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Drops the settings option so later tests see defaults.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );
		remove_all_filters( 'wp_get_attachment_image_src' );
		parent::tear_down();
	}

	/**
	 * A beacon credits the campaign named on the token, not the oldest live row.
	 *
	 * @return void
	 */
	public function test_beacon_credits_the_filled_campaign_not_the_oldest(): void {
		$this->enable_native();
		$this->stub_images();

		$older = $this->live_campaign( 'https://example.com/older' );
		$newer = $this->live_campaign( 'https://example.com/newer' );

		$this->assertLessThan( $newer['campaign'], $older['campaign'] );

		$token   = ( new Fill_Token() )->mint( $this->placement_id, $newer['campaign'], $newer['creative'] )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$this->assertSame( 204, rest_get_server()->dispatch( $request )->get_status() );

		$totals = ( new Rollup_Repository() )->totals_for_campaigns( array( $older['campaign'], $newer['campaign'] ) );

		$this->assertSame( 0, $totals[ $older['campaign'] ]['impressions'] ?? 0 );
		$this->assertSame( 1, $totals[ $newer['campaign'] ]['impressions'] );
		$this->assertSame( 0, $totals[ $newer['campaign'] ]['clicks'] );
	}

	/**
	 * Fill rotates among live campaigns and never leaks the candidate set.
	 *
	 * @return void
	 */
	public function test_fill_rotates_and_omits_the_candidate_set(): void {
		$this->enable_native();
		$this->stub_images();

		$first  = $this->live_campaign( 'https://example.com/one' );
		$second = $this->live_campaign( 'https://example.com/two' );
		$tokens = new Fill_Token();
		$seen   = array();

		Plugin::instance()->container()->get( Fill_Cache::class )->delete( $this->placement_id );

		for ( $i = 0; $i < 40; $i++ ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aggr/v1/fill/leaderboard' ) );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status() );
			$this->assertIsArray( $data );
			$this->assertArrayNotHasKey( 'candidates', $data );
			$this->assertIsArray( $data['creative'] );
			$this->assertNull( $data['house'] );
			$this->assertArrayNotHasKey( 'campaign', $data['creative'] );
			$this->assertArrayNotHasKey( 'creative', $data['creative'] );

			$parsed = $tokens->parse( (string) $data['creative']['token'] );
			$this->assertIsArray( $parsed );
			$seen[ $parsed['campaign_id'] ] = true;
		}

		$this->assertArrayHasKey( $first['campaign'], $seen );
		$this->assertArrayHasKey( $second['campaign'], $seen );
	}

	/**
	 * Turns native delivery on for this request.
	 */
	private function enable_native(): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;

		$this->assertTrue( $this->settings->save( $document ) );
	}

	/**
	 * Public image URLs for every attachment in this test.
	 */
	private function stub_images(): void {
		add_filter(
			'wp_get_attachment_image_src',
			static function () {
				return array( 'https://example.org/creative.png', 728, 90, false );
			}
		);
	}

	/**
	 * One live campaign with an active creative on the fixture placement.
	 *
	 * @param string $click Destination.
	 * @return array{campaign: int, creative: int}
	 */
	private function live_campaign( string $click ): array {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
				'post_title'  => 'Live ' . $click,
			)
		);
		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'creative.png',
				'post_mime_type' => 'image/png',
			)
		);

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, $click );
		update_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, 'Paid' );
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );

		return array(
			'campaign' => $campaign_id,
			'creative' => $creative_id,
		);
	}
}
