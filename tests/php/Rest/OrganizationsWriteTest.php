<?php
/**
 * The organization state route.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\REST\Organizations_Controller;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Organization_State_Manager;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * What replaced the suspension form.
 *
 * Suspension stops every campaign an organization is running, so it is the
 * highest-consequence write on any staff screen. The assertions here are the
 * ones the deleted handler carried: who is refused, and that the state
 * vocabulary cannot be widened by the request.
 */
final class OrganizationsWriteTest extends WP_UnitTestCase {

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $administrator;

	/**
	 * The organization's own advertiser.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * The organization under test.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Organization persistence.
	 *
	 * @var Org_Repository
	 */
	private Org_Repository $organizations;

	/**
	 * Installs roles, one organization, and the routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$container           = Plugin::instance()->container();
		$this->organizations = $container->get( Org_Repository::class );

		( new Installer( $container->get( Audit_Repository::class ), new Roles() ) )->install_roles();

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->advertiser    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$org_id = $this->organizations->create_for_owner( 'Bright Angle', $this->advertiser );
		$this->assertIsInt( $org_id );
		$this->org_id = $org_id;

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Dispatches one state change.
	 *
	 * @param int    $org_id Organization id.
	 * @param string $state  Requested state.
	 * @return WP_REST_Response
	 */
	private function set_state( int $org_id, string $state ): WP_REST_Response {
		return $this->call( 'POST', '/aggr/v1/organizations/' . $org_id . '/state', array( 'state' => $state ) );
	}

	/**
	 * Dispatches one request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route path.
	 * @param array<string, mixed> $body   Request body.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $route, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The route is registered.
	 *
	 * @return void
	 */
	public function test_the_route_exists(): void {
		$this->assertArrayHasKey(
			'/aggr/v1/organizations/(?P<id>\d+)/state',
			rest_get_server()->get_routes()
		);
	}

