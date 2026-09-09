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

		// The campaign summary's facts moved out of the screen when it reached
		// the file-length gate. The guard follows them: reading the screen for
		// a block that is no longer in it is how this stops watching anything.
		$facts = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-summary-facts.php' );

		$this->assertIsString( $dashboard );
		$this->assertIsString( $table );
		$this->assertIsString( $detail );
		$this->assertIsString( $facts );
		$this->assertStringContainsString( 'delivery_counts()', $dashboard );
		$this->assertStringContainsString( 'delivery_series()', $dashboard );
		$this->assertStringContainsString( 'partials/sparkline.php', $dashboard );
		$this->assertStringContainsString( 'Impressions and clicks from native delivery', $dashboard );
		$this->assertStringContainsString( '$aggr_show_metrics', $table );
		$this->assertStringContainsString( 'CTR', $table );
		$this->assertStringContainsString( 'partials/campaign-summary-facts.php', $detail );
		$this->assertStringContainsString( "isset( \$aggr_campaign['impressions'], \$aggr_campaign['clicks'] )", $facts );
		$this->assertStringContainsString( 'aggr-sizebox', $detail );

		/*
		 * Conversions are gated the same way and, unlike the others, have an
		 * absence that must not render as a number: a campaign nothing measured
		 * says so in words. A template that printed `0` there would claim the
		 * campaign converted nobody.
		 */
		$this->assertStringContainsString( 'Not measured', $facts );
		$this->assertStringContainsString( 'Not measured', $table );
	}

	/**
	 * The share control appears only where there is something to share with.
	 *
	 * A weight beside a single creative claims a choice the selector never
	 * makes: with nothing to compete against, every weight delivers the same
	 * hundred per cent. Rendering it anyway would be the interface asserting
	 * behaviour the domain does not have — the failure this codebase keeps
	 * finding from the other direction, where a control exists over a mechanism
	 * that does nothing.
	 *
	 * @return void
	 */
	public function test_the_share_control_needs_a_second_creative_to_appear(): void {
		$partial = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-variant-share.php' );

		$this->assertIsString( $partial );

		$this->assertStringContainsString(
			"count( \$aggr_slot['creatives'] ) < 2",
			$partial,
			'The share control no longer checks that the placement holds a second creative.'
		);

		/*
		 * A creative with no assignment carries a null weight rather than zero,
		 * and the control must not offer to set a share for something that is
		 * not delivering yet.
		 */
		$this->assertStringContainsString( 'null === $aggr_share_weight', $partial );

		// The bounds come from the domain rather than being retyped here.
		$this->assertStringContainsString( 'Assignment_Rules::MIN_WEIGHT', $partial );
		$this->assertStringContainsString( 'Assignment_Rules::MAX_WEIGHT', $partial );

		// And the write is nonce-protected, like every other portal write.
		$this->assertStringContainsString( 'weight_nonce_action', $partial );
	}

	/**
	 * **The wizard lets a placement carry more than one creative.**
	 *
	 * It used to require exactly one, which contradicted the only two rules
	 * that decide anything: `Creative_Manager` permits ten on a placement, and
	 * `Campaign_Validator` accepts a placement covered by any number of them,
	 * because `Coverage_Service` counts a placement as covered once however
	 * many cover it. An advertiser could upload a second creative, was
	 * permitted to, would have been accepted — and was stopped by a wizard step
	 * telling them a placement needs exactly one.
	 *
	 * That is why weighted variants were unreachable without calling the REST
	 * route by hand, despite delivery having supported them all along.
	 *
	 * Asserted on the template because that is where the contradicting rule
	 * lived. A count comparison here is the shape of the defect: `1 !==` is a
	 * cap, `array() ===` is a requirement, and only one of them agrees with the
	 * validator.
	 *
	 * @return void
	 */
	public function test_the_creative_step_requires_at_least_one_not_exactly_one(): void {
		$screen = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );

		$this->assertIsString( $screen );

		$this->assertStringContainsString(
			"array() === \$aggr_slot['creatives']",
			$screen,
			'The creative step no longer asks whether a placement has any creative at all.'
		);
		$this->assertStringNotContainsString(
			"1 !== count( \$aggr_slot['creatives'] )",
			$screen,
			'The wizard requires exactly one creative again, so a second variant is a dead end.'
		);

		/*
		 * And the copy agrees with the rule. A gate that accepts two while its
		 * own sentence says "exactly one" teaches the advertiser the wrong
		 * thing about their own campaign.
		 */
		$this->assertStringNotContainsString( 'needs exactly one creative', $screen );
		$this->assertStringContainsString( 'at least one creative', $screen );
	}

	/**
	 * The reporting window governs the figures it sits beside, and only those.
	 *
	 * The dashboard shows two kinds of number: all-time counts of where each
	 * campaign has got to, and delivery for a chosen window. Both were rendered
	 * as the same `aggr-stat` card with the window picker floating between
	 * them, so narrowing to seven days read as "3 campaigns ran this week" —
	 * a correct figure answering a question nobody asked, which misinforms just
	 * as effectively as a wrong one.
	 *
	 * Order is the assertion because order is the fix: the pipeline counts come
	 * first in their own markup, then the delivery section, then the picker
	 * inside it, then the tiles the picker changes.
	 *
	 * @return void
	 */
	public function test_the_reporting_window_is_scoped_to_the_delivery_figures(): void {
		$dashboard = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/dashboard.php' );

		$this->assertIsString( $dashboard );

		$counts  = strpos( $dashboard, 'class="aggr-pipeline"' );
		$section = strpos( $dashboard, 'class="aggr-delivery"' );
		$picker  = strpos( $dashboard, 'class="aggr-range"' );
		$tiles   = strpos( $dashboard, 'class="aggr-stats"' );

		$this->assertIsInt( $counts, 'The pipeline counts render as their own list, not as metric tiles.' );
		$this->assertIsInt( $section, 'Delivery is a section so the picker has something to belong to.' );
		$this->assertIsInt( $picker );
		$this->assertIsInt( $tiles );

		$this->assertLessThan( $section, $counts );
		$this->assertLessThan( $picker, $section, 'The picker must sit inside the section it governs.' );
		$this->assertLessThan( $tiles, $picker );

		$this->assertSame(
			1,
			substr_count( $dashboard, 'class="aggr-stats"' ),
			'A second tile row is how the two kinds of number became indistinguishable.'
		);
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
		$this->assertStringContainsString( '.aggr-pipeline__value', $css );
		$this->assertStringContainsString( '.aggr-delivery__head', $css );
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
