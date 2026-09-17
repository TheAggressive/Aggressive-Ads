<?php
/**
 * A chart's value axis, fitted to its numbers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Round gridlines that use the height the data needs.
 *
 * **Tight, not merely round.** The first scale picked the next of 1, 2, 5 or
 * 10 times a power of ten, so a busiest day of 97 was drawn against 200 and
 * the whole line sat in the bottom half. This tries three, four and five
 * intervals of a round step and keeps the smallest top that clears the peak
 * with a little headroom — 97 gets 0–120 in steps of 30.
 */
final class Chart_Scale {

	/**
	 * Steps a person reads as round, per power of ten.
	 */
	private const NICE = array( 1, 2, 2.5, 3, 4, 5, 6, 8, 10 );

	/**
	 * The top of the axis and every gridline value, bottom first.
	 *
	 * Counts are whole, so a step is never below one: a quiet chart reads
	 * 0, 1, 2, 3 rather than 0, 0.5, 1, 1.5.
	 *
	 * @param int $peak The largest value drawn.
	 * @return array{top: float, ticks: list<float>}
	 */
	public static function fit( int $peak ): array {
		if ( $peak <= 0 ) {
			return array(
				'top'   => 4.0,
				'ticks' => array( 0.0, 1.0, 2.0, 3.0, 4.0 ),
			);
		}

		$need = $peak * 1.08;
		$best = null;

		foreach ( array( 4, 5, 3 ) as $intervals ) {
			$step = self::nice_step( $need / $intervals );
			$top  = $step * (int) ceil( $need / $step - 1e-9 );

			if ( null === $best || $top < $best['top'] - 1e-9 ) {
				$best = array(
					'top'  => $top,
					'step' => $step,
				);
			}
		}

		$ticks = array();

		for ( $value = 0.0; $value <= $best['top'] + 1e-9; $value += $best['step'] ) {
			$ticks[] = round( $value, 6 );
		}

		return array(
			'top'   => $best['top'],
			'ticks' => $ticks,
		);
	}

	/**
	 * The smallest round step at least as large as a raw one, never below one.
	 *
	 * @param float $raw Unrounded step.
	 * @return float
	 */
	private static function nice_step( float $raw ): float {
		if ( $raw <= 1 ) {
			return 1.0;
		}

		$magnitude = 10 ** floor( log10( $raw ) );

		foreach ( self::NICE as $multiple ) {
			if ( $multiple * $magnitude >= $raw - 1e-9 ) {
				return (float) ( $multiple * $magnitude );
			}
		}

		return (float) ( 10 * $magnitude );
	}
}