	/**
	 * An administrator can suspend and reactivate.
	 *
	 * @return void
	 */
	public function test_an_administrator_can_change_state(): void {
		wp_set_current_user( $this->administrator );

		$this->assertSame( 200, $this->set_state( $this->org_id, Org_Repository::STATE_SUSPENDED )->get_status() );
		$this->assertFalse( $this->organizations->is_active( $this->org_id ) );

		$response = $this->set_state( $this->org_id, Org_Repository::STATE_ACTIVE );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->organizations->is_active( $this->org_id ) );
		$this->assertArrayHasKey( 'view', $response->get_data() );
	}

	/**
	 * An advertiser cannot suspend their own organization.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_change_state(): void {
		wp_set_current_user( $this->advertiser );

		$this->assertSame( 403, $this->set_state( $this->org_id, Org_Repository::STATE_SUSPENDED )->get_status() );
		$this->assertTrue( $this->organizations->is_active( $this->org_id ) );
	}

	/**
	 * Logged out is refused before the body is read.
	 *
	 * @return void
	 */
	public function test_an_anonymous_caller_cannot_change_state(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->set_state( $this->org_id, Org_Repository::STATE_SUSPENDED )->get_status() );
		$this->assertTrue( $this->organizations->is_active( $this->org_id ) );
	}

	/**
	 * The route's own gate refuses, independently of the workflow behind it.
	 *
	 * Asserted directly because the two checks mask each other: the workflow
	 * repeats the capability check for its audit trail, so deleting the
	 * permission_callback still yields 403 — the request simply travels further
	 * before being refused.
	 *
	 * @return void
	 */
	public function test_the_gate_refuses_without_the_capability(): void {
		$controller = Plugin::instance()->container()->get( Organizations_Controller::class );

		wp_set_current_user( 0 );
		$this->assertFalse( $controller->permission() );

		wp_set_current_user( $this->advertiser );
		$this->assertFalse( $controller->permission() );

		wp_set_current_user( $this->administrator );
		$this->assertTrue( $controller->permission() );
	}

	/**
	 * A state the system does not have is refused before anything is written.
	 *
	 * Org_Repository stores whatever string it is handed and every later read
	 * compares against the two known constants, so an accepted third value would
	 * not error — it would quietly make the organization neither active nor
	 * suspended, and `is_active()` false forever.
	 *
	 * @return void
	 */
	public function test_an_unknown_state_is_refused(): void {
		wp_set_current_user( $this->administrator );

		$this->assertSame( 400, $this->set_state( $this->org_id, 'deleted' )->get_status() );
		$this->assertSame( Org_Repository::STATE_ACTIVE, $this->organizations->state( $this->org_id ) );

		/*
		 * And again against the workflow directly, because the two guards mask
		 * each other. The route declares an `enum`, so a bad state never reaches
		 * set_state() through HTTP and the assertion above would still pass with
		 * the workflow's allowlist deleted. set_state() is public now — anything
		 * in the plugin can call it — so it has to refuse on its own.
		 */
		$result = Plugin::instance()
			->container()
			->get( Organization_State_Manager::class )
			->set_state( $this->org_id, 'deleted' );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_invalid_org_state', $result->get_error_code() );
		$this->assertSame( Org_Repository::STATE_ACTIVE, $this->organizations->state( $this->org_id ) );
	}

	/**
	 * Staff can rename an organization.
	 *
	 * @return void
	 */
	public function test_an_administrator_can_rename(): void {
		wp_set_current_user( $this->administrator );

		$response = $this->call( 'PATCH', '/aggr/v1/organizations/' . $this->org_id, array( 'name' => 'Bright Angle Studio' ) );

		$this->assertSame( 200, $response->get_status() );

		// Org_Repository normalizes names to upper case on write, so that the
		// duplicate-name check cannot be defeated by capitalization. The route
		// passes the name through untouched and the repository decides.
		$this->assertSame( 'BRIGHT ANGLE STUDIO', $this->organizations->name( $this->org_id ) );
	}

	/**
	 * Staff can move ownership to another member.
	 *
	 * @return void
	 */
	public function test_an_administrator_can_transfer_ownership(): void {
		$second = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->organizations->add_member( $this->org_id, $second );

		wp_set_current_user( $this->administrator );

		$response = $this->call( 'POST', '/aggr/v1/organizations/' . $this->org_id . '/owner', array( 'user_id' => $second ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $second, $this->organizations->owner_user_id( $this->org_id ) );
	}

	/**
	 * Staff can remove a member, but never the owner.
	 *
	 * Removing the owner would leave an organization nobody can administer from
	 * the portal side, so the workflow refuses it and asks for a transfer first.
	 *
	 * @return void
	 */
	public function test_a_member_can_be_removed_but_the_owner_cannot(): void {
		$second = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->organizations->add_member( $this->org_id, $second );

		wp_set_current_user( $this->administrator );

		$owner = $this->call( 'DELETE', '/aggr/v1/organizations/' . $this->org_id . '/members/' . $this->advertiser, array() );

		$this->assertSame( 400, $owner->get_status() );
		$this->assertSame( 'aggr_cannot_remove_owner', $owner->get_data()['code'] );
		$this->assertContains( $this->advertiser, $this->organizations->user_ids_for_org( $this->org_id ) );

		$member = $this->call( 'DELETE', '/aggr/v1/organizations/' . $this->org_id . '/members/' . $second, array() );

		$this->assertSame( 200, $member->get_status() );
		$this->assertNotContains( $second, $this->organizations->user_ids_for_org( $this->org_id ) );
	}

	/**
	 * The roster travels back with every membership change.
	 *
	 * @return void
	 */
	public function test_the_response_carries_the_member_list(): void {
		wp_set_current_user( $this->administrator );

		$data = $this->call( 'PATCH', '/aggr/v1/organizations/' . $this->org_id, array( 'name' => 'Renamed' ) )->get_data();
		$row  = null;

		foreach ( $data['view']['rows'] as $candidate ) {
			if ( $this->org_id === $candidate['id'] ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertIsArray( $row );
		$this->assertSame( array( $this->advertiser ), array_column( $row['member_list'], 'id' ) );
		$this->assertTrue( $row['member_list'][0]['is_owner'] );
	}

	/**
	 * Every roster route refuses a caller without the capability.
	 *
	 * @return void
	 */
	public function test_no_roster_route_is_open_to_an_advertiser(): void {
		$second = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->organizations->add_member( $this->org_id, $second );

		wp_set_current_user( $second );

		$base = '/aggr/v1/organizations/' . $this->org_id;

		$this->assertSame( 403, $this->call( 'PATCH', $base, array( 'name' => 'Hijacked' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $base . '/owner', array( 'user_id' => $second ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $base . '/members', array( 'email' => 'x@example.com' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', $base . '/members/' . $this->advertiser, array() )->get_status() );

		$this->assertSame( 'BRIGHT ANGLE', $this->organizations->name( $this->org_id ) );
		$this->assertSame( $this->advertiser, $this->organizations->owner_user_id( $this->org_id ) );
	}

	/**
	 * The audit records the person who actually made the change.
	 *
	 * The actor is passed to the workflow by the controller rather than read
	 * from the session there, so nothing except this assertion stops the two
	 * from disagreeing. Every other test on this route passes with the actor
	 * hardcoded, because the capability gate has already excluded everyone who
	 * is not staff — but an audit trail that names the wrong member of staff is
	 * worse than none, since it is the record an incident is reconstructed from.
	 *
	 * @return void
	 */
	public function test_the_audit_names_the_caller_not_a_default_user(): void {
		$second_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $second_admin );

		$this->assertSame(
			200,
			$this->call( 'PATCH', '/aggr/v1/organizations/' . $this->org_id, array( 'name' => 'Audited' ) )->get_status()
		);

		$events = Plugin::instance()
			->container()
			->get( Audit_Repository::class )
			->for_object( 'organization', $this->org_id, $this->org_id );

		$this->assertNotEmpty( $events );
		$this->assertSame( 'organization.renamed', $events[0]['event'] );
		$this->assertSame( $second_admin, (int) $events[0]['actor_user_id'] );
		$this->assertNotSame( $this->administrator, (int) $events[0]['actor_user_id'] );
	}

	/**
	 * The workflow refuses an id that is not an organization.
	 *
	 * @return void
	 */
	public function test_a_missing_organization_is_reported(): void {
		wp_set_current_user( $this->administrator );

		$this->assertSame( 404, $this->set_state( $this->org_id + 4242, Org_Repository::STATE_SUSPENDED )->get_status() );
	}
}
