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
		$cards    = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-ad-updates.php' );
		$overlays = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php' );
		$campaign = file_get_contents( AGGR_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );

		$this->assertIsString( $cards );
		$this->assertIsString( $overlays );
		$this->assertIsString( $campaign );

		$this->assertStringContainsString( 'campaign-ad-updates.php', $campaign );
		$this->assertStringContainsString( 'campaign-overlays.php', $campaign );
		$this->assertStringContainsString( 'campaign-overlays.php', $cards );
		$this->assertStringNotContainsString( '<details', $cards );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $cards );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $campaign );
		$this->assertStringContainsString( 'href="#<?php echo esc_attr( $aggr_dialog_id ); ?>"', $cards );
		$this->assertStringContainsString( 'href="#<?php echo esc_attr( $aggr_preview_id ); ?>"', $campaign );
		$this->assertStringContainsString( 'href="#<?php echo esc_attr( $aggr_remove_id ); ?>"', $campaign );
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
		$this->assertStringContainsString( 'View larger preview of', $cards );
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
