<?php
/**
 * Smooth line geometry for the portal's charts.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * A curve through every point that never overshoots them.
 *
 * **Monotone, not a free spline.** A Catmull-Rom or natural spline bulges past
 * its points: between a busy day and a quiet one it dips below the quiet
 * one, and between two zero days after a peak it goes negative. On a chart
 * of counts that is the line claiming numbers that never happened. The
 * Fritsch–Carlson tangents used here keep each segment inside the range of
 * the two points it joins, so the curve is only ever a smoother way of
 * drawing what was measured.
 */
final class Chart_Path {

	/**
	 * One cubic Bézier command per interval, in SVG path syntax.
	 *
	 * Returned per interval rather than as one string so a caller can draw
	 * part of the line differently — the days still being counted, dashed —
	 * without the two halves disagreeing about the curve where they meet.
	 *
	 * @param list<array{0: float, 1: float}> $points Points as [x, y], x strictly increasing.
	 * @return list<string> `C x1 y1 x2 y2 x y` for each interval; empty below two points.
	 */
	public static function segments( array $points ): array {
		$count = count( $points );

		if ( $count < 2 ) {
			return array();
		}

		$slopes = array();

		for ( $i = 0; $i < $count - 1; $i++ ) {
			$dx       = $points[ $i + 1 ][0] - $points[ $i ][0];
			$slopes[] = $dx > 0 ? ( $points[ $i + 1 ][1] - $points[ $i ][1] ) / $dx : 0.0;
		}

		$tangents = array( $slopes[0] );

		for ( $i = 1; $i < $count - 1; $i++ ) {
			// A peak, a trough or a flat stretch: the curve is level here.
			$tangents[] = $slopes[ $i - 1 ] * $slopes[ $i ] <= 0 ? 0.0 : ( $slopes[ $i - 1 ] + $slopes[ $i ] ) / 2;
		}

		$tangents[] = $slopes[ $count - 2 ];

		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( 0.0 === $slopes[ $i ] ) {
				$tangents[ $i ]     = 0.0;
				$tangents[ $i + 1 ] = 0.0;
				continue;
			}

			$a = $tangents[ $i ] / $slopes[ $i ];
			$b = $tangents[ $i + 1 ] / $slopes[ $i ];
			$h = $a * $a + $b * $b;

			// Fritsch–Carlson: tangents too steep for the interval are scaled back.
			if ( $h > 9 ) {
				$t                  = 3 / sqrt( $h );
				$tangents[ $i ]     = $t * $a * $slopes[ $i ];
				$tangents[ $i + 1 ] = $t * $b * $slopes[ $i ];
			}
		}

		$commands = array();

		for ( $i = 0; $i < $count - 1; $i++ ) {
			[ $x0, $y0 ] = $points[ $i ];
			[ $x1, $y1 ] = $points[ $i + 1 ];
			$third       = ( $x1 - $x0 ) / 3;

			$commands[] = sprintf(
				'C %s %s %s %s %s %s',
				self::number( $x0 + $third ),
				self::number( $y0 + $tangents[ $i ] * $third ),
				self::number( $x1 - $third ),
				self::number( $y1 - $tangents[ $i + 1 ] * $third ),
				self::number( $x1 ),
				self::number( $y1 )
			);
		}

		return $commands;
	}

	/**
	 * A coordinate in the short, locale-independent form SVG expects.
	 *
	 * @param float $value Coordinate.
	 * @return string
	 */
	public static function number( float $value ): string {
		$rounded = round( $value, 2 );

		return rtrim( rtrim( number_format( $rounded, 2, '.', '' ), '0' ), '.' );
	}
}
