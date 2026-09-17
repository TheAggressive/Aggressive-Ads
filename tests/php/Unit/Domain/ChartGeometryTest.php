<?php
/**
 * The shapes the daily chart draws.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Chart_Geometry;
use PHPUnit\Framework\TestCase;

/**
 * Moved out of the chart template, where none of this could be tested.
 */
final class ChartGeometryTest extends TestCase {

	/**
	 * The solid line ends where the dashed one begins, so the two halves meet.
	 *
	 * @return void
	 */
	public function test_the_counting_days_continue_the_line_dashed(): void {
		$chart = Chart_Geometry::build( array( 4, 8, 6, 2, 1 ), array( 0, 0, 0, 0, 0 ), 3 );

		// Peak 8 needs 8.64; three steps of 3 is the tightest round top.
		$this->assertSame( 9.0, $chart['top'] );
		$this->assertNotSame( '', $chart['line'] );
		$this->assertNotSame( '', $chart['dashed'] );
		// Five days are four segments: two solid up to the last counted day, two dashed after.
		$this->assertSame( 2, substr_count( $chart['dashed'], 'C ' ), 'The dashed half starts at the last counted day.' );
		$this->assertSame( 2, substr_count( $chart['line'], 'C ' ) );
		$this->assertStringStartsWith( 'M 500 33.33', $chart['dashed'] );
		$this->assertSame( '', $chart['previous'], 'An empty previous period drew a line of zeros.' );
		$this->assertFalse( $chart['has_previous'] );
	}

	/**
	 * Nothing still being counted is all solid; everything being counted is all dashed.
	 *
	 * @return void
	 */
	public function test_the_split_at_either_end(): void {
		$final = Chart_Geometry::build( array( 1, 2, 3 ), array( 3, 2, 1 ), 3 );

		$this->assertSame( '', $final['dashed'] );
		$this->assertNotSame( '', $final['line'] );
		$this->assertNotSame( '', $final['previous'] );

		$open = Chart_Geometry::build( array( 1, 2, 3 ), array(), 0 );

		$this->assertSame( '', $open['line'] );
		$this->assertSame( 2, substr_count( $open['dashed'], 'C ' ) );
	}

	/**
	 * **Both lines ending at zero put both labels in one place.** The lower
	 * one could not step down past the chart's edge and was clamped back onto
	 * the other; now the higher steps up, and "Now" keeps its dot.
	 *
	 * @return void
	 */
	public function test_end_labels_part_even_at_the_bottom(): void {
		[ $now, $previous ] = Chart_Geometry::labels( 100.0, 100.0, true );

		$this->assertSame( 100.0, $now, 'Now left its own dot.' );
		$this->assertSame( 100.0 - Chart_Geometry::LABEL_GAP, $previous );

		[ $now, $previous ] = Chart_Geometry::labels( 30.0, 36.0, true );

		$this->assertSame( 30.0, $now );
		$this->assertSame( 44.0, $previous );

		$this->assertSame( array( 50.0, 52.0 ), Chart_Geometry::labels( 50.0, 52.0, false ), 'Labels moved for a line that is not drawn.' );
	}

	/**
	 * No days draws nothing and does not divide by zero.
	 *
	 * @return void
	 */
	public function test_no_days_draws_nothing(): void {
		$chart = Chart_Geometry::build( array(), array(), 0 );

		$this->assertSame( '', $chart['area'] );
		$this->assertSame( '', $chart['line'] );
	}
}
