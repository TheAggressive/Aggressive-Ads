<?php
/**
 * The staff inventory write routes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Domain\Refresh_Policy;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * What replaced Placements' admin-post handlers.
 *
 * The screen moved to React and its forms went with it, so these routes are now
 * the only way to change what advertisers can buy — and a placement slug is
 * what a published page renders an ad into. The assertions that matter are the
 * ones the deleted handlers used to carry: who is refused, and that the
 * workflow's own rules still decide rather than the controller.
 */
final class PlacementsWriteTest extends WP_UnitTestCase {

	private const CREATE = '/aggr/v1/placements/catalogue';
	private const UPDATE = '/aggr/v1/placements/(?P<id>\d+)';

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $administrator;

	/**
	 * A reviewer, who may not manage placements.
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
	 * Placement persistence.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	/**
	 * Installs roles and the routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->reviewer      = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->placements = Plugin::instance()->container()->get( Placement_Repository::class );

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * A placement the workflow accepts.
	 *
	 * @param array<string, mixed> $overrides Field replacements.
	 * @return array<string, mixed>
	 */
	private function valid_placement( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'        => 'Homepage leaderboard',
				'slug'        => 'header',
				'size_preset' => '728x90',
				'sort_order'  => 0,
				'is_active'   => true,
			),
			$overrides
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
	 * Creates one placement as the administrator and returns its id.
	 *
	 * @param array<string, mixed> $overrides Field replacements.
	 * @return int
	 */
	private function create_placement( array $overrides = array() ): int {
		wp_set_current_user( $this->administrator );

		$response = $this->write( self::CREATE, 'POST', $this->valid_placement( $overrides ) );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertIsArray( $data );

		return (int) $data['id'];
	}

