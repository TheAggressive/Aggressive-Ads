<?php
/**
 * The staff review routes.
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
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The review surface over HTTP, and the gate it had to grow to get there.
 *
 * `Review_Data::campaign()` has no capability check of its own. It returns the
 * staff-only internal notes, private creative previews and the audit timeline,
 * and it was safe only because `Review_Screen::render()` was its single caller
 * and gated it. Putting it behind a route is exactly the change that would turn
 * an ungated read model into a disclosure, so the gate is what most of this
 * class is about.
 */
final class ReviewRoutesTest extends WP_UnitTestCase {

	private const QUEUE = '/aggr/v1/review/queue';

	/**
	 * A reviewer.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * An advertiser who owns the fixture campaign.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * Fixture organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Fixture campaign, in review.
	 *
	 * @var int
	 */
	private int $campaign;

	/**
	 * Installs roles, one organization, one campaign and the routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->campaigns  = Plugin::instance()->container()->get( Campaign_Repository::class );
		$this->reviewer   = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$this->campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::REVIEW,
				'post_author' => $this->advertiser,
				'post_title'  => 'Spring launch',
			)
		);

		update_post_meta( $this->campaign, Campaign_Repository::META_ORG_ID, $this->org_id );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Dispatches one request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route path.
	 * @param array<string, mixed> $body   JSON body, for writes.
	 * @param array<string, mixed> $query  Query parameters.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		// Set as parameters rather than appended to the path: dispatch() matches
		// the route literally, so "?filter=x" in the path is part of the route
		// it fails to find rather than a query it reads.
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( array() !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The campaign route for the fixture.
	 *
	 * @param string $suffix Optional sub-path.
	 * @return string
	 */
	private function campaign_route( string $suffix = '' ): string {
		return '/aggr/v1/review/campaigns/' . $this->campaign . $suffix;
	}

	/**
	 * Every review route is registered.
	 *
	 * @return void
	 */
	public function test_the_review_routes_exist(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::QUEUE, $routes );
		$this->assertArrayHasKey( '/aggr/v1/review/campaigns/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/aggr/v1/review/campaigns/(?P<id>\d+)/notes', $routes );
		$this->assertArrayHasKey( '/aggr/v1/review/campaigns/(?P<id>\d+)/changes', $routes );
		$this->assertArrayHasKey( '/aggr/v1/review/campaigns/(?P<id>\d+)/request', $routes );

