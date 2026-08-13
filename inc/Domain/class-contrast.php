<?php
/**
 * WCAG 2.2 relative luminance and contrast ratio.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure contrast math. No WordPress. Brand save and the stylesheet test
 * share this so a palette cannot pass one and fail the other.
 */
final class Contrast {

	/**
	 * WCAG 2.2 AA, normal text (and every portal label: none is “large text”).
	 */
	public const AA_NORMAL = 4.5;

	/**
	 * WCAG 2.2 AA, user-interface components and graphical objects.
	 */
	public const AA_NON_TEXT = 3.0;

	/**
	 * Contrast ratio between two six-digit hex colours.
	 *
	 * @param string $one Hex with leading #.
	 * @param string $two Hex with leading #.
	 * @return float
	 */
	public static function ratio( string $one, string $two ): float {
		$light = self::luminance( $one );
		$dark  = self::luminance( $two );

		if ( $dark > $light ) {
			list( $light, $dark ) = array( $dark, $light );
		}

		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	/**
	 * Whether the pair clears a threshold.
	 *
	 * @param string $foreground Hex with leading #.
	 * @param string $background Hex with leading #.
	 * @param float  $minimum    Required ratio.
	 * @return bool
	 */
	public static function passes( string $foreground, string $background, float $minimum = self::AA_NORMAL ): bool {
		return self::ratio( $foreground, $background ) >= $minimum;
	}

	/**
	 * Relative luminance of a six-digit hex colour, per WCAG.
	 *
	 * @param string $hex Hex with leading #.
	 * @return float
	 */
	public static function luminance( string $hex ): float {
		$channels = array(
			hexdec( substr( $hex, 1, 2 ) ) / 255,
			hexdec( substr( $hex, 3, 2 ) ) / 255,
			hexdec( substr( $hex, 5, 2 ) ) / 255,
		);

		foreach ( $channels as $index => $value ) {
			$channels[ $index ] = $value <= 0.04045
				? $value / 12.92
				: ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}
}
