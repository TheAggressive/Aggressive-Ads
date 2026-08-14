<?php
/**
 * Staff settings against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Placement_Screen;
use Aggressive\Ads\Admin\Settings_Screen;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_Error;
use WP_UnitTestCase;

/**
 * Proves the control plane writes through the schema, and that reviewers
 * cannot see Settings while still reaching the Advertising shell.
 */
final class SettingsAdminTest extends WP_UnitTestCase {

	/**
	 * Settings document under test.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Settings screen controller.
	 *
	 * @var Settings_Screen
	 */
	private Settings_Screen $screen;

	/**
	 * Inventory screen. Always registered.
	 *
	 * @var Placement_Screen
	 */
	private Placement_Screen $inventory;

	/**
	 * Administrator user id.
	 *
	 * @var int
	 */
	private int $administrator;

	/**
	 * Reviewer user id.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Installs roles and resolves the control-plane services.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container           = Plugin::instance()->container();
		$this->settings      = $container->get( Settings::class );
		$this->screen        = $container->get( Settings_Screen::class );
		$this->inventory     = $container->get( Placement_Screen::class );
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->reviewer      = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
	}

	/**
	 * Drops the option so later tests see schema defaults.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Menu, assets, and the save handler are attached.
	 *
	 * @return void
	 */
	public function test_settings_surface_is_wired(): void {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->screen, 'register_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->screen, 'enqueue' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Settings_Screen::ACTION, array( $this->screen, 'handle_save' ) ) );
	}

	/**
	 * A valid document persists and is what get() returns next.
	 *
	 * @return void
	 */
	public function test_authorized_save_persists_the_document(): void {
		$document                          = $this->settings->get();
		$document['brand']['product_name'] = 'Museum Ads';

		$this->assertTrue( $this->settings->save( $document ) );
		$this->assertSame( 'Museum Ads', $this->settings->product_name() );

		$stored = get_option( Settings::OPTION );

		$this->assertIsArray( $stored );
		$this->assertSame( 'Museum Ads', $stored['brand']['product_name'] );
	}

	/**
	 * Contrast failure rejects the whole payload. Nothing is written.
	 *
	 * @return void
	 */
	public function test_low_contrast_save_is_rejected_and_storage_is_unchanged(): void {
		$document                           = $this->settings->get();
		$document['brand']['accent_strong'] = '#ffffff';

		$result = $this->settings->save( $document );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_settings_invalid', $result->get_error_code() );
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	/**
	 * Reviewers reach the shell, not Settings. Administrators reach both.
	 *
	 * @return void
	 */
	public function test_reviewer_cannot_manage_settings_but_can_see_the_shell(): void {
		wp_set_current_user( $this->reviewer );

		$this->assertTrue( current_user_can( Capabilities::REVIEW_CAMPAIGNS ) );
		$this->assertTrue( current_user_can( Capabilities::ACCESS_STAFF ) );
		$this->assertFalse( current_user_can( Capabilities::MANAGE_SETTINGS ) );

		wp_set_current_user( $this->administrator );

		$this->assertTrue( current_user_can( Capabilities::MANAGE_SETTINGS ) );
		$this->assertTrue( current_user_can( Capabilities::ACCESS_STAFF ) );

		wp_set_current_user( $this->advertiser );

		$this->assertFalse( current_user_can( Capabilities::ACCESS_STAFF ) );
	}

	/**
	 * Inventory is registered. Native delivery is not a kill-switch for it.
	 *
	 * @return void
	 */
	public function test_inventory_is_always_registered(): void {
		global $submenu;

		wp_set_current_user( $this->administrator );

		$submenu = array();
		Plugin::instance()->container()->get( Menu::class )->register_parent();
		$this->inventory->register_menu();

		$this->assertTrue( $this->submenu_has( Placement_Screen::MENU_SLUG ) );
	}

	/**
	 * A Settings save cannot turn native delivery off.
	 *
	 * @return void
	 */
	public function test_save_cannot_disable_native_delivery(): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = false;

		$this->assertTrue( $this->settings->save( $document ) );
		$this->assertTrue( $this->settings->module_enabled( Settings_Schema::MODULE_NATIVE_DELIVERY ) );
	}

	/**
	 * Whether a submenu slug was registered under Advertising.
	 *
	 * @param string $slug Submenu slug.
	 * @return bool
	 */
	private function submenu_has( string $slug ): bool {
		global $submenu;

		$items = $submenu[ Menu::PARENT_SLUG ] ?? array();

		foreach ( $items as $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				return true;
			}
		}

		return false;
	}
}
