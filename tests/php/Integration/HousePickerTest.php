<?php
/**
 * Choosing a house image, rather than knowing its number.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Placement_Data;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The house image was a number field. Setting one meant uploading the image
 * from another screen, finding its attachment id and copying the digits
 * across — and nothing checked the result, so a wrong number saved cleanly
 * and the placement served nothing.
 *
 * The failure mode of the replacement is quieter than the one it removes.
 * `MediaUpload` renders perfectly without `wp.media` on the page and only the
 * click does nothing, so the enqueue is asserted rather than assumed: there is
 * no visible symptom to notice in review.
 */
final class HousePickerTest extends WP_UnitTestCase {

	/**
	 * Placement reads and writes.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	/**
	 * Screen payload builder.
	 *
	 * @var Placement_Data
	 */
	private Placement_Data $data;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$container        = Plugin::instance()->container();
		$this->placements = $container->get( Placement_Repository::class );
		$this->data       = $container->get( Placement_Data::class );
	}

	/**
	 * A placement, with a house image when asked for one.
	 *
	 * @param bool $with_image Whether to attach an image.
	 * @return int
	 */
	private function placement( bool $with_image = true ): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'slot-' . wp_generate_password( 8, false ),
				'post_title'  => 'Slot',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		if ( $with_image ) {
			$attachment_id = (int) self::factory()->attachment->create_object(
				array(
					'file'           => 'house.png',
					'post_mime_type' => 'image/png',
				)
			);

			$this->placements->set_house( $placement_id, $attachment_id, 'https://example.test/house', 'House advertisement' );
		}

		return $placement_id;
	}

	public function test_the_screen_loads_the_media_frame_the_picker_opens(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		do_action( 'admin_menu' );

		global $submenu;

		$slug = '';

		foreach ( $submenu[ Menu::PARENT_SLUG ] ?? array() as $entry ) {
			if ( str_contains( (string) ( $entry[2] ?? '' ), 'placement' ) ) {
				$slug = (string) $entry[2];
			}
		}

		$this->assertNotSame( '', $slug, 'No placements screen is registered, so this test would prove nothing.' );

		$_GET['page'] = $slug;
		$hook         = get_plugin_page_hookname( $slug, Menu::PARENT_SLUG );

		/*
		 * A real screen, because core hooks onto `admin_enqueue_scripts` too
		 * and some of them dereference `get_current_screen()`. Firing the
		 * action without one crashes inside WordPress rather than in anything
		 * this test is about.
		 */
		set_current_screen( $hook );

		do_action( 'admin_enqueue_scripts', $hook );

		$this->assertTrue(
			wp_script_is( 'media-editor', 'enqueued' ),
			'`MediaUpload` renders fine with no media frame on the page and only the click does nothing, so nothing about the screen would look wrong.'
		);
	}

	public function test_a_placement_with_no_image_offers_no_preview(): void {
		$this->assertSame( '', $this->placements->house_image_url( $this->placement( false ) ) );
	}

	public function test_a_chosen_image_comes_back_as_a_preview_url(): void {
		$url = $this->placements->house_image_url( $this->placement() );

		$this->assertNotSame( '', $url, 'Without a URL the form can only show the publisher a number again.' );
		$this->assertStringContainsString( 'house', $url );
	}

	public function test_a_removed_image_stops_being_previewed(): void {
		$placement_id = $this->placement();

		$this->placements->set_house( $placement_id, 0, '', '' );

		$this->assertSame(
			'',
			$this->placements->house_image_url( $placement_id ),
			'A stale preview would show a publisher an advertisement that is no longer configured to serve.'
		);
	}

	public function test_the_screen_payload_carries_the_preview(): void {
		$placement_id = $this->placement();
		$rows         = $this->data->view()['rows'];
		$row          = null;

		foreach ( $rows as $candidate ) {
			if ( $candidate['id'] === $placement_id ) {
				$row = $candidate;
			}
		}

		$this->assertIsArray( $row, 'The placement is missing from its own screen payload.' );
		$this->assertArrayHasKey(
			'house_image_url',
			$row,
			'The form reads this key. A payload without it renders a picker that can never show what is already chosen.'
		);
		$this->assertNotSame( '', $row['house_image_url'] );
	}

	public function test_a_broken_attachment_reference_previews_nothing(): void {
		$placement_id = $this->placement( false );

		update_post_meta( $placement_id, Placement_Repository::META_HOUSE_ATTACHMENT, 999999 );

		$this->assertSame(
			'',
			$this->placements->house_image_url( $placement_id ),
			'An id pointing at nothing is exactly what the number field used to produce, and a broken preview image is a worse report of it than none.'
		);
	}
}
