<?php
/**
 * CTR arithmetic.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Reporting_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zero impressions is "unknown", not 0%.
 */
final class ReportingRulesTest extends TestCase {

	/**
	 * Clicks over a positive impression count is a ratio.
	 *
	 * @return void
	 */
	public function test_ctr_is_clicks_per_impression(): void {
		$this->assertSame( 0.0, Reporting_Rules::ctr( 10, 0 ) );
		$this->assertSame( 0.1, Reporting_Rules::ctr( 10, 1 ) );
		$this->assertSame( 1.0, Reporting_Rules::ctr( 4, 4 ) );
	}

	/**
	 * Nothing to divide by must not become a fake 0%.
	 *
	 * @return void
	 */
	public function test_ctr_is_null_without_impressions(): void {
		$this->assertNull( Reporting_Rules::ctr( 0, 0 ) );
		$this->assertNull( Reporting_Rules::ctr( 0, 3 ) );
		$this->assertNull( Reporting_Rules::ctr( -1, 1 ) );
	}

	/**
	 * Proportional change, and the three cases that have no percentage.
	 *
	 * @return void
	 */
	public function test_change_is_signed_and_refuses_what_cannot_be_divided(): void {
		$this->assertSame( 0.5, Reporting_Rules::change( 150, 100 ) );
		$this->assertSame( -0.25, Reporting_Rules::change( 75, 100 ) );
		$this->assertSame( 0.0, Reporting_Rules::change( 100, 100 ) );

		// Every change from nothing is infinite; the count already says the
		// thing worth knowing.
		$this->assertNull( Reporting_Rules::change( 40, 0 ) );
	}

	/**
	 * **An unmeasured window never becomes a decline.**
	 *
	 * A range starting before a metric shipped compares against `NULL`, and
	 * reporting that as `-100%` would invent a collapse out of a feature's
	 * release date. The rule lives in the function rather than at each call
	 * site, so a caller that coalesced its way to a zero cannot reintroduce it.
	 *
	 * @return void
	 */
	public function test_an_unmeasured_side_has_no_comparison(): void {
		$this->assertNull( Reporting_Rules::change( 40, null ), 'An unmeasured previous window was reported as growth.' );
		$this->assertNull( Reporting_Rules::change( null, 40 ), 'An unmeasured current window was reported as a decline.' );
		$this->assertNull( Reporting_Rules::change( null, null ) );
	}

	/**
	 * A rate's change is in points, not in percent of a percent.
	 *
	 * 1.0% to 1.5% is half a percentage point and a 50% rise. "CTR up 50%" is
	 * read by nearly everybody as the wrong one of those, which is why counts
	 * and rates take different functions.
	 *
	 * @return void
	 */
	public function test_a_rate_changes_by_points(): void {
		$this->assertEqualsWithDelta( 0.005, Reporting_Rules::point_change( 0.015, 0.010 ), 0.000001 );
		$this->assertEqualsWithDelta( -0.005, Reporting_Rules::point_change( 0.010, 0.015 ), 0.000001 );
		$this->assertSame( 0.0, Reporting_Rules::point_change( 0.02, 0.02 ) );
		$this->assertNull( Reporting_Rules::point_change( 0.02, null ) );
		$this->assertNull( Reporting_Rules::point_change( null, 0.02 ) );
	}

	/**
	 * A zero max is a flat line. A lone impression still occupies one pixel.
	 *
	 * @return void
	 */
	public function test_bar_height_is_flat_when_empty_and_visible_when_not(): void {
		$this->assertSame( 0, Reporting_Rules::bar_height( 0, 0 ) );
		$this->assertSame( 0, Reporting_Rules::bar_height( 0, 10 ) );
		$this->assertSame( 1, Reporting_Rules::bar_height( 1, 100 ) );
		$this->assertSame( 100, Reporting_Rules::bar_height( 50, 50 ) );
		$this->assertSame( 50, Reporting_Rules::bar_height( 5, 10 ) );
	}

	/**
	 * Viewability rates, and the two different absences.
	 *
	 * @return array<string, array{int, int|null, float|null}>
	 */
	public static function viewability_cases(): array {
		return array(
			'none of them seen'           => array( 100, 0, 0.0 ),
			'half seen'                   => array( 100, 50, 0.5 ),
			'all seen'                    => array( 100, 100, 1.0 ),
			'unmeasured'                  => array( 100, null, null ),
			'nothing delivered'           => array( 0, 0, null ),
			'nothing, and unmeasured'     => array( 0, null, null ),

			/*
			 * More views than impressions should not happen — a view records
			 * the delivery it implies — but a clamp costs nothing and beats
			 * reporting 140%, which reads as a bug in the numbers rather than
			 * in whatever produced them.
			 */
			'more views than impressions' => array( 10, 14, 1.0 ),
		);
	}

	#[DataProvider( 'viewability_cases' )]
	public function test_viewability_rate( int $impressions, ?int $viewables, ?float $expected ): void {
		$this->assertSame( $expected, Reporting_Rules::viewability( $impressions, $viewables ) );
	}

	/**
	 * Zero views is a measurement; unmeasured is not.
	 *
	 * Stated on its own because it is the distinction the nullable column, the
	 * migration and the reconcile guard all exist to carry, and every one of
	 * them is undone if this collapses the two.
	 */
	public function test_no_views_is_not_the_same_as_no_measurement(): void {
		$this->assertSame(
			0.0,
			Reporting_Rules::viewability( 500, 0 ),
			'A measured day with no views must report zero, not nothing.'
		);
		$this->assertNull(
			Reporting_Rules::viewability( 500, null ),
			'An unmeasured day must report nothing, not zero.'
		);
	}
}
