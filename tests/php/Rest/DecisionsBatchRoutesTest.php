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

	public function test_post_decisions_returns_forbidden_when_native_delivery_disabled(): void {
		$this->disable_native();

		$request = new WP_REST_Request( 'POST', '/aggr/v1/decisions' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'slots' => array( 'header-slot' ) ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
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

	private function enable_native(): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;
		$this->settings->save( $document );
	}

	private function disable_native(): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = false;
		$this->settings->save( $document );
	}
}
