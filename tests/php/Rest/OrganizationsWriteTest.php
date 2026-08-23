<?php
/**
 * The organization state route.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Types;
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
	 * It rides on `organization`, not on the list rows. The list is paged and
	 * shows a member count, so shipping every row's roster to render it sent
	 * the whole directory — names and email addresses — on every read. The
	 * affected organization still comes back whole, because the modal that
	 * made the change is open on it.
	 *
	 * @return void
	 */
	public function test_the_response_carries_the_member_list(): void {
		wp_set_current_user( $this->administrator );

		$data = $this->call( 'PATCH', '/aggr/v1/organizations/' . $this->org_id, array( 'name' => 'Renamed' ) )->get_data();

		$this->assertIsArray( $data['organization'] );
		$this->assertSame( $this->org_id, $data['organization']['id'] );
		$this->assertSame( array( $this->advertiser ), array_column( $data['organization']['member_list'], 'id' ) );
		$this->assertTrue( $data['organization']['member_list'][0]['is_owner'] );

		// And the list rows must not carry it, which is the point of the change.
		foreach ( $data['view']['rows'] as $candidate ) {
			$this->assertArrayNotHasKey( 'member_list', $candidate );
		}
	}

	/**
	 * The list pages, and reports the total it paged through.
	 *
	 * The screen used to receive every organization and sift them in the
	 * browser, capped at 500 with nothing in the payload saying so. A search
	 * for the 501st returned nothing, which reads as "no such organization".
	 *
	 * @return void
	 */
	public function test_the_list_pages_and_reports_the_real_total(): void {
		wp_set_current_user( $this->administrator );

		/*
		 * Organizations, not invitations.
		 *
		 * The first version of this created three *members* of the one existing
		 * organization, so it ran against a single row and passed with paging
		 * deleted outright — `per_page => 1` cannot be seen to work against a
		 * one-row table.
		 */
		for ( $i = 0; $i < 3; $i++ ) {
			self::factory()->post->create(
				array(
					'post_type'  => Post_Types::ORGANIZATION,
					'post_title' => 'PAGING FIXTURE ' . $i,
				)
			);
		}

		$total = $this->count_all_organizations();

		// Assert the fixture is real before asserting on it.
		$this->assertGreaterThanOrEqual( 4, $total );

		$first = $this->call(
			'GET',
			'/aggr/v1/organizations',
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);

		$this->assertSame( 200, $first->get_status() );

		$view = $first->get_data()['view'];

		// One row out of four or more. A per_page the query ignored would
		// return every organization and still look like a successful page.
		$this->assertCount( 1, $view['rows'] );
		$this->assertSame( 1, $view['page'] );
		$this->assertSame( $total, $view['total'] );

		// And page two must hold a different organization, or "paging" is one
		// page repeated.
		$second = $this->call(
			'GET',
			'/aggr/v1/organizations',
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		);

		$second_view = $second->get_data()['view'];

		$this->assertCount( 1, $second_view['rows'] );
		$this->assertNotSame( $view['rows'][0]['id'], $second_view['rows'][0]['id'] );
	}

	/**
	 * Sort direction reaches the query.
	 *
	 * The client can only sort by name, and the server used to hardcode ASC, so
	 * clicking the header flipped the arrow and returned the same page.
	 *
	 * @return void
	 */
	public function test_sort_direction_is_honoured(): void {
		wp_set_current_user( $this->administrator );

		foreach ( array( 'AAA PAGING FIRST', 'ZZZ PAGING LAST' ) as $name ) {
			self::factory()->post->create(
				array(
					'post_type'  => Post_Types::ORGANIZATION,
					'post_title' => $name,
				)
			);
		}

		$ascending = $this->call(
			'GET',
			'/aggr/v1/organizations',
			array(
				'order'    => 'asc',
				'per_page' => 1,
			)
		);

		$descending = $this->call(
			'GET',
			'/aggr/v1/organizations',
			array(
				'order'    => 'desc',
				'per_page' => 1,
			)
		);

		$this->assertSame( 'AAA PAGING FIRST', $ascending->get_data()['view']['rows'][0]['name'] );
		$this->assertSame( 'ZZZ PAGING LAST', $descending->get_data()['view']['rows'][0]['name'] );
	}

	/**
	 * A write does not filter the page it returns.
	 *
	 * `POST /organizations/{id}/state` carries the new state in its body, and a
	 * body parameter outranks the query string. While the list filter was also
	 * called `state`, suspending from an unfiltered list came back as a
	 * suspended-only page with a suspended-only total, and the client adopted
	 * it — so the table appeared to collapse to a single row.
	 *
	 * @return void
	 */
	public function test_suspending_does_not_filter_the_returned_page(): void {
		wp_set_current_user( $this->administrator );

		self::factory()->post->create(
			array(
				'post_type'  => Post_Types::ORGANIZATION,
				'post_title' => 'STILL ACTIVE ELSEWHERE',
			)
		);

		$total = $this->count_all_organizations();
		$this->assertGreaterThanOrEqual( 2, $total );

		$response = $this->call(
			'POST',
			'/aggr/v1/organizations/' . $this->org_id . '/state',
			array( 'state' => 'suspended' )
		);

		$this->assertSame( 200, $response->get_status() );

		// The whole list, not the suspended slice of it.
		$this->assertSame( $total, $response->get_data()['view']['total'] );
	}

	/**
	 * An organization with no state meta still counts as active.
	 *
	 * `Org_Repository::state()` returns active for anything that is not
	 * literally 'suspended', missing meta included. A filter written as
	 * `state = 'active'` would agree with it everywhere except on rows created
	 * before the meta existed, which would then vanish from the active list
	 * while continuing to report themselves active on every other screen —
	 * a disappearance with no error anywhere.
	 *
	 * @return void
	 */
	public function test_active_filter_includes_organizations_with_no_state_meta(): void {
		wp_set_current_user( $this->administrator );

		delete_post_meta( $this->org_id, Org_Repository::META_ORG_STATE );

		// Assert the fixture is really in the state the test is about, before
		// asserting anything on top of it.
		$this->assertSame( '', (string) get_post_meta( $this->org_id, Org_Repository::META_ORG_STATE, true ) );
		$this->assertSame( Org_Repository::STATE_ACTIVE, $this->organizations->state( $this->org_id ) );

		$response = $this->call( 'GET', '/aggr/v1/organizations', array( 'filter_state' => Org_Repository::STATE_ACTIVE ) );

		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['view']['rows'], 'id' );

		$this->assertContains( $this->org_id, $ids );
	}

	/**
	 * Suspended filtering returns only the suspended.
	 *
	 * The negative half: a filter that matched everything would pass the test
	 * above and be useless.
	 *
	 * @return void
	 */
	public function test_suspended_filter_excludes_active_organizations(): void {
		wp_set_current_user( $this->administrator );

		$before = array_column(
			$this->call( 'GET', '/aggr/v1/organizations', array( 'filter_state' => Org_Repository::STATE_SUSPENDED ) )->get_data()['view']['rows'],
			'id'
		);

		$this->assertNotContains( $this->org_id, $before );

		$this->assertTrue( $this->organizations->set_state( $this->org_id, Org_Repository::STATE_SUSPENDED ) );

		$after = array_column(
			$this->call( 'GET', '/aggr/v1/organizations', array( 'filter_state' => Org_Repository::STATE_SUSPENDED ) )->get_data()['view']['rows'],
			'id'
		);

		$this->assertContains( $this->org_id, $after );
	}

	/**
	 * Reproduces the browser's rename: query args plus a JSON body.
	 *
	 * @return void
	 */
	public function test_probe_rename_with_query_args(): void {
		wp_set_current_user( $this->administrator );

		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/organizations/' . $this->org_id );
		$request->set_query_params(
			array(
				'page'     => '1',
				'per_page' => '25',
				'search'   => '',
				'state'    => '',
			)
		);
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'name' => 'Probe Renamed' ) ) );

		$response = rest_get_server()->dispatch( $request );

		if ( 200 !== $response->get_status() ) {
			$data = $response->get_data();
			$this->fail( 'status ' . $response->get_status() . ' :: ' . wp_json_encode( $data ) );
		}

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Every organization the repository holds, counted independently.
	 *
	 * @return int
	 */
	private function count_all_organizations(): int {
		// found_posts rather than a big numberposts: the count must be the real
		// one, and asking for a page of rows to count them is the bug this
		// test exists to catch.
		$query = new \WP_Query(
			array(
				// The constant, not the literal: guessing the slug here counted
				// zero organizations and the assertion still read as a real
				// comparison against the route's total.
				'post_type'      => Post_Types::ORGANIZATION,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return (int) $query->found_posts;
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
