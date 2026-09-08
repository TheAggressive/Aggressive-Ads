<?php
/**
 * Decisions batch REST route tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests POST /aggr/v1/decisions permissions, input validation, and batch responses.
 */
final class DecisionsBatchRoutesTest extends WP_UnitTestCase {

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
		( new Installer( new Audit_Repository(), new Roles() ) )->install_delivery_tables();

		$this->settings = Plugin::instance()->container()->get( Settings::class );
		$this->enable_native();

		$header_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_name'   => 'header-slot',
				'post_status' => 'publish',
				'post_title'  => 'Header Slot',
			)
		);
		update_post_meta( $header_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $header_id, Placement_Repository::META_SIZE, '728x90' );

		$sidebar_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_name'   => 'sidebar-slot',
				'post_status' => 'publish',
				'post_title'  => 'Sidebar Slot',
			)
		);
		update_post_meta( $sidebar_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $sidebar_id, Placement_Repository::META_SIZE, '300x250' );

		do_action( 'rest_api_init', rest_get_server() );
	}

	public function test_decisions_is_available_without_enabling_a_module(): void {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/decisions' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'slots' => array( 'header-slot' ) ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_post_decisions_validates_slots_input(): void {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/decisions' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'slots' => 'not-an-array' ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );

		// Empty array is also rejected.
		$request = new WP_REST_Request( 'POST', '/aggr/v1/decisions' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'slots' => array() ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );

		// Array with invalid characters in slug is rejected.
		$request = new WP_REST_Request( 'POST', '/aggr/v1/decisions' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'slots' => array( 'valid-slug', '<script>alert(1)</script>' ) ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_post_decisions_handles_unknown_slots_gracefully(): void {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/decisions' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'slots' => array( 'non-existent-slot' ),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'decisions', $data );
		$this->assertEmpty( $data['decisions'] );
	}

	public function test_post_decisions_resolves_batch_slots_successfully(): void {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/decisions' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'slots' => array( 'header-slot', 'sidebar-slot' ),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'decisions', $data );
		$this->assertArrayHasKey( 'header-slot', $data['decisions'] );
		$this->assertArrayHasKey( 'sidebar-slot', $data['decisions'] );
	}

	/**
	 * A responsive placement is offered the size the viewport asked for.
	 *
	 * The controller passed no viewport at all, so `for_slots()` resolved every
	 * placement to its base size. On a fixed-size placement that is invisible —
	 * the base is the only size — which is why every existing test over this
	 * route passed while a responsive one was quietly offered the wrong artwork
	 * on every batch decision.
	 *
	 * Both sizes are asserted from the same placement. Checking only the mobile
	 * size would pass over a route that ignored the viewport and happened to
	 * have a mobile base.
	 *
	 * @return void
	 */
	public function test_the_batch_route_resolves_the_reported_viewport(): void {
		$placements = Plugin::instance()->container()->get( Placement_Repository::class );
		$header_id  = (int) get_page_by_path( 'header-slot', OBJECT, Post_Types::PLACEMENT )->ID;

		$this->assertTrue(
			$placements->set_size_map(
				$header_id,
				array(
					0   => '320x50',
					768 => '728x90',
				)
			),
			'The responsive size map did not save, so this proves nothing about viewports.'
		);

		/*
		 * The fixture is checked before the route is.
		 *
		 * `set_size_map()` answers true for a map that normalised to nothing,
		 * because it compares what it stored against what it meant to store.
		 * A malformed fixture therefore falls back to the fixed base and the
		 * route below returns the base at every viewport — which looks exactly
		 * like the defect this test is for. It cost a wrong diagnosis once
		 * already.
		 */
		$this->assertSame(
			'320x50',
			$placements->size_map( $header_id )->for_viewport( 375 ),
			'The fixture is not responsive, so nothing below is about the route.'
		);

		$this->assertSame( '320x50', $this->batch_size( 375 ) );
		$this->assertSame( '728x90', $this->batch_size( 1024 ) );
	}

	/**
	 * The size the batch route reports for the header slot at one viewport.
	 *
	 * @param int $width Reported viewport width in CSS pixels.
	 * @return string
	 */
	private function batch_size( int $width ): string {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/decisions' );
		$request->set_param( 'slots', array( 'header-slot' ) );
		$request->set_param( 'w', $width );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		return (string) $response->get_data()['decisions']['header-slot']['size'];
	}

	private function enable_native(): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;
		$this->settings->save( $document );
	}
}
