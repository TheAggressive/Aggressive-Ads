<?php
/**
 * The REST write paths.
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
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Upload and transition, through the real router.
 *
 * Writes may answer 403 where reads answer 404: the caller already knows the
 * object exists, because they are trying to change it.
 */
final class WriteRoutesTest extends WP_UnitTestCase {

	/**
	 * An advertiser who owns the campaign.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * An advertiser from a different organization.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * A staff reviewer.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * The campaign under test.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * An active, selected placement.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Temporary files.
	 *
	 * @var array<int, string>
	 */
	private array $temporary = array();

	/**
	 * Builds users, a campaign and a placement.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

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

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->owner,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $org );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Removes temporary files.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$this->temporary = array();

		parent::tear_down();
	}

	/**
	 * Builds an upload request.
	 *
	 * @param int $campaign_id  Campaign to upload to.
	 * @param int $placement_id Placement to fill.
	 * @return WP_REST_Request
	 */
	private function upload_request( int $campaign_id, int $placement_id ): WP_REST_Request {
		$image = imagecreatetruecolor( 728, 90 );

		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();

		$temp = wp_tempnam( 'aggr-rest-upload' );
		file_put_contents( $temp, $bytes );
		$this->temporary[] = $temp;

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $campaign_id . '/creatives' );

