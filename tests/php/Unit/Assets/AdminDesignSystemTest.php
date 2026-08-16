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
	 * The review templates retain their keyboard and form semantics.
	 *
	 * @return void
	 */
	public function test_review_templates_keep_accessible_structure(): void {
		$queue  = file_get_contents( AGGR_PLUGIN_DIR . 'templates/admin/review-queue.php' );
		$detail = file_get_contents( AGGR_PLUGIN_DIR . 'templates/admin/review-campaign.php' );

		$this->assertIsString( $queue );
		$this->assertIsString( $detail );
		$this->assertStringContainsString( 'aria-current="page"', $queue );
		$this->assertStringContainsString( '<th scope="col">', $queue );
		$this->assertStringContainsString( 'wp_nonce_field', $detail );
		$this->assertStringContainsString( '<label for=', $detail );
		$this->assertStringContainsString( 'required>', $detail );
	}

	/**
	 * Inventory remains a labeled, nonce-protected form. There is no delete.
	 *
	 * @return void
	 */
	public function test_inventory_template_keeps_accessible_form_semantics(): void {
		$template = file_get_contents( AGGR_PLUGIN_DIR . 'templates/admin/placements.php' );

		$this->assertIsString( $template );
		$this->assertStringContainsString( 'wp_nonce_field', $template );
		$this->assertStringContainsString( '<label for=', $template );
		$this->assertStringContainsString( 'admin-post.php', $template );
		$this->assertStringContainsString( 'Create placement', $template );
		$this->assertStringContainsString( 'Custom size', $template );
		$this->assertStringNotContainsString( 'Delete placement', $template );
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
