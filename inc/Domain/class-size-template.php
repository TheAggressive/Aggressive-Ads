<?php
/**
 * A blank artboard at a placement's exact size.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * So whoever makes an ad starts from the dimensions the upload will be checked
 * against rather than from a number copied out of a sentence. Built from a size
 * the placement already validated, as SVG text: nothing to host, nothing to
 * fetch. Shared by the upload form and the help page so both hand out one file.
 */
final class Size_Template {

	/**
	 * The template for a `{width}x{height}` size, or '' when it is not one.
	 *
	 * @param string $size Placement size, e.g. `728x90`.
	 * @return string
	 */
	public static function svg( string $size ): string {
		$parts = Campaign_Rules::parse_size( $size );

		if ( null === $parts ) {
			return '';
		}

		[ $width, $height ] = $parts;

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d"><rect width="%1$d" height="%2$d" fill="#f2f2f2"/><rect x="1" y="1" width="%3$d" height="%4$d" fill="none" stroke="#8a8a8a" stroke-width="2" stroke-dasharray="8 6"/><text x="50%%" y="50%%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="%5$d" fill="#555555">%1$d x %2$d</text></svg>',
			$width,
			$height,
			max( 0, $width - 2 ),
			max( 0, $height - 2 ),
			max( 10, min( 32, intdiv( $height, 4 ) ) )
		);
	}
}