		$request->set_body_params(
			array(
				'placement_id' => $placement_id,
				'click_url'    => 'https://example.com/tickets',
				'alt_text'     => 'Spring season poster',
			)
		);

		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'poster.png',
					'tmp_name' => $temp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $bytes ),
				),
			)
		);

		return $request;
	}

	/**
	 * Every write route exists.
	 *
	 * @return void
	 */
	public function test_the_write_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/aggr/v1/campaigns', $routes );
		$this->assertArrayHasKey( '/aggr/v1/campaigns/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/aggr/v1/campaigns/(?P<id>\d+)/copy', $routes );
		$this->assertArrayHasKey( '/aggr/v1/campaigns/(?P<id>\d+)/creatives', $routes );
		$this->assertArrayHasKey( '/aggr/v1/creatives/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/aggr/v1/creatives/(?P<id>\d+)/replacement', $routes );
		$this->assertArrayHasKey( '/aggr/v1/creative-replacements/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/aggr/v1/creative-replacements/(?P<id>\d+)/decision', $routes );
		$this->assertArrayHasKey( '/aggr/v1/campaigns/(?P<id>\d+)/transitions', $routes );
	}

	/**
	 * A draft created through REST derives its tenant from the caller.
	 *
	 * @return void
	 */
	public function test_the_owner_can_create_an_organization_scoped_draft(): void {
		wp_set_current_user( $this->owner );

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns' );
		$request->set_body_params( array( 'title' => 'New campaign' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'New campaign', $data['title'] );
		$this->assertSame( Post_Statuses::DRAFT, $data['status'] );
		$this->assertSame( 0, $data['autosave_rev'] );
		$this->assertSame(
			get_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, true ),
			get_post_meta( (int) $data['id'], Campaign_Repository::META_ORG_ID, true )
		);
	}

	/**
	 * REST autosave persists only its public allowlist.
	 *
	 * @return void
	 */
	public function test_the_owner_can_autosave_an_editable_campaign(): void {
		wp_set_current_user( $this->owner );

		$original_org = get_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, true );
		$request      = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params(
			array(
				'title'            => 'Updated campaign',
				'placement_ids'    => array( $this->placement_id ),
				'start_ts'         => 1_900_000_000,
				'end_ts'           => 1_900_086_400,
				'advertiser_notes' => 'Please review this artwork.',
				'wizard_step'      => 'creative',
				'autosave_rev'     => 0,
				'org_id'           => PHP_INT_MAX,
				'internal_notes'   => 'Must never persist.',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Updated campaign', $data['title'] );
		$this->assertSame( 1, $data['autosave_rev'] );
		$this->assertSame( 'creative', $data['wizard_step'] );
		$this->assertSame( $original_org, get_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, true ) );
		$this->assertSame( '', get_post_meta( $this->campaign_id, Campaign_Repository::META_INTERNAL_NOTES, true ) );
	}

	/**
	 * REST package selection uses the same validated snapshot as the HTML form.
	 *
	 * @return void
	 */
	public function test_the_owner_can_select_a_package_through_autosave(): void {
		$package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Launch package',
			)
		);
		add_post_meta( $package_id, Package_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $package_id, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $package_id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $package_id, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $package_id, Package_Repository::META_IS_ACTIVE, 1 );

		wp_set_current_user( $this->owner );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params(
			array(
				'package_id'   => $package_id,
				'wizard_step'  => 'package',
				'autosave_rev' => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $package_id, $data['package_id'] );
		$this->assertSame( 45000, $data['budget_cents'] );
		$this->assertSame( 'USD', $data['currency'] );
		$this->assertSame( array( $this->placement_id ), $data['placement_ids'] );
	}

	/**
	 * REST cannot advance Step 4 without complete creative coverage.
	 *
	 * @return void
	 */
	public function test_rest_review_step_requires_complete_creative_coverage(): void {
		wp_set_current_user( $this->owner );

		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params(
			array(
				'start_ts'     => time() + DAY_IN_SECONDS,
				'end_ts'       => 0,
				'wizard_step'  => 'review',
				'autosave_rev' => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'aggr_creatives_incomplete', $response->get_data()['code'] );
		$this->assertSame( 0, (int) get_post_meta( $this->campaign_id, Campaign_Repository::META_START_TS, true ) );
	}

	/**
	 * REST and HTML share successful Step 4 completion validation.
	 *
	 * @return void
	 */
	public function test_rest_can_complete_destination_and_schedule_step(): void {
		wp_set_current_user( $this->owner );

		$creative_id = Plugin::instance()->container()->get( Creative_Repository::class )->create(
			$this->campaign_id,
			(int) get_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, true ),
			$this->placement_id,
			array(
				'kind'      => 'image',
				'click_url' => 'https://example.com/exhibition',
				'alt_text'  => 'Visitors viewing an exhibition',
				'size'      => '728x90',
			)
		);
		$this->assertGreaterThan( 0, $creative_id );

		$start   = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->setTime( 0, 0, 0 )->getTimestamp();
		$end     = ( new \DateTimeImmutable( '+20 days', wp_timezone() ) )->setTime( 23, 59, 59 )->getTimestamp();
		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params(
			array(
				'start_ts'     => $start,
				'end_ts'       => $end,
				'wizard_step'  => 'review',
				'autosave_rev' => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'review', $data['wizard_step'] );
		$this->assertSame( $start, $data['start_ts'] );
		$this->assertSame( $end, $data['end_ts'] );
		$this->assertSame( 1, $data['autosave_rev'] );
	}

	/**
	 * A stale autosave receives a conflict and cannot overwrite current data.
	 *
	 * @return void
	 */
	public function test_a_stale_rest_autosave_is_refused(): void {
		wp_set_current_user( $this->owner );

		$first = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$first->set_body_params(
			array(
				'title'        => 'Current title',
				'autosave_rev' => 0,
			)
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $first )->get_status() );

		$stale = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$stale->set_body_params(
			array(
				'title'        => 'Stale title',
				'autosave_rev' => 0,
			)
		);

		$response = rest_get_server()->dispatch( $stale );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'aggr_edit_conflict', $response->get_data()['code'] );
		$this->assertSame( 'Current title', get_the_title( $this->campaign_id ) );
	}

	/**
	 * Another tenant cannot autosave into an owned campaign.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_autosave(): void {
		wp_set_current_user( $this->stranger );

		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params(
			array(
				'title'        => 'Taken over',
				'autosave_rev' => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'aggr_forbidden', $response->get_data()['code'] );
	}

	/**
	 * The owner can upload a creative to their own campaign.
	 *
	 * @return void
	 */
	public function test_the_owner_can_upload(): void {
		wp_set_current_user( $this->owner );

		$response = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $this->placement_id ) );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 728, $data['width'] );
		$this->assertSame( 90, $data['height'] );
		$this->assertSame( 'image/png', $data['mime'] );
	}

	/**
	 * The owner can remove an unapproved creative and its private bytes.
	 *
	 * @return void
	 */
	public function test_the_owner_can_delete_an_editable_creative(): void {
		wp_set_current_user( $this->owner );
		$uploaded    = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $this->placement_id ) );
		$creative_id = (int) $uploaded->get_data()['id'];
		$stored      = Plugin::instance()->container()->get( Creative_Repository::class )->storage_details( $creative_id );
		$this->assertIsArray( $stored );

		$request  = new WP_REST_Request( 'DELETE', '/aggr/v1/creatives/' . $creative_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( get_post( $creative_id ) );
		$this->assertNull( Plugin::instance()->container()->get( Private_Storage::class )->resolve( $stored['path'] ) );
	}

	/**
	 * Another tenant cannot delete creative by posting its object id.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_delete_a_creative(): void {
		wp_set_current_user( $this->owner );
		$uploaded    = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $this->placement_id ) );
		$creative_id = (int) $uploaded->get_data()['id'];

		wp_set_current_user( $this->stranger );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$request  = new WP_REST_Request( 'DELETE', '/aggr/v1/creatives/' . $creative_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNotNull( get_post( $creative_id ) );
	}

	/**
	 * **The response never carries the private path or its token.**
	 *
	 * The stored path and token are on the creative this route just made, and
	 * the private root's unguessability is the layer that actually holds.
	 *
	 * @return void
	 */
	public function test_the_response_never_leaks_storage_details(): void {
		wp_set_current_user( $this->owner );

		$response = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $this->placement_id ) );
		$body     = (string) wp_json_encode( $response->get_data() );

		foreach (
			array(
				Creative_Repository::META_PRIVATE_PATH,
				Creative_Repository::META_PRIVATE_TOKEN,
				Creative_Repository::META_SHA256,
				'aggr-private',
			) as $secret
		) {
			$this->assertStringNotContainsString( $secret, $body );
		}

		$stored = (string) get_post_meta( (int) $response->get_data()['id'], Creative_Repository::META_PRIVATE_PATH, true );

		$this->assertNotSame( '', $stored );
		$this->assertStringNotContainsString( $stored, $body );
	}

	/**
	 * **Another organization cannot upload into this campaign.**
	 *
	 * A write, so 403 is correct here — the caller already knows the campaign
	 * exists, because they are trying to add to it.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_upload(): void {
		wp_set_current_user( $this->stranger );

		$response = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $this->placement_id ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'aggr_forbidden', $response->get_data()['code'] );
	}

	/**
	 * A placement the campaign has not selected is refused.
	 *
	 * @return void
	 */
	public function test_an_unselected_placement_is_refused(): void {
		$other = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other, Placement_Repository::META_IS_ACTIVE, 1 );

		wp_set_current_user( $this->owner );

		$response = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $other ) );

		$this->assertSame( 422, $response->get_status() );
	}

	/**
	 * An SVG is refused by the route, not merely by the rules.
	 *
	 * @return void
	 */
	public function test_the_route_refuses_an_svg(): void {
		wp_set_current_user( $this->owner );

		$temp = wp_tempnam( 'aggr-svg' );
		file_put_contents( $temp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
		$this->temporary[] = $temp;

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $this->campaign_id . '/creatives' );
		$request->set_body_params(
			array(
				'placement_id' => $this->placement_id,
				'click_url'    => 'https://example.com/exhibition',
				'alt_text'     => 'Gallery exhibition artwork',
			)
		);
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'logo.svg',
					'tmp_name' => $temp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => 64,
				),
			)
		);

		$this->assertSame( 422, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Uploading to a campaign that is no longer editable is refused.
	 *
	 * @return void
	 */
	public function test_uploading_to_a_submitted_campaign_is_refused(): void {
		wp_update_post(
			array(
				'ID'          => $this->campaign_id,
				'post_status' => Post_Statuses::SUBMITTED,
			)
		);

		wp_set_current_user( $this->owner );

		$response = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $this->placement_id ) );

		$this->assertSame( 409, $response->get_status() );
	}

	/**
	 * **An advertiser POSTing an approval is denied, not accepted.**
	 *
	 * Deliberately not rejected by schema validation: it is an expected event
	 * that must reach the state machine so the denial is recorded.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_approve_their_own_campaign(): void {
		wp_update_post(
			array(
				'ID'          => $this->campaign_id,
				'post_status' => Post_Statuses::REVIEW,
			)
		);

		wp_set_current_user( $this->owner );

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $this->campaign_id . '/transitions' );
		$request->set_body_params( array( 'to' => Post_Statuses::APPROVED ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( Post_Statuses::REVIEW, get_post_status( $this->campaign_id ) );
	}

	/**
	 * An illegal edge is a conflict, not a bad request: the body is
	 * well-formed and the campaign's state is what makes it impossible.
	 *
	 * @return void
	 */
	public function test_an_illegal_transition_is_a_conflict(): void {
		wp_set_current_user( $this->reviewer );

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $this->campaign_id . '/transitions' );
		$request->set_body_params( array( 'to' => Post_Statuses::COMPLETE ) );

		$this->assertSame( 409, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * A status that is not one of ours never reaches the state machine.
	 *
	 * @return void
	 */
	public function test_an_unknown_status_is_rejected_by_the_schema(): void {
		wp_set_current_user( $this->reviewer );

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $this->campaign_id . '/transitions' );
		$request->set_body_params( array( 'to' => 'publish' ) );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Sending a campaign back without feedback is refused by the guard.
	 *
	 * @return void
	 */
	public function test_sending_back_without_feedback_is_refused(): void {
		wp_update_post(
			array(
				'ID'          => $this->campaign_id,
				'post_status' => Post_Statuses::REVIEW,
			)
		);

		wp_set_current_user( $this->reviewer );

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $this->campaign_id . '/transitions' );
		$request->set_body_params( array( 'to' => Post_Statuses::CHANGES ) );

		$this->assertSame( 422, rest_get_server()->dispatch( $request )->get_status() );

		$with_notes = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $this->campaign_id . '/transitions' );
		$with_notes->set_body_params(
			array(
				'to'           => Post_Statuses::CHANGES,
				'review_notes' => 'The leaderboard is 1200x400, not 728x90.',
			)
		);

		$response = rest_get_server()->dispatch( $with_notes );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Post_Statuses::CHANGES, $response->get_data()['status'] );
	}

	/**
	 * A logged-out visitor reaches neither route.
	 *
	 * @return void
	 */
	public function test_logged_out_visitors_are_refused(): void {
		wp_set_current_user( 0 );

		$upload = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $this->placement_id ) );
		$this->assertContains( $upload->get_status(), array( 401, 403 ) );

		$transition = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $this->campaign_id . '/transitions' );
		$transition->set_body_params( array( 'to' => Post_Statuses::SUBMITTED ) );

		$this->assertContains( rest_get_server()->dispatch( $transition )->get_status(), array( 401, 403 ) );

		$delete = new WP_REST_Request( 'DELETE', '/aggr/v1/creatives/999999' );
		$this->assertContains( rest_get_server()->dispatch( $delete )->get_status(), array( 401, 403 ) );
	}

	/**
	 * The upload limit bites, and says when to come back.
	 *
	 * Generous by design — an advertiser correcting a rejected campaign at
	 * 11pm must never meet it — so the test spends the allowance directly
	 * rather than by uploading thirty files.
	 *
	 * @return void
	 */
	public function test_the_upload_limit_bites(): void {
		$limiter = Plugin::instance()->container()->get( Rate_Limiter::class );

		$limit = Rate_Limiter::limit_for( Rate_Limiter::ACTION_UPLOAD );

		for ( $i = 0; $i < $limit; $i++ ) {
			$this->assertTrue( $limiter->attempt( Rate_Limiter::ACTION_UPLOAD, $this->owner ) );
		}

		wp_set_current_user( $this->owner );

		$response = rest_get_server()->dispatch( $this->upload_request( $this->campaign_id, $this->placement_id ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertGreaterThan( 0, $response->get_data()['data']['retry_after'] );
	}

	/**
	 * One user's limit is not another's.
	 *
	 * @return void
	 */
	public function test_the_limit_is_per_user(): void {
		$limiter = Plugin::instance()->container()->get( Rate_Limiter::class );

		$limit = Rate_Limiter::limit_for( Rate_Limiter::ACTION_TRANSITION );

		for ( $i = 0; $i < $limit; $i++ ) {
			$limiter->attempt( Rate_Limiter::ACTION_TRANSITION, $this->owner );
		}

		$this->assertInstanceOf(
			\WP_Error::class,
			$limiter->attempt( Rate_Limiter::ACTION_TRANSITION, $this->owner )
		);

		$this->assertTrue( $limiter->attempt( Rate_Limiter::ACTION_TRANSITION, $this->reviewer ) );
	}

	/**
	 * Hitting the limit is recorded, since a denial is the interesting record.
	 *
	 * @return void
	 */
	public function test_hitting_the_limit_is_audited(): void {
		global $wpdb;

		$limiter = Plugin::instance()->container()->get( Rate_Limiter::class );
		$audit   = new Audit_Repository();

		$limit = Rate_Limiter::limit_for( Rate_Limiter::ACTION_AUTOSAVE );

		for ( $i = 0; $i <= $limit; $i++ ) {
			$limiter->attempt( Rate_Limiter::ACTION_AUTOSAVE, $this->owner );
		}

		$table = $audit->table_name();

		$rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event = %s AND actor_user_id = %d",
				'rate_limit.exceeded',
				$this->owner
			)
		);

		$this->assertGreaterThan( 0, $rows );
	}
}
