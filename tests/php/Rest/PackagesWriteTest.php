<?php
/**
 * The staff catalogue write routes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\REST\Packages_Controller;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * What replaced the catalogue's admin-post handlers.
 *
 * The screen moved to React and its forms went with it, so these routes are now
 * the only way to change what advertisers can buy. The assertions that matter
 * are therefore the ones the deleted handlers used to carry: who is refused,
 * and that the workflow's own rules still decide, rather than the controller.
 */
final class PackagesWriteTest extends WP_UnitTestCase {

	private const CREATE = '/aggr/v1/packages/catalogue';

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $administrator;

	/**
	 * A reviewer, who may not manage packages.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * An advertiser.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * An active placement with a usable size.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Installs roles, one placement, and the routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->reviewer      = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * A package the workflow accepts.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_package(): array {
		return array(
			'name'            => 'Leaderboard month',
			'placement_ids'   => array( $this->placement_id ),
			'duration_days'   => 30,
			'custom_duration' => false,
			'price_cents'     => 25000,
			'currency'        => 'USD',
			'is_active'       => true,
			'is_default'      => false,
		);
	}

	/**
	 * Dispatches one write.
	 *
	 * @param string               $route  Route path.
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $body   Request body.
	 * @return WP_REST_Response
	 */
	private function write( string $route, string $method, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Both routes are registered.
	 *
	 * @return void
	 */
	public function test_the_write_routes_exist(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::CREATE, $routes );
		$this->assertArrayHasKey( '/aggr/v1/packages/(?P<id>\d+)', $routes );
	}

	/**
	 * An administrator can create a package, and it lands in the catalogue.
	 *
	 * @return void
	 */
	public function test_an_administrator_can_create_a_package(): void {
		wp_set_current_user( $this->administrator );

		$response = $this->write( self::CREATE, 'POST', $this->valid_package() );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertGreaterThan( 0, $data['id'] );

		$packages = Plugin::instance()->container()->get( Package_Repository::class );

		$this->assertSame( 'Leaderboard month', $packages->name( (int) $data['id'] ) );
		$this->assertSame( 25000, $packages->price_cents( (int) $data['id'] ) );
	}

	/**
	 * The response carries the catalogue the server now holds.
	 *
	 * The screen renders from this rather than from a local guess, because a
	 * write can move more than it sent: promoting one package to default demotes
	 * whichever held it, and only the server knows which.
	 *
	 * @return void
	 */
	public function test_the_response_returns_the_refreshed_catalogue(): void {
		wp_set_current_user( $this->administrator );

		$first = $this->write( self::CREATE, 'POST', $this->valid_package() )->get_data();

		$second_input               = $this->valid_package();
		$second_input['name']       = 'Second';
		$second_input['is_default'] = true;

		$response = $this->write( self::CREATE, 'POST', $second_input );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'view', $data );

		$defaults = array();

		foreach ( $data['view']['rows'] as $row ) {
			if ( $row['is_default'] ) {
				$defaults[] = $row['id'];
			}
		}

