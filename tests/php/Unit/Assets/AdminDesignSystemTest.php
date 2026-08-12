<?php
/**
 * Design-system constraints for the staff review surface.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Assets;

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
		$css = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'src/styles/admin.css' );

		$this->assertIsString( $css, 'src/styles/admin.css must be readable.' );
		$this->assertStringContainsString( '--laao-ads-', $css );
		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css, 'Admin CSS introduced a literal color outside the token layer.' );
	}

	/**
	 * Interactive controls keep the shared minimum touch target.
	 *
	 * @return void
	 */
	public function test_admin_navigation_uses_the_control_size_token(): void {
		$css = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'src/styles/admin.css' );

		$this->assertIsString( $css );
		$this->assertGreaterThanOrEqual( 2, substr_count( $css, 'var(--laao-ads-control-min)' ) );
		$this->assertStringNotContainsString( '@layer', $css, 'Layered component rules lose to unlayered wp-admin element styles.' );
	}

	/**
	 * The review templates retain their keyboard and form semantics.
	 *
	 * @return void
	 */
	public function test_review_templates_keep_accessible_structure(): void {
		$queue  = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/admin/review-queue.php' );
		$detail = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/admin/review-campaign.php' );

		$this->assertIsString( $queue );
		$this->assertIsString( $detail );
		$this->assertStringContainsString( 'aria-current="page"', $queue );
		$this->assertStringContainsString( '<th scope="col">', $queue );
		$this->assertStringContainsString( 'wp_nonce_field', $detail );
		$this->assertStringContainsString( '<label for=', $detail );
		$this->assertStringContainsString( 'required>', $detail );
	}

	/**
	 * Placement mapping remains a real, labeled, nonce-protected form surface.
	 *
	 * @return void
	 */
	public function test_mapping_template_keeps_accessible_form_and_table_semantics(): void {
		$template = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/admin/placement-mapping.php' );

		$this->assertIsString( $template );
		$this->assertStringContainsString( '<th scope="row"', $template );
		$this->assertStringContainsString( '<th scope="col">', $template );
		$this->assertStringContainsString( 'wp_nonce_field', $template );
		$this->assertStringContainsString( '<label class="screen-reader-text" for=', $template );
		$this->assertStringContainsString( '<select id=', $template );
		$this->assertStringContainsString( 'role="alert"', $template );
		$this->assertStringContainsString( 'Not mapped — approval blocked', $template );
	}
}
