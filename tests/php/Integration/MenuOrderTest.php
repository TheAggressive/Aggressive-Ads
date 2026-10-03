<?php
/**
 * The Advertising sidebar's order, and the landing redirect that follows it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Conversions_Screen;
use Aggressive\Ads\Admin\Forecast_Screen;
use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Organization_Screen;
use Aggressive\Ads\Admin\Package_Screen;
use Aggressive\Ads\Admin\Placement_Screen;
use Aggressive\Ads\Admin\Reports_Screen;
use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Admin\Settings_Screen;
use Aggressive\Ads\Plugin;
use WP_UnitTestCase;

/**
 * The sidebar ran in service-registration order, and the parent's landing
 * redirect kept a second, hand-written order that had drifted from it and
 * left out Conversions. Both now read `Menu::SCREENS`; these tests hold the
 * sidebar to it whatever order the screens boot in, and hold the list to the
 * screens that actually register.
 */
final class MenuOrderTest extends WP_UnitTestCase {

	/**
	 * Registers the whole menu as an administrator, screens in the order given.
	 *
	 * @param array<int, class-string> $screens Screen classes, in boot order.
	 * @return array<int, array<int, string>> The parent's submenu rows.
	 */
	private function submenu_after_booting( array $screens ): array {
		global $menu, $submenu, $_registered_pages, $_parent_pages;

		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();
		$_parent_pages     = array();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$container = Plugin::instance()->container();
		$shell     = $container->get( Menu::class );

		$shell->register_parent();

		foreach ( $screens as $screen ) {
			$container->get( $screen )->register_menu();
		}

		$shell->remove_duplicate_parent();
		$shell->sort_submenu();

		return array_values( (array) ( $submenu[ Menu::PARENT_SLUG ] ?? array() ) );
	}

	/**
	 * Every screen class, in an order that is neither the sidebar's nor boot's.
	 *
	 * @return array<int, class-string>
	 */
	private static function scrambled(): array {
		return array(
			Settings_Screen::class,
			Package_Screen::class,
			Review_Screen::class,
			Reports_Screen::class,
			Forecast_Screen::class,
			Organization_Screen::class,
			Conversions_Screen::class,
			Placement_Screen::class,
		);
	}

	/**
	 * **The sidebar is `SCREENS`, whatever order the screens boot in.**
	 *
	 * Registered scrambled on purpose: registered in sidebar order, a sort
	 * that did nothing would pass.
	 *
	 * @return void
	 */
	public function test_the_sidebar_follows_the_one_order(): void {
		$rows = $this->submenu_after_booting( self::scrambled() );

		$this->assertCount( count( Menu::SCREENS ), $rows, 'Every screen registers exactly one row.' );
		$this->assertSame( array_keys( Menu::SCREENS ), array_column( $rows, 2 ) );
	}

	/**
	 * **The list names each screen with the capability it really opens on.**
	 *
	 * The landing redirect sends the parent click to the first screen in
	 * `SCREENS` the user may open. A capability here that differs from the
	 * one the screen registers with would land somebody on a 403, or skip a
	 * screen they can open.
	 *
	 * @return void
	 */
	public function test_each_listed_capability_is_the_screens_own(): void {
		$rows = $this->submenu_after_booting( self::scrambled() );

		foreach ( $rows as $row ) {
			$this->assertSame( Menu::SCREENS[ $row[2] ] ?? null, $row[1], "{$row[2]} registers with a different capability than Menu::SCREENS gives it." );
		}
	}

	/**
	 * Something another plugin adds under the parent stays, after ours.
	 *
	 * @return void
	 */
	public function test_a_foreign_row_keeps_its_place_after_ours(): void {
		global $submenu;

		$this->submenu_after_booting( self::scrambled() );

		$submenu[ Menu::PARENT_SLUG ][] = array( 'Extra', 'manage_options', 'someone-elses-page' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating another plugin's row.

		Plugin::instance()->container()->get( Menu::class )->sort_submenu();

		$slugs = array_column( array_values( $submenu[ Menu::PARENT_SLUG ] ), 2 );

		$this->assertSame( 'someone-elses-page', end( $slugs ) );
		$this->assertSame( array_keys( Menu::SCREENS ), array_slice( $slugs, 0, count( Menu::SCREENS ) ) );
	}
}
