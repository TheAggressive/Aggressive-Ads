<?php
/**
 * Reads authored portal stylesheets for unit assertions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

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
	 *
	 * @throws \RuntimeException When a stylesheet cannot be read.
	 */
	public static function contents(): string {
		$root  = AGGR_PLUGIN_DIR . 'src/styles/';
		$entry = $root . 'portal.css';
		$css   = file_get_contents( $entry );

		if ( false === $css ) {
			throw new \RuntimeException( 'Unreadable stylesheet: ' . $entry );
		}

		/*
		 * The partial list is read out of the entry's own `@import` lines
		 * rather than repeated here.
		 *
		 * It used to be a hand-maintained array, which is the shape this
		 * codebase keeps catching: a partial added to `portal.css` and not to
		 * that array would be styled in the browser and invisible to every
		 * design-system assertion, so a test asserting a class exists would
		 * quietly stop reading the file that defines it. Splitting a partial in
		 * two is enough to trigger it, which is how it was found.
		 *
		 * A `@import` naming a file that is not there throws rather than being
		 * skipped: the entry is the manifest, so it being wrong is a defect and
		 * not a reason to read less.
		 */
		$matched = preg_match_all( "/@import\s+'\.\/([^']+)'/", $css, $matches );

		if ( 1 > (int) $matched ) {
			throw new \RuntimeException( 'No @import found in ' . $entry . ', so no partial would be read.' );
		}

		$chunks = array( $css );

		foreach ( $matches[1] as $relative ) {
			$path    = $root . $relative;
			$partial = file_get_contents( $path );

			if ( false === $partial ) {
				throw new \RuntimeException( 'Unreadable stylesheet: ' . $path );
			}

			$chunks[] = $partial;
		}

		return implode( "\n", $chunks );
	}
}
