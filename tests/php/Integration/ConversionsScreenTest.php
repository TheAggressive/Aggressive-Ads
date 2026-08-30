<?php
/**
 * The conversions screen is reachable, and only by the right people.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Conversions_Screen;
use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Until this screen existed, three merged pull requests of conversion tracking
 * were unreachable without curl — a definition could only be created over REST.
 * These assertions are about that being fixed and staying fixed.
 */
final class ConversionsScreenTest extends WP_UnitTestCase {

	/**
	 * Screen under test.
	 *
	 * @var Conversions_Screen
	 */
	private Conversions_Screen $screen;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->screen = Plugin::instance()->container()->get( Conversions_Screen::class );
	}

	/**
	 * Registers the plugin's admin menu as one user would see it.
	 *
	 * @param int $user_id Acting user.
	 * @return array<int, array<int, string>> Submenu rows under Advertising.
	 */
	private function submenu_for( int $user_id ): array {
		global $submenu, $menu, $_registered_pages, $_parent_pages;

		$submenu           = array();
		$menu              = array();
		$_registered_pages = array();
		$_parent_pages     = array();

		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );

		do_action( 'admin_menu', '' );

		return $submenu[ Menu::PARENT_SLUG ] ?? array();
	}

	/**
	 * Whether a submenu list contains the conversions page.
	 *
	 * @param array<int, array<int, string>> $rows Submenu rows.
	 */
	private function has_conversions( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( Conversions_Screen::MENU_SLUG === ( $row[2] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * **The screen exists in the menu**, which is the whole point of it.
	 */
	public function test_a_settings_manager_sees_the_screen(): void {
		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue(
			user_can( $admin, Capabilities::MANAGE_SETTINGS ),
			'The fixture must hold the capability, or the next assertion passes for the wrong reason.'
		);

		$this->assertTrue( $this->has_conversions( $this->submenu_for( $admin ) ) );
	}

	/**
	 * An advertiser does not, and neither does a reviewer.
	 *
	 * Reviewing campaigns and configuring measurement are deliberately separate
	 * capabilities. The REST routes assert the same split; this is the half a
	 * person actually sees.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refused_roles(): array {
		return array(
			'an advertiser' => array( Roles::ADVERTISER ),
			'a reviewer'    => array( Roles::REVIEWER ),
			'a subscriber'  => array( 'subscriber' ),
		);
	}

	/**
	 * Asserts one role cannot reach the screen.
	 *
	 * @dataProvider refused_roles
	 *
	 * @param string $role Role slug.
	 */
	public function test_a_role_without_the_capability_does_not_see_it( string $role ): void {
		$user_id = (int) self::factory()->user->create( array( 'role' => $role ) );

		$this->assertFalse( user_can( $user_id, Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( $this->has_conversions( $this->submenu_for( $user_id ) ) );
	}

	/**
	 * **The render callback refuses too, not only the menu.**
	 *
	 * A hidden menu item is not authorization: `admin.php?page=aggr-conversions`
	 * is a URL anybody can type, and WordPress runs the callback for whoever
	 * asks. The capability check inside `render()` is what actually holds.
	 */
	public function test_the_render_callback_refuses_an_unauthorized_caller(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$died = false;

		add_filter(
			'wp_die_handler',
			static function () use ( &$died ): callable {
				return static function () use ( &$died ): void {
					$died = true;

					throw new \RuntimeException( 'wp_die' );
				};
			}
		);

		try {
			ob_start();
			$this->screen->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			ob_end_clean();
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died, 'An advertiser reached the conversions screen by URL.' );
	}

	/**
	 * The screen prints its mount point and the REST path it writes through.
	 */
	public function test_an_authorized_caller_gets_the_mount_point(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="aggr-conversions-root"', $html );
		$this->assertStringContainsString( 'conversion-definitions', $html );
		$this->assertStringContainsString( 'noscript', $html, 'A JavaScript-only screen must say so without it.' );
	}

	/**
	 * **The window options are ones the domain will actually accept.**
	 *
	 * A select offering a window the validator clamps is a control that lies:
	 * the publisher picks one day, the definition saves as one hour, and
	 * nothing says so. Built from `Conversion_Rules`, asserted here.
	 */
	public function test_every_offered_window_survives_validation(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$matches = array();
		preg_match( '/data-aggr-conversions="([^"]+)"/', $html, $matches );

		$payload = json_decode( html_entity_decode( $matches[1] ?? '', ENT_QUOTES ), true );

		$this->assertIsArray( $payload );
		$this->assertNotEmpty( $payload['windows'], 'The screen offers no attribution windows at all.' );

		foreach ( $payload['windows'] as $option ) {
			$seconds = (int) $option['value'];

			$this->assertSame(
				$seconds,
				\Aggressive\Ads\Domain\Conversion_Rules::window_seconds( $seconds ),
				'The screen offers a window the domain would clamp.'
			);
		}
	}
}
