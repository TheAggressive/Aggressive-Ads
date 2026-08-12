<?php
/**
 * Pins the shared dialog / replace-ad UI contract.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Assets;

use LAAO_Advertiser_Portal\Assets\Assets;
use PHPUnit\Framework\TestCase;

/**
 * Structural assertions for the replace-ad dialog surface.
 */
final class DialogDesignSystemTest extends TestCase {

	/**
	 * Update uses the shared overlay, not an expanding details card.
	 *
	 * @return void
	 */
	public function test_replace_ads_use_dialog_markup(): void {
		$cards    = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/campaign-ad-updates.php' );
		$dialogs  = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/creative-replace-dialogs.php' );
		$campaign = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/campaign.php' );

		$this->assertIsString( $cards );
		$this->assertIsString( $dialogs );
		$this->assertIsString( $campaign );

		$this->assertStringContainsString( 'campaign-ad-updates.php', $campaign );
		$this->assertStringNotContainsString( '<details', $cards );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $cards );
		$this->assertStringContainsString( 'aria-controls="', $cards );
		$this->assertStringContainsString( 'href="#<?php echo esc_attr( $laao_ads_dialog_id ); ?>"', $cards );
		$this->assertStringContainsString( 'data-wp-init="actions.init"', $dialogs );
		$this->assertStringContainsString( 'data-laao-ads-dialog-close', $dialogs );

		$this->assertStringContainsString( 'role="dialog"', $dialogs );
		$this->assertStringContainsString( 'aria-modal="true"', $dialogs );
		$this->assertStringContainsString( 'laao-ads-overlay', $dialogs );
		$this->assertStringContainsString( 'Creative_Actions::REPLACE_ACTION', $dialogs );
		$this->assertStringContainsString( 'Assets::DIALOG_STORE', $dialogs );
	}

	/**
	 * Overlay CSS uses the single stacking token and reduced-motion duration.
	 *
	 * @return void
	 */
	public function test_overlay_styles_use_dialog_tokens(): void {
		$css = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'assets/portal.css' );

		$this->assertIsString( $css );
		$this->assertStringContainsString( '--laao-ads-z-dialog:', $css );
		$this->assertStringContainsString( '--laao-ads-shadow-panel:', $css );
		$this->assertStringContainsString( '--laao-ads-duration-dialog:', $css );
		$this->assertStringContainsString( 'z-index: var(--laao-ads-z-dialog)', $css );
		$this->assertStringContainsString( '.laao-ads-overlay:not(.is-open):not(:target)', $css );
		$this->assertSame( 1, substr_count( $css, '--laao-ads-z-dialog:' ) );
	}

	/**
	 * Dialog script modules ship with the plugin and are registered by Assets.
	 *
	 * @return void
	 */
	public function test_dialog_modules_exist_on_disk(): void {
		foreach ( array( 'scroll-lock.js', 'helpers.js', 'dialog.js' ) as $file ) {
			$this->assertFileExists( LAAO_ADS_PLUGIN_DIR . 'assets/interactivity/' . $file );
		}

		$assets = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'inc/Assets/class-assets.php' );
		$dialog = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'assets/interactivity/dialog.js' );
		$this->assertIsString( $assets );
		$this->assertIsString( $dialog );
		$this->assertStringContainsString( 'enqueue_dialog', $assets );
		$this->assertStringContainsString( Assets::MODULE_DIALOG, $assets );
		$this->assertStringContainsString( '@wordpress/interactivity', $assets );
		$this->assertStringContainsString( 'bindControls', $dialog );
		$this->assertStringContainsString( 'classList.add', $dialog );
	}
}