		// Staff decisions on an ad replacement are not here. That route already
		// existed on Creative_Controller behind the same capability, and a
		// second path to one workflow is two paths to keep in agreement.
		$this->assertArrayNotHasKey( '/aggr/v1/review/replacements/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/aggr/v1/creative-replacements/(?P<id>\d+)/decision', $routes );
	}

	/**
	 * A reviewer reads the queue with its tab counts.
	 *
	 * @return void
	 */
	public function test_a_reviewer_reads_the_queue(): void {
		wp_set_current_user( $this->reviewer );

		$response = $this->call( 'GET', self::QUEUE );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertSame( 'pending', $data['filter'] );
		$this->assertArrayHasKey( 'tabs', $data );
		$this->assertContains( $this->campaign, array_column( $data['queue']['rows'], 'id' ) );
	}

	/**
	 * An unknown filter falls back rather than failing.
	 *
	 * A stale bookmark should show the default queue, not an error the reader
	 * cannot act on.
	 *
	 * @return void
	 */
	public function test_an_unknown_filter_falls_back_to_the_default(): void {
		wp_set_current_user( $this->reviewer );

		$response = $this->call( 'GET', self::QUEUE, array(), array( 'filter' => 'not-a-tab' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()['filter'] );
	}

	/**
	 * A reviewer reads one campaign in full.
	 *
	 * @return void
	 */
	public function test_a_reviewer_reads_one_campaign(): void {
		wp_set_current_user( $this->reviewer );

		$this->campaigns->set_internal_notes( $this->campaign, 'Staff only.' );

		$response = $this->call( 'GET', $this->campaign_route() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertSame( $this->campaign, $data['campaign']['id'] );
		$this->assertSame( 'Staff only.', $data['campaign']['internal_notes'] );
	}

	/**
	 * **An advertiser cannot read the review view of their own campaign.**
	 *
	 * This is the assertion the whole controller exists for. The payload carries
	 * internal notes, private creative previews and the audit timeline; owning
	 * the campaign is not permission to see the staff view of it.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_read_the_review_view(): void {
		wp_set_current_user( $this->advertiser );

		$this->campaigns->set_internal_notes( $this->campaign, 'Never leaves the admin.' );

		// The fixture is only real if this advertiser genuinely owns the
		// campaign — a denial against somebody else's campaign would prove
		// tenancy, not the staff gate.
		$this->assertTrue( current_user_can( 'edit_aggr_campaign', $this->campaign ) );
		$this->assertFalse( current_user_can( Capabilities::REVIEW_CAMPAIGNS ) );

		$response = $this->call( 'GET', $this->campaign_route() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertStringNotContainsString(
			'Never leaves the admin.',
			(string) wp_json_encode( $response->get_data() )
		);
	}

	/**
	 * An anonymous caller is refused the queue and the campaign alike.
	 *
	 * @return void
	 */
	public function test_an_anonymous_caller_is_refused(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->call( 'GET', self::QUEUE )->get_status() );
		$this->assertSame( 401, $this->call( 'GET', $this->campaign_route() )->get_status() );
	}

	/**
	 * **The read gate refuses without the review capability.**
	 *
	 * Called directly, so the gate is what is under test rather than whatever
	 * else a dispatch happens to reject first.
	 *
	 * @return void
	 */
	public function test_the_gate_refuses_without_the_review_capability(): void {
		$controller = Plugin::instance()->container()->get( \Aggressive\Ads\REST\Review_Controller::class );

		wp_set_current_user( $this->reviewer );
		$this->assertTrue( $controller->permission() );

		wp_set_current_user( $this->advertiser );
		$this->assertFalse( $controller->permission() );

		wp_set_current_user( 0 );
		$this->assertFalse( $controller->permission() );
	}

	/**
	 * An id that is not a campaign is a 404, not an empty payload.
	 *
	 * @return void
	 */
	public function test_an_unknown_campaign_is_not_found(): void {
		wp_set_current_user( $this->reviewer );

		$response = $this->call( 'GET', '/aggr/v1/review/campaigns/' . ( $this->campaign + 9999 ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Internal notes save, and come back on the refreshed campaign.
	 *
	 * @return void
	 */
	public function test_a_reviewer_saves_internal_notes(): void {
		wp_set_current_user( $this->reviewer );

		$response = $this->call(
			'POST',
			$this->campaign_route( '/notes' ),
			array( 'notes' => 'Confirm destination with sales.' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			'Confirm destination with sales.',
			$this->campaigns->internal_notes( $this->campaign )
		);
		$this->assertSame(
			'Confirm destination with sales.',
			$response->get_data()['campaign']['internal_notes']
		);
	}

	/**
	 * **An advertiser cannot write internal notes.**
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_write_internal_notes(): void {
		wp_set_current_user( $this->advertiser );

		$response = $this->call(
			'POST',
			$this->campaign_route( '/notes' ),
			array( 'notes' => 'Advertiser overwrite.' )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', $this->campaigns->internal_notes( $this->campaign ) );
	}

	/**
	 * An unknown decision is the workflow's error, not a schema refusal.
	 *
	 * Letting it reach `process()` is deliberate: the message the reader sees is
	 * then the one the workflow author wrote, rather than a validation string
	 * nobody chose.
	 *
	 * @return void
	 */
	public function test_an_unknown_decision_returns_the_workflow_error(): void {
		wp_set_current_user( $this->reviewer );

		$response = $this->call(
			'POST',
			$this->campaign_route( '/changes' ),
			array( 'decision' => 'maybe' )
		);
		$data     = $response->get_data();

		$this->assertSame( 422, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertSame( 'aggr_change_decision_invalid', $data['code'] );
	}

	/**
	 * Declining a request the campaign does not have is a 404, not a silent ok.
	 *
	 * @return void
	 */
	public function test_declining_a_request_that_does_not_exist_is_reported(): void {
		wp_set_current_user( $this->reviewer );

		$response = $this->call(
			'POST',
			$this->campaign_route( '/request' ),
			array( 'notes' => 'Not this time.' )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'aggr_no_action_request', $response->get_data()['code'] );
	}

	/**
	 * **Every review route refuses an advertiser.**
	 *
	 * One assertion per route, because a gate is per-route: adding a seventh
	 * with the wrong permission callback is exactly the mistake this catches.
	 *
	 * @return void
	 */
	public function test_every_review_route_refuses_an_advertiser(): void {
		wp_set_current_user( $this->advertiser );

		$calls = array(
			array( 'GET', self::QUEUE ),
			array( 'GET', $this->campaign_route() ),
			array( 'POST', $this->campaign_route( '/notes' ) ),
			array( 'POST', $this->campaign_route( '/changes' ) ),
			array( 'POST', $this->campaign_route( '/request' ) ),
		);

		foreach ( $calls as $call ) {
			list( $method, $route ) = $call;

			$this->assertSame(
				403,
				$this->call( $method, $route, array( 'decision' => 'approve' ) )->get_status(),
				$method . ' ' . $route . ' did not refuse an advertiser.'
			);
		}
	}
}
