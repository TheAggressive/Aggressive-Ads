<?php
/**
 * Design-system constraints for the staff review surface.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Prevents the admin workflow from becoming a second, disconnected UI system.
 */
final class AdminDesignSystemTest extends TestCase {

	/**
	 * Admin layout owns no literal colors; all visual decisions stay in tokens.
	 *
	 * @return void
	 */
	public function test_admin_css_uses_the_shared_color_tokens(): void {
		$css = file_get_contents( AGGR_PLUGIN_DIR . 'src/styles/admin.css' );

		$this->assertIsString( $css, 'src/styles/admin.css must be readable.' );
		$this->assertStringContainsString( '--aggr-', $css );
		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css, 'Admin CSS introduced a literal color outside the token layer.' );
	}

	/**
	 * Interactive controls keep the shared minimum touch target.
	 *
	 * @return void
	 */
	public function test_admin_navigation_uses_the_control_size_token(): void {
		$css = file_get_contents( AGGR_PLUGIN_DIR . 'src/styles/admin.css' );

		$this->assertIsString( $css );
		$this->assertGreaterThanOrEqual( 2, substr_count( $css, 'var(--aggr-control-min)' ) );
		$this->assertStringNotContainsString( '@layer', $css, 'Layered component rules lose to unlayered wp-admin element styles.' );
	}

	/**
	 * The review screens keep their accessible structure after the conversion.
	 *
	 * The two templates these assertions read no longer exist — the screens are
	 * React writing through REST. What they were protecting is the structure
	 * itself, so it is asserted against the components that now render it:
	 * scoped table headers, a labelled control for every textarea, and the
	 * design system this screen deliberately kept rather than moving to core's
	 * component set.
	 *
	 * @return void
	 */
	public function test_review_screens_keep_accessible_structure(): void {
		$queue    = file_get_contents( AGGR_PLUGIN_DIR . 'src/admin/review/queue.tsx' );
		$campaign = file_get_contents( AGGR_PLUGIN_DIR . 'src/admin/review/campaign.tsx' );

		$this->assertIsString( $queue );
		$this->assertIsString( $campaign );

		$this->assertStringContainsString( 'aria-current', $queue );
		$this->assertStringContainsString( 'scope="col"', $queue );
		$this->assertStringContainsString( 'aria-labelledby', $campaign );

		// Every textarea on the detail screen is reachable by its label.
		$this->assertSame(
			substr_count( $campaign, '<textarea' ),
			substr_count( $campaign, 'htmlFor=' ),
			'A textarea on the review screen has no label bound to it.'
		);

		// The plugin's own design system, not wp-components. Mixing the two on
		// one screen is the half-done state src/styles/admin.css exists to
		// avoid; see the note in Review_Screen.
		$this->assertStringContainsString( 'aggr-panel', $campaign );
		$this->assertStringNotContainsString( '@wordpress/components', $campaign );
		$this->assertStringNotContainsString( '@wordpress/components', $queue );
	}

	/**
	 * A placement is deactivated, never deleted.
	 *
	 * The markup assertions that used to live here described a server-rendered
	 * form that no longer exists — Inventory is a React screen writing through
	 * REST. What they were really protecting survives the move and is asserted
	 * where it now lives: there is no delete affordance and no route to reach
	 * one. Deleting a placement would orphan every package that sells it and
	 * every campaign snapshot that bought one.
	 *
	 * @return void
	 */
	public function test_a_placement_can_never_be_deleted(): void {
		$controller = file_get_contents( AGGR_PLUGIN_DIR . 'inc/REST/class-placements-controller.php' );
		$screen     = file_get_contents( AGGR_PLUGIN_DIR . 'src/admin/inventory/index.tsx' );

		$this->assertIsString( $controller );
		$this->assertIsString( $screen );
		$this->assertStringNotContainsString( "'DELETE'", $controller );
		$this->assertStringNotContainsString( 'Delete placement', $screen );
		$this->assertStringContainsString( 'is_active', $controller );
	}

	/**
	 * Inventory keeps the size choices the deleted template offered.
	 *
	 * The common-size list and the custom escape hatch are the whole point of
	 * the screen: without the second, a site with a slot that is not an IAB
	 * size cannot sell it at all.
	 *
	 * @return void
	 */
	public function test_inventory_offers_common_and_custom_sizes(): void {
		$screen = file_get_contents( AGGR_PLUGIN_DIR . 'src/admin/inventory/index.tsx' );
		$php    = file_get_contents( AGGR_PLUGIN_DIR . 'inc/Admin/class-placement-screen.php' );

		$this->assertIsString( $screen );
		$this->assertIsString( $php );

		// The strings live in PHP because make-pot does not parse .tsx.
		$this->assertStringContainsString( "'customSize'", $screen );
		$this->assertStringContainsString( 'Custom size', $php );
		$this->assertStringContainsString( 'Create placement', $php );
		// A translation call would need this import, and make-pot would still
		// never see the string. Asserting on the import rather than on "__("
		// matters: the only "__(" in the file is the comment saying not to.
		$this->assertStringNotContainsString( "from '@wordpress/i18n'", $screen );
	}

	/**
	 * A package is deactivated, never deleted.
	 *
	 * The markup assertions that used to live here described a server-rendered
	 * form that no longer exists — the catalogue is a React screen writing
	 * through REST. What they were really protecting survives the move and is
	 * asserted where it now lives: there is no delete affordance and no route to
	 * reach one. Deleting a package would orphan the snapshot every campaign that
	 * bought it still points at.
	 *
	 * @return void
	 */
	public function test_a_package_can_never_be_deleted(): void {
		$controller = file_get_contents( AGGR_PLUGIN_DIR . 'inc/REST/class-packages-controller.php' );
		$screen     = file_get_contents( AGGR_PLUGIN_DIR . 'src/admin/packages/index.tsx' );

		$this->assertIsString( $controller );
		$this->assertIsString( $screen );

		$this->assertStringNotContainsString( "'DELETE'", $controller );
		$this->assertStringNotContainsString( "method: 'DELETE'", $screen );
		$this->assertStringNotContainsString( 'Delete package', $screen );
	}

	/**
	 * Money never becomes a float on its way to the server.
	 *
	 * Prices are integer cents end to end. Parsing "12.34" as a float and
	 * multiplying by 100 yields 1233.9999999999998, and (int) of that is 1233 —
	 * a penny lost per package, silently, on a value customers are charged.
	 *
	 * @return void
	 */
	public function test_the_package_screen_converts_price_without_floating_point(): void {
		$screen = file_get_contents( AGGR_PLUGIN_DIR . 'src/admin/packages/index.tsx' );

		$this->assertIsString( $screen );
		$this->assertStringNotContainsString( 'parseFloat', $screen );
		$this->assertStringContainsString( 'function toCents', $screen );
	}

	/**
	 * Native delivery is not a Modules checkbox. Unchecking it would black out
	 * the public site with no fallback publisher.
	 *
	 * @return void
	 */
	public function test_settings_screen_does_not_offer_native_delivery(): void {
		// The toggle list moved out of a template and into the payload the React
		// screen is hydrated with. The rule did not move: whichever file builds
		// the list is the file that must never build this one.
		$screen = file_get_contents( AGGR_PLUGIN_DIR . 'inc/Admin/class-settings-screen.php' );

		$this->assertIsString( $screen );
		$this->assertStringNotContainsString( 'MODULE_NATIVE_DELIVERY', $screen );
		$this->assertStringNotContainsString( 'Native delivery', $screen );
		$this->assertStringNotContainsString( 'native_delivery', $screen );
	}
}