		$this->assertSame( array( (int) $data['id'] ), $defaults, 'Exactly one package may be the default.' );
		$this->assertNotSame( (int) $first['id'], $defaults[0] );
	}

	/**
	 * A reviewer is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function test_a_reviewer_cannot_create_a_package(): void {
		wp_set_current_user( $this->reviewer );

		$this->assertSame( 403, $this->write( self::CREATE, 'POST', $this->valid_package() )->get_status() );
		$this->assertSame(
			array(),
			Plugin::instance()->container()->get( Package_Repository::class )->all_ids()
		);
	}

	/**
	 * An advertiser is refused too.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_create_a_package(): void {
		wp_set_current_user( $this->advertiser );

		$this->assertSame( 403, $this->write( self::CREATE, 'POST', $this->valid_package() )->get_status() );
	}

	/**
	 * Logged out is refused before the body is read.
	 *
	 * @return void
	 */
	public function test_an_anonymous_caller_cannot_create_a_package(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->write( self::CREATE, 'POST', $this->valid_package() )->get_status() );
	}

	/**
	 * The workflow's rules still decide, and their wording reaches the client.
	 *
	 * An active package with no placement is rejected by Package_Manager, not by
	 * anything in the controller. If this ever passes, the route has grown a
	 * rule set of its own and the integration tests no longer cover what ships.
	 *
	 * @return void
	 */
	public function test_an_active_package_without_a_placement_is_rejected(): void {
		wp_set_current_user( $this->administrator );

		$input                  = $this->valid_package();
		$input['placement_ids'] = array();

		$response = $this->write( self::CREATE, 'POST', $input );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aggr_invalid_package_placements', $response->get_data()['code'] );
		$this->assertSame(
			array(),
			Plugin::instance()->container()->get( Package_Repository::class )->all_ids()
		);
	}

	/**
	 * A bad currency is rejected with the workflow's own code.
	 *
	 * @return void
	 */
	public function test_a_malformed_currency_is_rejected(): void {
		wp_set_current_user( $this->administrator );

		$input             = $this->valid_package();
		$input['currency'] = 'dollars';

		$response = $this->write( self::CREATE, 'POST', $input );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aggr_invalid_package_currency', $response->get_data()['code'] );
	}

	/**
	 * Update changes an existing package and refuses an id that is not one.
	 *
	 * @return void
	 */
	public function test_update_saves_and_reports_a_missing_package(): void {
		wp_set_current_user( $this->administrator );

		$created = $this->write( self::CREATE, 'POST', $this->valid_package() )->get_data();
		$id      = (int) $created['id'];

		$input         = $this->valid_package();
		$input['name'] = 'Renamed';

		$this->assertSame( 200, $this->write( '/aggr/v1/packages/' . $id, 'PATCH', $input )->get_status() );
		$this->assertSame(
			'Renamed',
			Plugin::instance()->container()->get( Package_Repository::class )->name( $id )
		);

		/*
		 * 403, not 404, and that is the workflow's existing answer rather than
		 * this route's. Package_Manager checks `edit_aggr_package` against the
		 * id before asking whether the id exists, and a capability check on a
		 * post that is not there is false. The deleted admin-post handler
		 * behaved identically; asserting the real answer keeps the test honest
		 * about which layer decided.
		 */
		$missing = $this->write( '/aggr/v1/packages/' . ( $id + 4242 ), 'PATCH', $input );

		$this->assertSame( 403, $missing->get_status() );
	}

	/**
	 * The route's own gate refuses, independently of the workflow behind it.
	 *
	 * This is asserted directly rather than through a dispatch because the two
	 * checks mask each other. Package_Manager repeats the capability check for
	 * its own audit trail, so deleting the permission_callback entirely still
	 * produces 403 for a reviewer — the request simply gets further in before
	 * being refused. Calling the callback is the only way to see it work.
	 *
	 * @return void
	 */
	public function test_the_write_gate_refuses_without_the_capability(): void {
		$controller = Plugin::instance()->container()->get( Packages_Controller::class );

		wp_set_current_user( 0 );
		$this->assertFalse( $controller->write_permission() );

		wp_set_current_user( $this->advertiser );
		$this->assertFalse( $controller->write_permission() );

		wp_set_current_user( $this->reviewer );
		$this->assertFalse( $controller->write_permission() );

		wp_set_current_user( $this->administrator );
		$this->assertTrue( $controller->write_permission() );
	}

	/**
	 * Reading the catalogue is a different gate from changing it.
	 *
	 * @return void
	 */
	public function test_reading_and_writing_are_separate_gates(): void {
		$controller = Plugin::instance()->container()->get( Packages_Controller::class );

		wp_set_current_user( $this->advertiser );

		$this->assertTrue( $controller->permission(), 'Advertisers select from the catalogue.' );
		$this->assertFalse( $controller->write_permission(), 'Advertisers do not price it.' );
	}
}
