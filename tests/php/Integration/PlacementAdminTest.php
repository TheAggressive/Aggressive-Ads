<?php
/**
 * Staff placement catalogue against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Placement_Data;
use Aggressive\Ads\Admin\Placement_Screen;
use Aggressive\Ads\Domain\Ad_Sizes;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Placement_Manager;
use WP_Error;
use WP_UnitTestCase;

/**
 * Proves placement writes are authorized, sized, unique, and auditable.
 */
final class PlacementAdminTest extends WP_UnitTestCase {

	/**
	 * Placement workflow.
	 *
	 * @var Placement_Manager
	 */
	private Placement_Manager $manager;

	/**
	 * Placement screen.
	 *
	 * @var Placement_Screen
	 */
	private Placement_Screen $screen;

	/**
	 * Placement read model.
	 *
	 * @var Placement_Data
	 */
	private Placement_Data $data;

	/**
	 * Placement persistence.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Administrator user id.
	 *
	 * @var int
	 */
	private int $administrator;

	/**
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Installs roles and resolves catalogue services.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit      = new Audit_Repository();
		$this->placements = new Placement_Repository();

		( new Installer( $this->audit, new Roles() ) )->install_roles();

		$container           = Plugin::instance()->container();
		$this->manager       = $container->get( Placement_Manager::class );
		$this->screen        = $container->get( Placement_Screen::class );
		$this->data          = $container->get( Placement_Data::class );
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->advertiser    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
	}

	/**
	 * Clears request state.
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Menu and authenticated handlers are attached.
	 *
	 * @return void
	 */
	public function test_inventory_surface_is_wired(): void {
		global $submenu;

		wp_set_current_user( $this->administrator );
		$submenu = array();

		Plugin::instance()->container()->get( Menu::class )->register_parent();
		$this->screen->register_menu();

		$found = false;

		foreach ( $submenu[ Menu::PARENT_SLUG ] ?? array() as $item ) {
			if ( isset( $item[2] ) && Placement_Screen::MENU_SLUG === $item[2] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found );

		// The catalogue's admin-post handlers were deleted with the server-
		// rendered template; writes go to REST\Placements_Controller now, and
		// leaving handlers registered with no form pointing at them would be
		// unreferenced write paths to the catalogue. See Rest\PlacementsWriteTest.
		$this->assertFalse( has_action( 'admin_post_aggr_create_placement' ) );
		$this->assertFalse( has_action( 'admin_post_aggr_update_placement' ) );
	}

	/**
	 * An administrator can create a common IAB size. The change is audited.
	 *
	 * @return void
	 */
	public function test_authorized_create_stores_a_listed_size(): void {
		wp_set_current_user( $this->administrator );

		$result = $this->manager->create( $this->valid_fields() );

		$this->assertIsInt( $result );
		$this->assertSame( '728x90', $this->placements->size( $result ) );
		$this->assertSame( 'header', $this->placements->slug( $result ) );
		$this->assertTrue( $this->placements->is_active( $result ) );

		$events = $this->audit->for_object( 'placement', $result, 0 );
		$this->assertSame( 'placement.created', $events[0]['event'] );
		$this->assertSame( 'ok', $events[0]['outcome'] );
	}

	/**
	 * Custom width and height are stored as ASCII WxH, not as the form token.
	 *
	 * @return void
	 */
	public function test_custom_size_is_stored_as_pixels(): void {
		wp_set_current_user( $this->administrator );

		$result = $this->manager->create(
			$this->valid_fields(
				array(
					'name'        => 'Odd slot',
					'slug'        => 'odd-slot',
					'size_preset' => Ad_Sizes::CUSTOM,
					'size_width'  => 123,
					'size_height' => 45,
				)
			)
		);

		$this->assertIsInt( $result );
		$this->assertSame( '123x45', $this->placements->size( $result ) );
		$this->assertSame( Ad_Sizes::CUSTOM, $this->data->view()['rows'][0]['size_preset'] );
	}

	/**
	 * Two placements cannot share a public slug.
	 *
	 * @return void
	 */
	public function test_duplicate_slug_is_rejected(): void {
		wp_set_current_user( $this->administrator );

		$first = $this->manager->create( $this->valid_fields() );
		$this->assertIsInt( $first );

		$second = $this->manager->create(
			$this->valid_fields(
				array(
					'name' => 'Other header',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'aggr_placement_slug_taken', $second->get_error_code() );
	}

	/**
	 * Deactivate is how a slot leaves the advertiser catalogue. There is no delete.
	 *
	 * @return void
	 */
	public function test_a_placement_can_be_deactivated(): void {
		wp_set_current_user( $this->administrator );

		$placement_id = $this->manager->create( $this->valid_fields() );
		$this->assertIsInt( $placement_id );

		$result = $this->manager->update(
			$placement_id,
			$this->valid_fields( array( 'is_active' => false ) )
		);

		$this->assertTrue( $result );
		$this->assertFalse( $this->placements->is_active( $placement_id ) );
	}

	/**
	 * Advertisers cannot mutate the global slot catalogue.
	 *
	 * @return void
	 */
	public function test_advertiser_is_denied_and_nothing_is_created(): void {
		wp_set_current_user( $this->advertiser );

		$result = $this->manager->create( $this->valid_fields() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->placements->id_by_slug( 'header' ) );
	}

	/**
	 * Custom width and height are ignored unless Custom size is selected.
	 *
	 * @return void
	 */
	public function test_custom_dimensions_do_not_override_a_listed_preset(): void {
		wp_set_current_user( $this->administrator );

		$result = $this->manager->create(
			$this->valid_fields(
				array(
					'size_preset' => '300x250',
					'size_width'  => 1,
					'size_height' => 1,
				)
			)
		);

		$this->assertIsInt( $result );
		$this->assertSame( '300x250', $this->placements->size( $result ) );
	}

	/**
	 * The screen mounts the catalogue it edits, rather than a form.
	 *
	 * The markup assertions that used to live here described a server-rendered
	 * form that no longer exists. What they were protecting — that the screen
	 * hands over the real catalogue and the common sizes — survives the move and
	 * is asserted against the bootstrap payload the React screen reads.
	 *
	 * @return void
	 */
	public function test_screen_mounts_the_catalogue(): void {
		wp_set_current_user( $this->administrator );
		$this->assertIsInt( $this->manager->create( $this->valid_fields() ) );

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Inventory', $html );
		$this->assertStringContainsString( 'id="aggr-inventory-root"', $html );
		$this->assertStringContainsString( '<noscript>', $html );

		$payload = $this->mounted_payload( $html );

		$this->assertSame( '/aggr/v1/placements', $payload['restPath'] );
		$this->assertSame( 'Leaderboard (728×90)', $payload['view']['sizes']['728x90'] );
		$this->assertSame( 'Homepage leaderboard', $payload['view']['rows'][0]['name'] );
		$this->assertSame( 'Create placement', $payload['i18n']['create'] );
		$this->assertSame( 'Custom size', $payload['i18n']['customSize'] );

		// No form, so no nonce: the write path is REST, which authenticates
		// with its own nonce header rather than a posted field.
		$this->assertStringNotContainsString( 'name="_wpnonce"', $html );
	}

	/**
	 * House destination uses the same URL rules as paid creatives.
	 *
	 * @return void
	 */
	public function test_house_update_rejects_a_javascript_url(): void {
		wp_set_current_user( $this->administrator );

		$placement_id = $this->manager->create( $this->valid_fields() );
		$this->assertIsInt( $placement_id );

		$result = $this->manager->update(
			$placement_id,
			$this->valid_fields(
				array(
					'house_attachment_id' => $this->png_attachment(),
					'house_click_url'     => 'javascript:alert(1)',
					'house_alt'           => 'House',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_invalid_house_url', $result->get_error_code() );
		$this->assertSame( 0, $this->placements->house_attachment_id( $placement_id ) );
	}

	/**
	 * The same URL rules apply with no attachment attached.
	 *
	 * The check used to live inside the "an image was supplied" branch, so
	 * dropping the image while editing the destination wrote the raw string to
	 * post meta and reported success. Two downstream gates meant nothing ever
	 * served it, which is exactly why it could sit there unnoticed.
	 *
	 * @return void
	 */
	public function test_house_update_rejects_a_javascript_url_without_an_attachment(): void {
		wp_set_current_user( $this->administrator );

		$placement_id = $this->manager->create( $this->valid_fields() );
		$this->assertIsInt( $placement_id );

		$result = $this->manager->update(
			$placement_id,
			$this->valid_fields(
				array(
					'house_attachment_id' => 0,
					'house_click_url'     => 'javascript:alert(1)',
					'house_alt'           => 'House',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_invalid_house_url', $result->get_error_code() );
		$this->assertSame( '', $this->placements->house_click_url( $placement_id ) );
	}

	/**
	 * Clearing house creative stays possible: no attachment, no destination.
	 *
	 * The guard above must not turn the empty-and-empty case into an error,
	 * because that is the only way to remove a house creative.
	 *
	 * @return void
	 */
	public function test_house_can_be_cleared_with_empty_fields(): void {
		wp_set_current_user( $this->administrator );

		$placement_id = $this->manager->create( $this->valid_fields() );
		$this->assertIsInt( $placement_id );

		$result = $this->manager->update(
			$placement_id,
			$this->valid_fields(
				array(
					'house_attachment_id' => 0,
					'house_click_url'     => '',
					'house_alt'           => '',
				)
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( '', $this->placements->house_click_url( $placement_id ) );
	}

	/**
	 * SVG is stored XSS on the public site. House creative cannot be one.
	 *
	 * @return void
	 */
	public function test_house_update_rejects_an_svg_attachment(): void {
		wp_set_current_user( $this->administrator );

		$placement_id = $this->manager->create( $this->valid_fields() );
		$this->assertIsInt( $placement_id );

		$svg = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'house.svg',
				'post_mime_type' => 'image/svg+xml',
			)
		);

		$result = $this->manager->update(
			$placement_id,
			$this->valid_fields(
				array(
					'house_attachment_id' => $svg,
					'house_click_url'     => 'https://example.com/house',
					'house_alt'           => 'House',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_invalid_house_attachment', $result->get_error_code() );
		$this->assertSame( 0, $this->placements->house_attachment_id( $placement_id ) );
	}

	/**
	 * A file named as an image that is not one is refused at save.
	 *
	 * @return void
	 */
	public function test_house_update_rejects_a_non_image_named_as_png(): void {
		wp_set_current_user( $this->administrator );

		$placement_id = $this->manager->create( $this->valid_fields() );
		$this->assertIsInt( $placement_id );

		$fake = $this->attachment_on_disk(
			'house-html-' . wp_generate_password( 8, false ) . '.png',
			'<script>alert(1)</script>',
			'image/png'
		);

		$result = $this->manager->update(
			$placement_id,
			$this->valid_fields(
				array(
					'house_attachment_id' => $fake,
					'house_click_url'     => 'https://example.com/house',
					'house_alt'           => 'House',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_invalid_house_attachment', $result->get_error_code() );
	}

	/**
	 * An advertiser cannot reach the screen at all.
	 *
	 * This replaces a test that posted to the deleted update handler. The
	 * equivalent assertion for the write path now lives in
	 * Rest\PlacementsWriteTest; what belongs here is the screen's own gate.
	 *
	 * @return void
	 */
	public function test_render_refuses_an_advertiser(): void {
		wp_set_current_user( $this->administrator );
		$this->assertIsInt( $this->manager->create( $this->valid_fields() ) );

		wp_set_current_user( $this->advertiser );

		$this->expectException( 'WPDieException' );

		ob_start();

		try {
			$this->screen->render();
		} finally {
			$html = (string) ob_get_clean();

			$this->assertStringNotContainsString( 'aggr-inventory-root', $html );
		}
	}

	/**
	 * The bootstrap payload the mounted screen reads.
	 *
	 * @param string $html Rendered screen.
	 * @return array<string, mixed>
	 */
	private function mounted_payload( string $html ): array {
		$matched = preg_match( '/data-aggr-inventory="([^"]*)"/', $html, $matches );

		$this->assertSame( 1, $matched, 'The screen printed no bootstrap payload.' );

		$decoded = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}

	/**
	 * Catalogue fields for one valid placement.
	 *
	 * @param array<string, mixed> $overrides Field replacements.
	 * @return array<string, mixed>
	 */
	private function valid_fields( array $overrides = array() ): array {
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
	 * A one-pixel PNG written to disk so getimagesize() has a real header.
	 */
	private function png_attachment(): int {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true );
		$this->assertNotFalse( $png );

		return $this->attachment_on_disk(
			'house-' . wp_generate_password( 8, false ) . '.png',
			$png,
			'image/png'
		);
	}

	/**
	 * Attachment whose bytes live on disk so getimagesize() can inspect them.
	 *
	 * @param string $basename Upload basename, including extension.
	 * @param string $contents File bytes.
	 * @param string $mime     Declared attachment MIME.
	 */
	private function attachment_on_disk( string $basename, string $contents, string $mime ): int {
		$uploads = wp_upload_dir();
		$this->assertIsArray( $uploads );
		$this->assertEmpty( $uploads['error'] );

		$path = trailingslashit( (string) $uploads['basedir'] ) . $basename;
		$this->assertNotFalse( file_put_contents( $path, $contents ) );

		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => $path,
				'post_mime_type' => $mime,
			)
		);

		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertFileExists( (string) get_attached_file( $attachment_id ) );

		return $attachment_id;
	}
}
