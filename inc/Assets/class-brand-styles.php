<?php
/**
 * Inline brand token overrides on .aggr-portal.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Assets;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Contrast;
use Aggressive\Ads\Domain\Settings_Schema;

/**
 * Prints after the compiled stylesheet so Brand wins on the keys it owns.
 */
final class Brand_Styles implements Service {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings document.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Attaches late enough that portal and admin styles are already queued.
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'print' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'print' ), 20 );
	}

	/**
	 * Adds inline custom properties when a stored document exists.
	 */
	public function print(): void {
		if ( ! wp_style_is( Assets::HANDLE, 'enqueued' ) || ! $this->settings->has_store() ) {
			return;
		}

		$brand = $this->settings->get()['brand'];
		$css   = sprintf(
			'.aggr-portal{--aggr-color-accent:%1$s;--aggr-color-accent-strong:%2$s;--aggr-color-canvas:%3$s;--aggr-color-surface:%4$s;--aggr-color-text:%5$s;--aggr-color-on-accent:%6$s;--aggr-color-primary:%5$s;--aggr-color-on-primary:%7$s;}',
			$brand['accent'],
			$brand['accent_strong'],
			$brand['canvas'],
			$brand['surface'],
			$brand['text'],
			// Derived, not stored: the label ink follows whatever accent the site chose.
			Settings_Schema::on_accent( $brand['accent'], $brand['text'] ),
			// Primary buttons are the text colour, labelled in whichever of the
			// surface or white reads better on it. The schema already requires
			// text to read on the surface, so this pair always passes.
			Contrast::best_ink( $brand['text'], array( $brand['surface'], '#ffffff' ) )
		);

		wp_add_inline_style( Assets::HANDLE, $css );
	}
}
