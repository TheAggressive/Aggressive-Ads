<?php
/**
 * The Advertising menu mark, and the CSS that colours it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The mark is painted with a mask so it inherits the menu's colour. These
 * assert the parts of that which can break silently: the file being absent, and
 * the rule losing the thing that makes it colourable.
 */
final class MenuIconTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * Installs roles and resolves the service.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->menu = Plugin::instance()->container()->get( Menu::class );
	}

	/**
	 * Captures the printed style block.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->menu->print_icon_style();

		return (string) ob_get_clean();
	}

	/**
	 * The mask source has to exist, or the menu shows an empty box.
	 *
	 * The path is a constant in one file and a line in the release manifest, so
	 * this fails when either moves without the other.
	 *
	 * @return void
	 */
	public function test_the_mark_ships(): void {
		$this->assertFileExists( AGGR_PLUGIN_DIR . 'assets/svg/aggressive-ads-icon.svg' );
	}

	/**
	 * The rule paints with currentColor, which is the whole point.
	 *
	 * A background-image would render the same mark and never change colour, so
	 * asserting the mark appears is not enough — this asserts the mechanism.
	 *
	 * @return void
	 */
	public function test_the_mark_takes_its_colour_from_the_menu(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$css = $this->render();

		$this->assertStringContainsString( 'background-color:currentColor', $css );
		$this->assertStringContainsString( 'aggressive-ads-icon.svg', $css );
		$this->assertStringContainsString( '-webkit-mask:', $css, 'Safari needs the prefixed property.' );

		// Centring is done by flex, not by a margin measured against one row
		// height. A magic offset looked right on the expanded menu and sat
		// off-centre everywhere else.
		$this->assertStringContainsString( 'align-items:center', $css );
		$this->assertStringNotContainsString(
			'margin:7px',
			$css,
			'A hand-tuned offset drifts on the folded menu and the mobile breakpoint.'
		);
		$this->assertStringNotContainsString(
			'background-image',
			$css,
			'A background-image cannot be coloured by CSS, which is why this uses a mask.'
		);
	}

	/**
	 * Somebody with no advertising access is not styled at all.
	 *
	 * @return void
	 */
	public function test_a_visitor_without_staff_access_gets_no_rule(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * WordPress must not draw its own glyph over the mark.
	 *
	 * @return void
	 */
	public function test_wordpress_is_told_not_to_supply_an_icon(): void {
		$this->assertSame( 'none', Menu::ICON );
	}
}
