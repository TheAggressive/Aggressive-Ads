<?php
/**
 * Pins the shared dialog / replace-ad UI contract.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

use Aggressive\Ads\Assets\Assets;
use PHPUnit\Framework\TestCase;

/**
 * Structural assertions for the shared campaign dialog overlay.
 */
final class DialogDesignSystemTest extends TestCase {

	/**
	 * Update uses the shared overlay, not an expanding details card.
	 *
	 * @return void
	 */
	public function test_campaign_dialogs_use_the_shared_overlay(): void {
		// The panel, the cards it draws and the card they share, read together.
		$cards    = implode(
			"\n",
			array_map(
				static fn ( string $file ): string => (string) file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/' . $file ),
				array( 'campaign-ad-updates.php', 'campaign-edit-ads.php', 'campaign-ad-card.php' )
			)
		);
		$overlays = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php' );

		/*
		 * The screen and the wizard step it requires, read together.
		 *
		 * These assertions are about the campaign wizard's dialogs, not about
		 * which file a line sits in. Reading only the screen made them fail the
		 * moment the creative step moved to a partial — the markup was
		 * unchanged and the guard had simply stopped looking at it.
		 */
		$screen = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );
		$step   = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-creative-step.php' ) . file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-ad-card.php' );

		$this->assertIsString( $cards );
		$this->assertIsString( $overlays );
		$this->assertIsString( $screen );
		$this->assertIsString( $step );

		$campaign = $screen . "\n" . $step;

		$this->assertStringContainsString( 'campaign-ad-updates.php', $campaign );
		$this->assertStringContainsString( 'campaign-overlays.php', $campaign );
		$this->assertStringContainsString( 'campaign-overlays.php', $cards );
		$this->assertStringNotContainsString( '<details', $cards );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $cards );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $campaign );
		// Every card action is a link to its dialog, drawn by the shared card from the actions each caller passes.
		$this->assertStringContainsString( 'href="#<?php echo esc_attr( $aggr_card_action[1] ); ?>"', $cards );
		$this->assertStringContainsString( "array( __( 'Update', 'aggressive-ads' ), \$aggr_replace_id, false )", $cards );
		$this->assertStringContainsString( "array( __( 'Preview', 'aggressive-ads' ), \$aggr_preview_id, false )", $campaign );
		$this->assertStringContainsString( "array( __( 'Remove', 'aggressive-ads' ), \$aggr_remove_id, true )", $campaign );
		$this->assertStringNotContainsString( 'Creative_Actions::REMOVE_ACTION', $campaign );

		$this->assertStringContainsString( 'enqueue_dialog', $overlays );
		$this->assertStringContainsString( 'wp_footer', $overlays );
		$this->assertStringContainsString( 'data-wp-init="actions.init"', $overlays );
		$this->assertStringContainsString( 'data-aggr-dialog-close', $overlays );
		$this->assertStringContainsString( 'role="dialog"', $overlays );
		$this->assertStringContainsString( 'aria-modal="true"', $overlays );
		$this->assertStringContainsString( 'Creative_Actions::REPLACE_ACTION', $overlays );
		$this->assertStringContainsString( 'Creative_Actions::REMOVE_ACTION', $overlays );
		$this->assertStringContainsString( 'Assets::DIALOG_STORE', $overlays );
		$this->assertStringContainsString( 'aggr-overlay--preview', $overlays );
		$this->assertStringContainsString( 'Remove this creative?', $overlays );
		// Each action says which ad it acts on, not only what it does.
		$this->assertStringContainsString( '$aggr_card_place', $cards );
	}

	/**
	 * Overlay CSS uses the single stacking token and reduced-motion duration.
	 *
	 * @return void
	 */
	public function test_overlay_styles_use_dialog_tokens(): void {
		$css = Portal_Styles::contents();

		$this->assertStringContainsString( '--aggr-z-dialog:', $css );
		$this->assertStringContainsString( '--aggr-shadow-panel:', $css );
		$this->assertStringContainsString( '--aggr-duration-dialog:', $css );
		$this->assertStringContainsString( 'z-index: var(--aggr-z-dialog)', $css );
		$this->assertStringContainsString( '.aggr-overlay:not(.is-open):not(:target)', $css );
		$this->assertStringContainsString( '.aggr-overlay--preview', $css );
		$this->assertSame( 1, substr_count( $css, '--aggr-z-dialog:' ) );
	}

	/**
	 * Dialog script modules ship with the plugin and are registered by Assets.
	 *
	 * @return void
	 */
	public function test_dialog_modules_exist_on_disk(): void {
		foreach ( array( 'scroll-lock.ts', 'helpers.ts', 'dialog.ts', 'logic.ts', 'wizard.ts', 'autosave.ts', 'upload.ts' ) as $file ) {
			$this->assertFileExists( AGGR_PLUGIN_DIR . 'src/interactivity/' . $file );
		}

		$assets = file_get_contents( AGGR_PLUGIN_DIR . 'inc/Assets/class-assets.php' );
		$dialog = file_get_contents( AGGR_PLUGIN_DIR . 'src/interactivity/dialog.ts' );
		$this->assertIsString( $assets );
		$this->assertIsString( $dialog );
		$this->assertStringContainsString( 'enqueue_dialog', $assets );
		$this->assertStringContainsString( 'hydrate_campaign_editor', $assets );
		$this->assertStringContainsString( Assets::MODULE_DIALOG, $assets );
		$this->assertStringContainsString( Assets::MODULE_WIZARD, $assets );
		$this->assertStringContainsString( '@wordpress/interactivity', $assets );
		$this->assertStringContainsString( 'bindControls', $dialog );
		$this->assertStringContainsString( 'classList.add', $dialog );
	}
}
