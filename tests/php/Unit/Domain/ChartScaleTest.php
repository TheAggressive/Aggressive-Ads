<?php
/**
 * Fitting a chart's value axis.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Chart_Scale;
use PHPUnit\Framework\TestCase;

/**
 * The axis clears the peak, stays round, and does not waste the chart.
 */
final class ChartScaleTest extends TestCase {

	/**
	 * **The case that was drawn against 200.** A busiest day of 97 gets a
	 * scale of 120 in steps of 30.
	 *
	 * @return void
	 */
	public function test_a_peak_of_ninety_seven_uses_the_height(): void {
		$scale = Chart_Scale::fit( 97 );

		$this->assertSame( 120.0, $scale['top'] );
		$this->assertSame( array( 0.0, 30.0, 60.0, 90.0, 120.0 ), $scale['ticks'] );
	}

	/**
	 * Across magnitudes: always above the peak, never more than about half
	 * again, three to five intervals, and counts never split below one.
	 *
	 * @return void
	 */
	public function test_every_peak_gets_a_tight_round_scale(): void {
		foreach ( array( 1, 2, 7, 13, 48, 97, 150, 999, 1234, 17318, 250000 ) as $peak ) {
			$scale     = Chart_Scale::fit( $peak );
			$intervals = count( $scale['ticks'] ) - 1;

			$this->assertGreaterThan( $peak, $scale['top'], "{$peak} touches the top line." );
			$this->assertLessThanOrEqual( max( 3, $peak * 1.6 ), $scale['top'], "{$peak} wastes the chart on {$scale['top']}." );
			$this->assertGreaterThanOrEqual( 2, $intervals, "{$peak} has too few gridlines." );
			$this->assertLessThanOrEqual( 5, $intervals, "{$peak} has too many gridlines." );
			$this->assertSame( 0.0, $scale['ticks'][0] );
			$this->assertEqualsWithDelta( $scale['top'], end( $scale['ticks'] ), 1e-6 );
			$this->assertGreaterThanOrEqual( 1.0, $scale['ticks'][1], "{$peak} splits a count below one." );
		}
	}

	/**
	 * Nothing delivered still draws a readable, empty grid.
	 *
	 * @return void
	 */
	public function test_an_empty_chart_still_has_a_grid(): void {
		$scale = Chart_Scale::fit( 0 );

		$this->assertSame( 4.0, $scale['top'] );
		$this->assertCount( 5, $scale['ticks'] );
	}
}
