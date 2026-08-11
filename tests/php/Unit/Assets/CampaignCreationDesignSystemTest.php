<?php
/**
 * Design-system and accessibility constraints for campaign creation.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Pins the semantic structure that automated CSS review cannot infer.
 */
final class CampaignCreationDesignSystemTest extends TestCase {

	/**
	 * Creation remains a real authenticated form without requiring JavaScript.
	 *
	 * @return void
	 */
	public function test_campaign_creation_has_a_progressive_form_path(): void {
		$list   = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/campaigns.php' );
		$detail = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );
		$base   = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/portal/base.php' );

		$this->assertIsString( $list );
		$this->assertIsString( $detail );
		$this->assertIsString( $base );
		$this->assertStringContainsString( 'show_admin_bar( false )', $base );
		$this->assertStringContainsString( "remove_action( 'wp_body_open', 'wp_admin_bar_render', 0 )", $base );
		$this->assertStringContainsString( 'Campaign_Actions::CREATE_ACTION', $list );
		$this->assertStringContainsString( 'Campaign_Actions::SAVE_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::SAVE_PACKAGE_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::SAVE_SCHEDULE_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::schedule_nonce_action', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::SUBMIT_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::submit_nonce_action', $detail );
		$this->assertStringContainsString( 'Creative_Actions::UPLOAD_ACTION', $detail );
		$this->assertStringContainsString( 'Creative_Actions::REMOVE_ACTION', $detail );
		$this->assertStringContainsString( 'wp_nonce_field', $list );
		$this->assertStringContainsString( 'wp_nonce_field', $detail );
		$this->assertStringContainsString( 'type="date"', $detail );
		$this->assertStringContainsString( 'min="<?php echo esc_attr( $laao_ads_min_start_date ); ?>"', $detail );
		$this->assertStringContainsString( 'type="checkbox"', $detail );
		$this->assertStringContainsString( 'type="radio"', $detail );
		$this->assertStringContainsString( 'type="file"', $detail );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $detail );
		$this->assertStringContainsString( 'accept="image/jpeg,image/png,image/gif,image/webp"', $detail );
		$this->assertStringContainsString( 'required', $detail );
	}

	/**
	 * Wizard progress and validation retain their announced semantics.
	 *
	 * @return void
	 */
	public function test_wizard_structure_is_accessible(): void {
		$template = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );
		$progress = array();

		$this->assertIsString( $template );
		$this->assertStringContainsString( 'aria-current="step"', $template );
		$this->assertStringContainsString( 'Campaign creation progress', $template );
		$this->assertSame( 1, preg_match( '/<ol class="laao-ads-steps".*?<\/ol>/s', $template, $progress ) );
		$this->assertSame( 6, substr_count( $progress[0], '<li' ), 'The documented wizard has six named steps.' );
		$this->assertStringContainsString( 'role="alert"', $template );
		$this->assertStringContainsString( 'aria-describedby=', $template );
		$this->assertStringContainsString( 'laao-ads-readiness-heading', $template );
		$this->assertStringContainsString( 'laao-ads-review-details-heading', $template );
		$this->assertStringContainsString( 'laao-ads-review-creative-heading', $template );
		$this->assertStringContainsString( 'laao-ads-submit-heading', $template );
		$this->assertStringContainsString( 'Submit campaign for review', $template );
		$this->assertStringContainsString( '<fieldset', $template );
		$this->assertStringContainsString( '<legend>', $template );
	}

	/**
	 * New controls use the shared touch-target and color token vocabulary.
	 *
	 * @return void
	 */
	public function test_creation_styles_use_shared_tokens(): void {
		$css = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'assets/portal.css' );

		$this->assertIsString( $css );
		$this->assertStringContainsString( '.laao-ads-form', $css );
		$this->assertStringContainsString( '.laao-ads-steps', $css );
		$this->assertStringContainsString( '.laao-ads-choice--package', $css );
		$this->assertStringContainsString( '.laao-ads-button--secondary', $css );
		$this->assertStringContainsString( '.laao-ads-upload-card', $css );
		$this->assertStringContainsString( '.laao-ads-upload-form', $css );
		$this->assertStringContainsString( '.laao-ads-confirmation', $css );
		$this->assertStringContainsString( '.laao-ads-destination-card', $css );
		$this->assertStringContainsString( '.laao-ads-readiness--ready', $css );
		$this->assertStringContainsString( '.laao-ads-readiness--issues', $css );
		$this->assertStringContainsString( '.laao-ads-review-card', $css );
		$this->assertStringContainsString( '.laao-ads-review-creative', $css );
		$this->assertStringContainsString( '.laao-ads-submit-card', $css );
		$this->assertStringContainsString( '::file-selector-button', $css );
		$this->assertStringContainsString( 'min-height: var(--laao-ads-control-min)', $css );
		$this->assertStringContainsString( 'var(--laao-ads-color-danger-tint)', $css );
	}

	/**
	 * Host-theme element rules cannot outrank the scoped component system.
	 *
	 * Token defaults stay layered so a theme can override them deliberately.
	 * Component rules must remain unlayered because the CSS cascade gives every
	 * unlayered declaration priority over every normal declaration in a layer,
	 * regardless of selector specificity.
	 *
	 * @return void
	 */
	public function test_only_token_defaults_use_a_cascade_layer(): void {
		$css = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'assets/portal.css' );

		$this->assertIsString( $css );
		$this->assertSame( 2, substr_count( $css, '@layer laao-ads-tokens' ) );
		$this->assertStringNotContainsString( '@layer laao-ads-reset', $css );
		$this->assertStringNotContainsString( '@layer laao-ads-layout', $css );
		$this->assertStringNotContainsString( '@layer laao-ads-components', $css );
	}
}
