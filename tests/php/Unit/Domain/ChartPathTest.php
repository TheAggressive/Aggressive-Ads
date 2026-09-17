<?php
/**
 * Smooth chart lines.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Chart_Path;
use PHPUnit\Framework\TestCase;

/**
 * The curve may be smooth; it may not invent values.
 */
final class ChartPathTest extends TestCase {

	/**
	 * Every segment ends on the next measured point.
	 *
	 * @return void
	 */
	public function test_the_curve_passes_through_every_point(): void {
		$points   = array( array( 0.0, 50.0 ), array( 10.0, 20.0 ), array( 20.0, 80.0 ), array( 30.0, 80.0 ) );
		$segments = Chart_Path::segments( $points );

		$this->assertCount( 3, $segments );

		foreach ( $segments as $index => $segment ) {
			$parts = explode( ' ', $segment );

			$this->assertSame( 'C', $parts[0] );
			$this->assertEqualsWithDelta( $points[ $index + 1 ][0], (float) $parts[5], 0.01 );
			$this->assertEqualsWithDelta( $points[ $index + 1 ][1], (float) $parts[6], 0.01 );
		}
	}

	/**
	 * **The overshoot that made a smooth line lie.** Between a peak and two
	 * zero days a free spline dips below zero. Every control point here stays
	 * inside the range of the two points its segment joins, which keeps the
	 * whole Bézier inside it.
	 *
	 * @return void
	 */
	public function test_no_segment_leaves_the_range_of_its_two_points(): void {
		$values = array( 0.0, 0.0, 100.0, 3.0, 0.0, 0.0, 60.0, 60.0, 5.0 );
		$points = array();

		foreach ( $values as $index => $value ) {
			$points[] = array( (float) $index * 10, $value );
		}

		foreach ( Chart_Path::segments( $points ) as $index => $segment ) {
			$parts = explode( ' ', $segment );
			$low   = min( $values[ $index ], $values[ $index + 1 ] );
			$high  = max( $values[ $index ], $values[ $index + 1 ] );

			foreach ( array( (float) $parts[2], (float) $parts[4] ) as $control ) {
				$this->assertGreaterThanOrEqual( $low - 0.01, $control, "Segment {$index} dips below its points." );
				$this->assertLessThanOrEqual( $high + 0.01, $control, "Segment {$index} rises above its points." );
			}
		}
	}

	/**
	 * One point is not a line, and coordinates are written the way SVG reads them.
	 *
	 * @return void
	 */
	public function test_a_single_point_draws_nothing_and_numbers_are_plain(): void {
		$this->assertSame( array(), Chart_Path::segments( array( array( 0.0, 1.0 ) ) ) );
		$this->assertSame( '12.5', Chart_Path::number( 12.5 ) );
		$this->assertSame( '3', Chart_Path::number( 3.0 ) );
		$this->assertSame( '0.33', Chart_Path::number( 1 / 3 ) );
	}
}
