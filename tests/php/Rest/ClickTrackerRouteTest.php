<?php
/**
 * Saving a click tracker through the REST route.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The write half of the tracker, read back from storage.
 *
 * `ClickTrackerDeliveryTest` proves the hop reads a stored tracker. This proves
 * the route puts one there — and never answers 200 for a value it did not keep,
 * which is how the line-item PATCH once lost its delivery fields.
 */
final class ClickTrackerRouteTest extends WP_UnitTestCase {

	private const TRACKER = 'https://track.example.com/c?cid={campaign_id}&u={lpurl}';

	/**
	 * The advertiser who owns the campaign.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Another organization's advertiser.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * The campaign under test.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Builds two organizations and one owned draft.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org, Org_Repository::META_OWNER_USER, $this->owner );

		$other = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other, Org_Repository::META_OWNER_USER, $this->stranger );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->owner,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $org );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Autosaves one field as a user.
	 *
	 * @param int                  $user   Acting user.
	 * @param array<string, mixed> $fields Body.
	 * @return \WP_REST_Response
	 */
	private function patch( int $user, array $fields ): \WP_REST_Response {
		wp_set_current_user( $user );

		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params( $fields + array( 'autosave_rev' => $this->current_revision() ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The campaign's current autosave revision, as the route expects it.
	 *
	 * @return int
	 */
	private function current_revision(): int {
		return Plugin::instance()->container()->get( Campaign_Repository::class )->autosave_revision( $this->campaign_id );
	}

	/**
	 * The tracker as storage holds it, bypassing the repository's own getter.
	 *
	 * @return string
	 */
	private function stored(): string {
		return (string) get_post_meta( $this->campaign_id, Campaign_Repository::META_CLICK_TRACKER, true );
	}

	public function test_a_tracker_sent_to_the_route_is_in_storage_afterwards(): void {
		$response = $this->patch( $this->owner, array( 'click_tracker' => self::TRACKER ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::TRACKER, $this->stored() );
		$this->assertSame( self::TRACKER, $response->get_data()['click_tracker'] );
	}

	public function test_a_tracker_typed_without_a_scheme_is_stored_with_one(): void {
		$this->patch( $this->owner, array( 'click_tracker' => 'track.example.com/c?u={lpurl}' ) );

		$this->assertSame( 'https://track.example.com/c?u={lpurl}', $this->stored() );
	}

	public function test_an_empty_tracker_removes_it(): void {
		$this->patch( $this->owner, array( 'click_tracker' => self::TRACKER ) );
		$this->patch( $this->owner, array( 'click_tracker' => '' ) );

		$this->assertSame( '', $this->stored() );
	}

	public function test_a_request_that_does_not_mention_the_tracker_leaves_it_alone(): void {
		$this->patch( $this->owner, array( 'click_tracker' => self::TRACKER ) );
		$this->patch( $this->owner, array( 'title' => 'Renamed' ) );

		$this->assertSame( self::TRACKER, $this->stored() );
	}

	public function test_a_refused_tracker_says_why_and_keeps_the_previous_one(): void {
		$this->patch( $this->owner, array( 'click_tracker' => self::TRACKER ) );

		$refused = array(
			'no landing page' => 'https://track.example.com/c?cid=1',
			'unencoded'       => 'https://track.example.com/c?u={lpurl_unenc}',
			'twice'           => 'https://track.example.com/c?a={lpurl}&b={lpurl}',
			'not a link'      => 'not a link at all {lpurl}',
			'a script'        => 'javascript:alert({lpurl})',
		);

		foreach ( $refused as $label => $tracker ) {
			$response = $this->patch( $this->owner, array( 'click_tracker' => $tracker ) );
			$data     = $response->get_data();

			$this->assertSame( 422, $response->get_status(), $label );
			$this->assertSame( 'click_tracker', $data['data']['field'] ?? '', $label );
			$this->assertSame( self::TRACKER, $this->stored(), $label . ': the old tracker must survive a refusal.' );
		}

		$this->assertCount( 5, $refused );
	}

	public function test_another_organizations_advertiser_cannot_set_it(): void {
		$response = $this->patch( $this->stranger, array( 'click_tracker' => self::TRACKER ) );

		$this->assertContains( $response->get_status(), array( 403, 404 ) );
		$this->assertSame( '', $this->stored() );
	}

	public function test_a_copy_carries_the_tracker(): void {
		$this->patch( $this->owner, array( 'click_tracker' => self::TRACKER ) );

		wp_set_current_user( $this->owner );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $this->campaign_id . '/copy' ) );
		$copy     = (int) ( $response->get_data()['id'] ?? 0 );

		$this->assertGreaterThan( 0, $copy );
		$this->assertNotSame( $this->campaign_id, $copy );
		$this->assertSame( self::TRACKER, (string) get_post_meta( $copy, Campaign_Repository::META_CLICK_TRACKER, true ) );
	}
}