	/**
	 * Both routes are registered.
	 *
	 * @return void
	 */
	public function test_the_write_routes_exist(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::CREATE, $routes );
		$this->assertArrayHasKey( self::UPDATE, $routes );
	}

	/**
	 * An administrator can create a placement, and it lands in the catalogue.
	 *
	 * @return void
	 */
	public function test_an_administrator_can_create_a_placement(): void {
		$placement_id = $this->create_placement();

		$this->assertGreaterThan( 0, $placement_id );
		$this->assertSame( 'Homepage leaderboard', $this->placements->name( $placement_id ) );
		$this->assertSame( '728x90', $this->placements->size( $placement_id ) );
		$this->assertSame( 'header', $this->placements->slug( $placement_id ) );
		$this->assertTrue( $this->placements->is_active( $placement_id ) );

		$policy = $this->placements->refresh_policy( $placement_id );

		$this->assertFalse( $policy->enabled );
		$this->assertSame( Refresh_Policy::DEFAULT_INTERVAL_SECONDS, $policy->interval_seconds );
		$this->assertSame( Refresh_Policy::DEFAULT_MAX_PER_VIEW, $policy->max_per_view );
	}

	/**
	 * Breakpoints the form sends are the breakpoints the placement serves.
	 *
	 * @return void
	 */
	public function test_breakpoints_round_trip_through_the_catalogue(): void {
		$placement_id = $this->create_placement(
			array(
				'slug'        => 'responsive-header',
				'breakpoints' => array(
					0   => '320x50',
					768 => '728x90',
				),
			)
		);

		$map = $this->placements->size_map( $placement_id );

		$this->assertTrue( $map->is_responsive() );
		$this->assertSame( '320x50', $map->for_viewport( 375 ) );
		$this->assertSame( '728x90', $map->for_viewport( 1024 ) );
	}

	/**
	 * **An unrelated save does not quietly make a placement fixed again.**
	 *
	 * An omitted key means "unchanged", never "cleared" — the same rule the
	 * refresh policy and the house creative already follow. Without it a rename
	 * would write an empty map, `Size_Map` would read that as "not a map" and
	 * fall back to the single stored size, and the placement would serve its
	 * base everywhere while the screen still listed the breakpoints somebody
	 * configured. A disagreement between what the screen shows and what the
	 * server serves is the kind nobody thinks to check.
	 *
	 * @return void
	 */
	public function test_a_save_that_omits_breakpoints_leaves_them_alone(): void {
		$placement_id = $this->create_placement(
			array(
				'slug'        => 'responsive-header',
				'breakpoints' => array(
					0   => '320x50',
					768 => '728x90',
				),
			)
		);

		$response = $this->write(
			'/aggr/v1/placements/' . $placement_id,
			'PATCH',
			$this->valid_placement(
				array(
					'slug' => 'responsive-header',
					'name' => 'Renamed and nothing else',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$map = $this->placements->size_map( $placement_id );

		$this->assertTrue( $map->is_responsive(), 'A rename turned a responsive placement into a fixed one.' );
		$this->assertSame( '728x90', $map->for_viewport( 1024 ) );
	}

	/**
	 * An explicit empty list does clear them, because that is a decision.
	 *
	 * The distinction is the whole point of the rule above: silence is not a
	 * choice, and an empty array is.
	 *
	 * @return void
	 */
	public function test_an_explicit_empty_list_makes_a_placement_fixed(): void {
		$placement_id = $this->create_placement(
			array(
				'slug'        => 'responsive-header',
				'breakpoints' => array(
					0   => '320x50',
					768 => '728x90',
				),
			)
		);

		$response = $this->write(
			'/aggr/v1/placements/' . $placement_id,
			'PATCH',
			$this->valid_placement(
				array(
					'slug'        => 'responsive-header',
					'breakpoints' => array(),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->placements->size_map( $placement_id )->is_responsive() );
	}

	/**
	 * The policy the form sends is the policy the catalogue returns.
	 *
	 * @return void
	 */
	public function test_refresh_policy_round_trips_through_the_catalogue(): void {
		$placement_id = $this->create_placement(
			array(
				'slug'                 => 'rotating-header',
				'refresh_enabled'      => true,
				'refresh_seconds'      => 20,
				'refresh_max_per_view' => 3,
			)
		);

		$policy = $this->placements->refresh_policy( $placement_id );

		$this->assertTrue( $policy->enabled );
		$this->assertSame( 20, $policy->interval_seconds );
		$this->assertSame( 3, $policy->max_per_view );

		$response = $this->write(
			'/aggr/v1/placements/' . $placement_id,
			'PATCH',
			$this->valid_placement(
				array(
					'slug'                 => 'rotating-header',
					'name'                 => 'Homepage leaderboard',
					'refresh_enabled'      => true,
					'refresh_seconds'      => 45,
					'refresh_max_per_view' => 2,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		$row = array();

		foreach ( $data['view']['rows'] as $candidate ) {
			if ( (int) $candidate['id'] === $placement_id ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertSame( 45, $row['refresh_seconds'] ?? null );
		$this->assertSame( 2, $row['refresh_max_per_view'] ?? null );
	}

	/**
	 * A rename that does not mention refresh must not reset the policy.
	 *
	 * @return void
	 */
	public function test_an_update_that_omits_refresh_leaves_the_policy(): void {
		$placement_id = $this->create_placement(
			array(
				'slug'                 => 'kept-policy',
				'refresh_enabled'      => true,
				'refresh_seconds'      => 15,
				'refresh_max_per_view' => 5,
			)
		);

		$response = $this->write(
			'/aggr/v1/placements/' . $placement_id,
			'PATCH',
			$this->valid_placement(
				array(
					'slug' => 'kept-policy',
					'name' => 'Renamed, policy untouched',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$policy = $this->placements->refresh_policy( $placement_id );

		$this->assertTrue( $policy->enabled, 'A rename turned refresh off.' );
		$this->assertSame( 15, $policy->interval_seconds );
		$this->assertSame( 5, $policy->max_per_view );
	}

	/**
	 * A custom size is stored as the pixel pair it describes.
	 *
	 * @return void
	 */
	public function test_a_custom_size_is_stored_as_pixels(): void {
		$placement_id = $this->create_placement(
			array(
				'slug'        => 'custom-slot',
				'size_preset' => 'custom',
				'size_width'  => 123,
				'size_height' => 45,
			)
		);

		$this->assertSame( '123x45', $this->placements->size( $placement_id ) );
	}

	/**
	 * The response carries the catalogue the server now holds.
	 *
	 * The screen renders from this rather than from a local guess.
	 *
	 * @return void
	 */
	public function test_the_response_returns_the_refreshed_catalogue(): void {
		$this->create_placement();

		$response = $this->write(
			self::CREATE,
			'POST',
			$this->valid_placement(
				array(
					'name' => 'Sidebar',
					'slug' => 'sidebar',
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'view', $data );

		$names = array_column( $data['view']['rows'], 'name' );

		$this->assertContains( 'Homepage leaderboard', $names );
		$this->assertContains( 'Sidebar', $names );
		$this->assertArrayHasKey( '728x90', $data['view']['sizes'] );
	}

	/**
	 * An update saves; an id that is not a placement is refused, never created.
	 *
	 * The refusal is 403 rather than 404, and deliberately so. The object
	 * capability is checked against the id, and `Ownership::map()` denies a
	 * capability aimed at something that does not exist — so the caller learns
	 * they may not touch it, not whether it is there.
	 *
	 * @return void
	 */
	public function test_update_saves_and_refuses_an_unknown_id(): void {
		$placement_id = $this->create_placement();

		$response = $this->write(
			'/aggr/v1/placements/' . $placement_id,
			'PATCH',
			$this->valid_placement( array( 'name' => 'Renamed leaderboard' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Renamed leaderboard', $this->placements->name( $placement_id ) );

		$missing = $this->write(
			'/aggr/v1/placements/' . ( $placement_id + 4242 ),
			'PATCH',
			$this->valid_placement()
		);

		$this->assertSame( 403, $missing->get_status() );
		$this->assertCount( 1, $this->placements->all_ids(), 'A PATCH to an unknown id created something.' );
	}

	/**
	 * Deactivating hides a placement without destroying it.
	 *
	 * There is no delete route, and there must not be one: a placement is
	 * referenced by every package that sells it and every campaign that bought
	 * one.
	 *
	 * @return void
	 */
	public function test_a_placement_is_deactivated_never_deleted(): void {
		$placement_id = $this->create_placement();

		$response = $this->write(
			'/aggr/v1/placements/' . $placement_id,
			'PATCH',
			$this->valid_placement( array( 'is_active' => false ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->placements->is_active( $placement_id ) );
		$this->assertContains( $placement_id, $this->placements->all_ids() );

		$routes = rest_get_server()->get_routes();

		foreach ( $routes as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/aggr/v1/placements' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$this->assertArrayNotHasKey(
					'DELETE',
					is_array( $handler['methods'] ?? null ) ? $handler['methods'] : array(),
					$route . ' exposes a DELETE.'
				);
			}
		}
	}

	/**
	 * A duplicate slug is refused by the workflow, not by the controller.
	 *
	 * @return void
	 */
	public function test_a_duplicate_slug_is_rejected(): void {
		$this->create_placement();

		$response = $this->write(
			self::CREATE,
			'POST',
			$this->valid_placement( array( 'name' => 'Second header' ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 1, $this->placements->all_ids() );
	}

	/**
	 * A reviewer is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function test_a_reviewer_cannot_create_a_placement(): void {
		wp_set_current_user( $this->reviewer );

		$this->assertSame( 403, $this->write( self::CREATE, 'POST', $this->valid_placement() )->get_status() );
		$this->assertSame( array(), $this->placements->all_ids() );
	}

	/**
	 * An advertiser is refused too.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_create_a_placement(): void {
		wp_set_current_user( $this->advertiser );

		$this->assertSame( 403, $this->write( self::CREATE, 'POST', $this->valid_placement() )->get_status() );
		$this->assertSame( array(), $this->placements->all_ids() );
	}

	/**
	 * An anonymous caller is refused.
	 *
	 * @return void
	 */
	public function test_an_anonymous_caller_cannot_create_a_placement(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->write( self::CREATE, 'POST', $this->valid_placement() )->get_status() );
		$this->assertSame( array(), $this->placements->all_ids() );
	}

	/**
	 * **The write gate refuses without the capability.**
	 *
	 * Called directly rather than through a dispatch, so the gate is what is
	 * under test rather than whatever else a route happens to reject first.
	 *
	 * @return void
	 */
	public function test_the_write_gate_refuses_without_the_capability(): void {
		$controller = Plugin::instance()->container()->get( \Aggressive\Ads\REST\Placements_Controller::class );

		wp_set_current_user( $this->administrator );
		$this->assertTrue( $controller->write_permission() );

		wp_set_current_user( $this->advertiser );
		$this->assertFalse( $controller->write_permission() );

		wp_set_current_user( 0 );
		$this->assertFalse( $controller->write_permission() );
	}

	/**
	 * **Reading and writing are separate gates.**
	 *
	 * An advertiser must keep being able to read the catalogue — the campaign
	 * wizard is built on it — while being refused any say in what it contains.
	 *
	 * @return void
	 */
	public function test_reading_and_writing_are_separate_gates(): void {
		$controller = Plugin::instance()->container()->get( \Aggressive\Ads\REST\Placements_Controller::class );

		wp_set_current_user( $this->advertiser );

		$this->assertTrue( $controller->permission() );
		$this->assertFalse( $controller->write_permission() );
		$this->assertTrue( current_user_can( Capabilities::ACCESS_PORTAL ) );
		$this->assertFalse( current_user_can( Capabilities::MANAGE_PLACEMENTS ) );
	}
}
