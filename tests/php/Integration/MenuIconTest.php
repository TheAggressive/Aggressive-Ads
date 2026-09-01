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
	 * The mark has to ship, even though the rule no longer loads it.
	 *
	 * The CSS carries the mark inline, so a missing file breaks nothing at
	 * runtime — which is exactly why this is worth asserting. `verify-package.sh`
	 * requires the file in the ZIP, and the colour assertion below requires the
	 * inlined bytes to equal it; between them the file cannot quietly become a
	 * copy nobody updates.
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
		$this->assertStringContainsString( '-webkit-mask:', $css, 'Safari needs the prefixed property.' );

		/*
		 * The mask travels in the rule. Pointing it at the file's URL made the
		 * mark a second request, and an element whose mask has not arrived
		 * paints nothing — so the icon was absent on every admin page until
		 * that response landed. Asserting the data URI is what stops a
		 * well-meaning edit from putting the request back.
		 */
		$this->assertStringContainsString( 'data:image/svg+xml;base64,', $css );
		$this->assertStringNotContainsString(
			'aggressive-ads-icon.svg',
			$css,
			'A URL here is a second HTTP request, and the mark is invisible until it returns.'
		);

		/*
		 * And that the inlined bytes are the shipped file's, rather than a copy
		 * that can drift from it.
		 *
		 * The message matters more than usual here. Someone who edits the SVG
		 * and does not regenerate the constant sees this fail, and a bare
		 * "string not found" against 300 characters of base64 tells them
		 * nothing about what to do — so it says what to run.
		 */
		$expected = base64_encode( (string) file_get_contents( AGGR_PLUGIN_DIR . 'assets/svg/aggressive-ads-icon.svg' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Reading the bundled asset to prove the rule carries it.

		$this->assertStringContainsString(
			$expected,
			$css,
			"The inlined mark is not the file in assets/svg/. Regenerate Menu::ICON_DATA_URI:\n"
				. "  printf 'data:image/svg+xml;base64,%s' \"$(base64 -w0 assets/svg/aggressive-ads-icon.svg)\""
		);

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
