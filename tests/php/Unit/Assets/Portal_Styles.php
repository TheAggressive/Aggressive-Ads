<?php
/**
 * Reads authored portal stylesheets for unit assertions.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Assets;

/**
 * Concatenates the portal entry and its underscore partials.
 *
 * Design-system tests assert against authored CSS under src/styles/, not the
 * compiled dist/ bundle — unit tests must not require `pnpm build`.
 */
final class Portal_Styles {

	/**
	 * Full portal stylesheet source (entry + @imported partials).
	 *
	 * @return string
	 */
	public static function contents(): string {
		$root = LAAO_ADS_PLUGIN_DIR . 'src/styles/';
		$parts = array(
			$root . 'portal.css',
			$root . 'base/_tokens.css',
			$root . 'layout/_chrome.css',
			$root . 'components/_surfaces.css',
			$root . 'components/_overlay.css',
		);

		$chunks = array();

		foreach ( $parts as $path ) {
			$css = file_get_contents( $path );
			if ( false === $css ) {
				throw new \RuntimeException( 'Unreadable stylesheet: ' . $path );
			}
			$chunks[] = $css;
		}

		return implode( "\n", $chunks );
	}
}
