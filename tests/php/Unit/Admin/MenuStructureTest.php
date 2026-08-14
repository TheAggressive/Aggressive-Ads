<?php
/**
 * Unified Advertising menu structure.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Admin;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Screens register submenus against Menu::PARENT_SLUG. A second add_menu_page
 * in Admin/ is a third megaphone, which the unified menu forbids.
 */
final class MenuStructureTest extends TestCase {

	/**
	 * Only the parent shell calls add_menu_page. Every screen uses a submenu.
	 *
	 * @return void
	 */
	public function test_only_the_parent_registers_a_top_level_menu(): void {
		$root         = dirname( __DIR__, 4 );
		$admin        = $root . '/inc/Admin';
		$found_parent = false;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $admin, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source   = (string) file_get_contents( $file->getPathname() );
			$relative = ltrim( str_replace( $root, '', $file->getPathname() ), '/' );

			if ( 'inc/Admin/class-menu.php' === $relative ) {
				$this->assertStringContainsString( 'add_menu_page(', $source );
				$this->assertStringContainsString( 'PARENT_SLUG', $source );
				$found_parent = true;
				continue;
			}

			$this->assertStringNotContainsString(
				'add_menu_page(',
				$source,
				"{$relative} registers a top-level menu; screens must use add_submenu_page( Menu::PARENT_SLUG, … )."
			);
		}

		$this->assertTrue( $found_parent, 'inc/Admin/class-menu.php must exist and register the parent.' );
	}

	/**
	 * Each staff screen hangs off the Advertising parent.
	 *
	 * @param string $relative Path under the plugin root.
	 * @return void
	 *
	 * @dataProvider data_staff_screens
	 */
	public function test_staff_screens_register_submenus( string $relative ): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/' . $relative );

		$this->assertStringContainsString( 'add_submenu_page(', $source );
		$this->assertStringContainsString( 'Menu::PARENT_SLUG', $source );
	}

	/**
	 * Staff screen files that must hang off the Advertising parent.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_staff_screens(): array {
		return array(
			'review'    => array( 'inc/Admin/class-review-screen.php' ),
			'orgs'      => array( 'inc/Admin/class-organization-screen.php' ),
			'inventory' => array( 'inc/Admin/class-placement-screen.php' ),
			'settings'  => array( 'inc/Admin/class-settings-screen.php' ),
		);
	}
}
