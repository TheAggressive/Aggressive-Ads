<?php
/**
 * Reading a range off a request, and refusing what cannot be one.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Report_Request;
use PHPUnit\Framework\TestCase;

/**
 * Every input here arrives from a query string, so every case is reachable by
 * typing. The ones that matter are the refusals: a screen that answered a
 * malformed range with a plausible-looking report would be wrong in the way
 * nobody checks.
 */
final class ReportRequestTest extends TestCase {

	private const WINDOWS = array( 7, 30, 90 );
	private const TODAY   = '2026-09-03';

	/**
	 * Resolve with this file's fixed clock and windows.
	 *
	 * @param string $from Requested first day.
	 * @param string $to   Requested last day.
	 * @param int    $days Requested preset.
	 */
	private function resolve( string $from = '', string $to = '', int $days = 0 ): Report_Request {
		return Report_Request::resolve( $from, $to, $days, self::WINDOWS, self::TODAY, 30 );
	}

	/**
	 * Nothing asked for is the default window, and is not a refusal.
	 */
	public function test_no_input_is_the_default_window(): void {
		$request = $this->resolve();

		$this->assertSame( 30, $request->period->days );
		$this->assertFalse( $request->rejected, 'Asking for nothing was reported as a refused range.' );
		$this->assertSame( self::TODAY, $request->period->end );
	}

	/**
	 * An explicit range is honoured exactly.
	 */
	public function test_an_explicit_range_is_honoured(): void {
		$request = $this->resolve( '2026-08-01', '2026-08-31' );

		$this->assertFalse( $request->rejected );
		$this->assertSame( '2026-08-01', $request->period->start );
		$this->assertSame( '2026-08-31', $request->period->end );
		$this->assertSame( 31, $request->period->days );
	}

	/**
	 * A preset is used when no explicit range was given.
	 */
	public function test_a_preset_is_used_when_no_range_is_given(): void {
		$request = $this->resolve( '', '', 7 );

		$this->assertFalse( $request->rejected );
		$this->assertSame( 7, $request->period->days );
	}

	/**
	 * **An explicit range beats a preset**, because it says more.
	 */
	public function test_an_explicit_range_wins_over_a_preset(): void {
		$request = $this->resolve( '2026-08-01', '2026-08-07', 90 );

		$this->assertSame( 7, $request->period->days );
		$this->assertSame( '2026-08-01', $request->period->start );
	}

	/**
	 * A refused range falls back and says that it did.
	 *
	 * The fallback alone is not enough: a screen that quietly showed the last
	 * thirty days after being asked for something else has answered a question
	 * nobody put to it.
	 */
	public function test_a_refused_range_falls_back_and_is_recorded(): void {
		foreach (
			array(
				'reversed'   => array( '2026-08-31', '2026-08-01' ),
				'too long'   => array( '2026-01-01', '2026-08-31' ),
				'malformed'  => array( '31-08-2026', '2026-08-31' ),
				'impossible' => array( '2026-02-30', '2026-03-01' ),
			) as $case => $range
		) {
			$request = $this->resolve( $range[0], $range[1] );

			$this->assertTrue( $request->rejected, sprintf( 'A %s range was accepted.', $case ) );
			$this->assertSame( 30, $request->period->days, sprintf( 'A %s range did not fall back to the default.', $case ) );
		}
	}

	/**
	 * Half a range is not a range.
	 *
	 * Reading one supplied date as an open-ended window is exactly the
	 * unbounded read the period type exists to make impossible.
	 */
	public function test_half_a_range_is_refused(): void {
		$this->assertTrue( $this->resolve( '2026-08-01', '' )->rejected );
		$this->assertTrue( $this->resolve( '', '2026-08-31' )->rejected );
	}

	/**
	 * A preset that is not offered is refused rather than clamped.
	 */
	public function test_a_preset_that_is_not_offered_is_refused(): void {
		$request = $this->resolve( '', '', 45 );

		$this->assertTrue( $request->rejected, 'A window nobody offered was treated as a choice.' );
		$this->assertSame( 30, $request->period->days );
	}

	/**
	 * Nothing this class returns can exceed the bound.
	 */
	public function test_every_outcome_is_bounded(): void {
		$cases = array(
			$this->resolve(),
			$this->resolve( '2026-01-01', '2026-12-31' ),
			$this->resolve( '', '', 99_999 ),
			$this->resolve( '2026-08-01', '2026-08-31', 90 ),
		);

		foreach ( $cases as $request ) {
			$this->assertLessThanOrEqual( Report_Period::MAX_DAYS, $request->period->days );
			$this->assertGreaterThanOrEqual( 1, $request->period->days );
		}
	}
}
