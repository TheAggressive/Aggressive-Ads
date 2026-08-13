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
	 * The package catalogue is a labeled, nonce-protected form with no delete.
	 *
	 * @return void
	 */
	public function test_packages_template_keeps_accessible_form_semantics(): void {
		$template = file_get_contents( AGGR_PLUGIN_DIR . 'templates/admin/packages.php' );

		$this->assertIsString( $template );
		$this->assertStringContainsString( 'wp_nonce_field', $template );
		$this->assertStringContainsString( '<label for=', $template );
		$this->assertStringContainsString( 'admin-post.php', $template );
		$this->assertStringContainsString( 'required maxlength="120"', $template );
		$this->assertStringNotContainsString( 'Delete package', $template );
	}

	/**
	 * Native delivery is not a Modules checkbox. Unchecking it would black out
	 * the public site with no fallback publisher.
	 *
	 * @return void
	 */
	public function test_settings_template_does_not_offer_native_delivery(): void {
		$template = file_get_contents( AGGR_PLUGIN_DIR . 'templates/admin/settings.php' );

		$this->assertIsString( $template );
		$this->assertStringNotContainsString( 'MODULE_NATIVE_DELIVERY', $template );
		$this->assertStringNotContainsString( 'Native delivery', $template );
		$this->assertStringNotContainsString( 'native_delivery', $template );
	}
}
