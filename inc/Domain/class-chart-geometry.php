<?php
/**
 * The shapes a daily line chart draws, from its numbers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Everything the chart template used to compute inline: the fitted scale, the
 * SVG paths for the area, the solid and dashed halves of this period's line
 * and the previous period's line, and where the two end labels sit.
 *
 * Pure, in a 1000 × 100 box the SVG stretches to the chart's width. Each day
 * sits in the middle of its own column, which is where its hover target and
 * date label are, so the line, the dots and the dates agree.
 */
final class Chart_Geometry {

	/**
	 * How far apart, in percent of the chart's height, two end labels must be.
	 */
	public const LABEL_GAP = 14;

	/**
	 * The chart for two aligned series.
	 *
	 * @param array<int, int> $current  This period, one value per day.
	 * @param array<int, int> $previous The previous equal period, day for day.
	 * @param int             $split    Index of the first day still being counted, or the day count when none is.
	 * @return array{top: float, ticks: list<float>, has_previous: bool, area: string, line: string, dashed: string, previous: string, end_now: float, end_previous: float}
	 */
	public static function build( array $current, array $previous, int $split ): array {
		$days     = count( $current );
		$peak     = max( array( 0, ...$current, ...$previous ) );
		$scale    = Chart_Scale::fit( $peak );
		$top      = $scale['top'];
		$has_prev = max( array( 0, ...$previous ) ) > 0;
		$split    = max( 0, min( $days, $split ) );

		if ( 0 === $days ) {
			return array(
				'top'          => $top,
				'ticks'        => array_reverse( $scale['ticks'] ),
				'has_previous' => false,
				'area'         => '',
				'line'         => '',
				'dashed'       => '',
				'previous'     => '',
				'end_now'      => 100.0,
				'end_previous' => 100.0,
			);
		}

		$now    = self::points( $current, $days, $top );
		$before = self::points( array_pad( $previous, $days, 0 ), $days, $top );
		$curve  = Chart_Path::segments( $now );
		$last   = $now[ $days - 1 ];
		$join   = max( 0, $split - 1 );

		[ $end_now, $end_previous ] = self::labels(
			self::height( $current[ $days - 1 ], $top ),
			self::height( (int) ( $previous[ $days - 1 ] ?? 0 ), $top ),
			$has_prev
		);

		return array(
			'top'          => $top,
			'ticks'        => array_reverse( $scale['ticks'] ),
			'has_previous' => $has_prev,
			'area'         => self::move( $now[0] ) . ' ' . implode( ' ', $curve ) . ' L ' . Chart_Path::number( $last[0] ) . ' 100 L ' . Chart_Path::number( $now[0][0] ) . ' 100 Z',
			'line'         => $split > 1 ? self::move( $now[0] ) . ' ' . implode( ' ', array_slice( $curve, 0, $join ) ) : '',
			'dashed'       => $split < $days && $days > 1 ? self::move( $now[ $join ] ) . ' ' . implode( ' ', array_slice( $curve, $join ) ) : '',
			'previous'     => $has_prev ? self::move( $before[0] ) . ' ' . implode( ' ', Chart_Path::segments( $before ) ) : '',
			'end_now'      => $end_now,
			'end_previous' => $end_previous,
		);
	}

	/**
	 * A value's distance from the top of the chart, in percent.
	 *
	 * @param float $value A value on the scale.
	 * @param float $top   The scale's top.
	 * @return float
	 */
	public static function height( float $value, float $top ): float {
		return $top > 0 ? 100 - 100 * $value / $top : 100.0;
	}

	/**
	 * Where the two end labels sit, parted when they would overlap.
	 *
	 * The higher label keeps its place and the lower one steps down — unless
	 * that would leave the chart, in which case the higher one steps up. On a
	 * tie "Now" is the lower, so it stays beside this period's dot.
	 *
	 * @param float $now      This period's last value, as a height.
	 * @param float $previous The previous period's last value, as a height.
	 * @param bool  $both     Whether the previous line is drawn at all.
	 * @return array{0: float, 1: float}
	 */
	public static function labels( float $now, float $previous, bool $both ): array {
		if ( ! $both || abs( $now - $previous ) >= self::LABEL_GAP ) {
			return array( $now, $previous );
		}

		$low  = min( 100.0, min( $now, $previous ) + self::LABEL_GAP );
		$high = $low - self::LABEL_GAP;

		return $now < $previous ? array( $high, $low ) : array( $low, $high );
	}

	/**
	 * One series as points in the chart's box.
	 *
	 * @param array<int, int> $values One value per day.
	 * @param int             $days   Days drawn.
	 * @param float           $top    The scale's top.
	 * @return list<array{0: float, 1: float}>
	 */
	private static function points( array $values, int $days, float $top ): array {
		$points = array();

		foreach ( array_values( $values ) as $index => $value ) {
			$points[] = array( ( $index + 0.5 ) * 1000 / $days, self::height( (float) $value, $top ) );
		}

		return $points;
	}

	/**
	 * An SVG move to a point.
	 *
	 * @param array{0: float, 1: float} $point The point.
	 * @return string
	 */
	private static function move( array $point ): string {
		return 'M ' . Chart_Path::number( $point[0] ) . ' ' . Chart_Path::number( $point[1] );
	}
}
