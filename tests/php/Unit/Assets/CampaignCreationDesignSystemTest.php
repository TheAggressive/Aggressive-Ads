<?php
/**
 * Design-system and accessibility constraints for campaign creation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;
use Aggressive\Ads\Portal\Campaign_Nonces;

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
		$list   = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/campaigns.php' );
		$detail = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );
		$base   = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/base.php' );

		$this->assertIsString( $list );
		$this->assertIsString( $detail );
		$this->assertIsString( $base );
		$this->assertStringContainsString( 'show_admin_bar( false )', $base );
		$this->assertStringContainsString( "remove_action( 'wp_body_open', 'wp_admin_bar_render', 0 )", $base );
		$this->assertStringContainsString( 'Campaign_Actions::CREATE_ACTION', $list );
		$this->assertStringContainsString( 'Campaign_Actions::COPY_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Nonces::copy_nonce_action', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::SAVE_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::SAVE_PACKAGE_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::SAVE_SCHEDULE_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Nonces::schedule_nonce_action', $detail );
		$this->assertStringContainsString( 'Campaign_Actions::SUBMIT_ACTION', $detail );
		$this->assertStringContainsString( 'Campaign_Nonces::submit_nonce_action', $detail );
		$this->assertStringContainsString( 'Creative_Actions::UPLOAD_ACTION', $detail );
		$this->assertStringContainsString( 'campaign-overlays.php', $detail );
		$overlays = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php' );
		$this->assertIsString( $overlays );
		$this->assertStringContainsString( 'Creative_Actions::REMOVE_ACTION', $overlays );
		$this->assertStringContainsString( 'wp_nonce_field', $list );
		$this->assertStringContainsString( 'wp_nonce_field', $detail );
		$this->assertStringContainsString( 'type="date"', $detail );
		$this->assertStringContainsString( 'min="<?php echo esc_attr( $aggr_min_start_date ); ?>"', $detail );
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
		$template = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );
		$progress = array();

		$this->assertIsString( $template );
		$this->assertStringContainsString( 'aria-current="step"', $template );
		$this->assertStringContainsString( 'Campaign creation progress', $template );
		$this->assertSame( 1, preg_match( '/<ol class="aggr-steps".*?<\/ol>/s', $template, $progress ) );
		$this->assertSame( 6, substr_count( $progress[0], '<li' ), 'The documented wizard has six named steps.' );
		$this->assertStringContainsString( 'role="alert"', $template );
		$this->assertStringContainsString( 'aria-describedby=', $template );
		$this->assertStringContainsString( 'aggr-readiness-heading', $template );
		$this->assertStringContainsString( 'aggr-review-details-heading', $template );
		$this->assertStringContainsString( 'aggr-review-creative-heading', $template );
		$this->assertStringContainsString( 'aggr-submit-heading', $template );
		$this->assertStringContainsString( 'Submit campaign for review', $template );
		$this->assertStringContainsString( '<fieldset', $template );
		$this->assertStringContainsString( '<legend>', $template );
		$this->assertStringContainsString( 'Assets::WIZARD_STORE', $template );
		$this->assertStringContainsString( 'Assets::AUTOSAVE_STORE', $template );
		$this->assertStringContainsString( 'Assets::UPLOAD_STORE', $template );
		$this->assertStringContainsString( 'tabindex="-1"', $template );
		$this->assertStringContainsString( 'id="aggr-details-heading"', $template );
	}

	/**
	 * Metric tiles are gated on View_Data, not hardcoded zeros in the template.
	 *
	 * @return void
	 */
	public function test_dashboard_metrics_are_gated(): void {
		$dashboard = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/dashboard.php' );
		$table     = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-table.php' );
		$detail    = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );

		$this->assertIsString( $dashboard );
		$this->assertIsString( $table );
		$this->assertIsString( $detail );
		$this->assertStringContainsString( 'delivery_counts()', $dashboard );
		$this->assertStringContainsString( 'delivery_series()', $dashboard );
		$this->assertStringContainsString( 'partials/sparkline.php', $dashboard );
		$this->assertStringContainsString( 'Impressions and clicks from native delivery', $dashboard );
		$this->assertStringContainsString( '$aggr_show_metrics', $table );
		$this->assertStringContainsString( 'CTR', $table );
		$this->assertStringContainsString( "isset( \$aggr_campaign['impressions'], \$aggr_campaign['clicks'] )", $detail );
		$this->assertStringContainsString( 'aggr-sizebox', $detail );
	}

	/**
	 * New controls use the shared touch-target and color token vocabulary.
	 *
	 * @return void
	 */
	public function test_creation_styles_use_shared_tokens(): void {
		$css = Portal_Styles::contents();

		$this->assertStringContainsString( '.aggr-form', $css );
		$this->assertStringContainsString( '.aggr-steps', $css );
		$this->assertStringContainsString( '.aggr-choice--package', $css );
		$this->assertStringContainsString( '.aggr-sizebox', $css );
		$this->assertStringContainsString( '.aggr-dashboard--split', $css );
		$this->assertStringContainsString( '.aggr-spark__track', $css );
		$this->assertStringContainsString( '.aggr-button--secondary', $css );
		$this->assertStringContainsString( '.aggr-upload-card', $css );
		$this->assertStringContainsString( '.aggr-upload-form', $css );
		$this->assertStringContainsString( '.aggr-confirmation', $css );
		$this->assertStringContainsString( '.aggr-destination-card', $css );
		$this->assertStringContainsString( '.aggr-readiness--ready', $css );
		$this->assertStringContainsString( '.aggr-readiness--issues', $css );
		$this->assertStringContainsString( '.aggr-review-card', $css );
		$this->assertStringContainsString( '.aggr-review-creative', $css );
		$this->assertStringContainsString( '.aggr-submit-card', $css );
		$this->assertStringContainsString( '.aggr-sr', $css );
		$this->assertStringContainsString( '.aggr-upload-form.is-drop-target', $css );
		$this->assertStringContainsString( '::file-selector-button', $css );
		$this->assertStringContainsString( 'min-height: var(--aggr-control-min)', $css );
		$this->assertStringContainsString( 'var(--aggr-color-danger-tint)', $css );
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
		$css = Portal_Styles::contents();

		$this->assertSame( 2, substr_count( $css, '@layer aggr-tokens' ) );
		$this->assertStringNotContainsString( '@layer aggr-reset', $css );
		$this->assertStringNotContainsString( '@layer aggr-layout', $css );
		$this->assertStringNotContainsString( '@layer aggr-components', $css );
	}

	/**
	 * Archivo is self-hosted. A Google Fonts URL is a privacy defect.
	 *
	 * @return void
	 */
	public function test_archivo_is_self_hosted(): void {
		$css = Portal_Styles::contents();

		$this->assertStringContainsString( '@font-face', $css );
		$this->assertStringContainsString( 'archivo-latin-wght-normal.woff2', $css );
		$this->assertStringContainsString( 'font-display: swap', $css );
		$this->assertStringNotContainsString( 'fonts.googleapis.com', $css );
		$this->assertStringNotContainsString( 'fonts.gstatic.com', $css );
		$this->assertFileExists( AGGR_PLUGIN_DIR . 'src/styles/fonts/archivo-latin-wght-normal.woff2' );
		$this->assertFileExists( AGGR_PLUGIN_DIR . 'src/styles/fonts/archivo-latin-ext-wght-normal.woff2' );
		$this->assertFileExists( AGGR_PLUGIN_DIR . 'assets/fonts/OFL.txt' );
	}
}
