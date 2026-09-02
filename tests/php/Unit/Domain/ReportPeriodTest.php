<?php
/**
 * Bounded UTC ranges, comparison windows and freshness.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Report_Period;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic every report shares, tested where it costs milliseconds.
 *
 * Reporting's defects are date defects — a range off by one at a month
 * boundary, a comparison window of a different length, a period that quietly
 * covers more than it was asked for — and every one of them is cheap to catch
 * here and expensive to catch on a screen.
 */
final class ReportPeriodTest extends TestCase {

	/**
	 * A range is inclusive at both ends and yields its days oldest first.
	 */
	public function test_a_range_is_inclusive_at_both_ends(): void {
		$period = Report_Period::between( '2026-08-07', '2026-08-13' );

		$this->assertNotNull( $period );
		$this->assertSame( 7, $period->days );
		$this->assertSame(
			array( '2026-08-07', '2026-08-08', '2026-08-09', '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13' ),
			$period->keys()
		);
	}

	/**
	 * A single day is a legal period of one, not an empty one.
	 */
	public function test_one_day_is_a_period_of_one(): void {
		$period = Report_Period::between( '2026-01-01', '2026-01-01' );

		$this->assertNotNull( $period );
		$this->assertSame( 1, $period->days );
		$this->assertSame( array( '2026-01-01' ), $period->keys() );
	}

	/**
	 * "Last N days" includes the end day.
	 *
	 * The off-by-one that looks like a rounding error for a week: a 30-day
	 * window that started 30 days back would cover 31.
	 */
	public function test_a_trailing_window_includes_its_end_day(): void {
		$period = Report_Period::ending( 30, '2026-08-13' );

		$this->assertNotNull( $period );
		$this->assertSame( '2026-07-15', $period->start );
		$this->assertSame( '2026-08-13', $period->end );
		$this->assertCount( 30, $period->keys() );
	}

	/**
	 * Bad input is refused rather than clamped.
	 *
	 * Clamping would answer a question nobody asked: the report renders, looks
	 * authoritative and covers a different period than the one requested.
	 */
	public function test_bad_input_is_refused_rather_than_reinterpreted(): void {
		$this->assertNull( Report_Period::ending( 0, '2026-08-13' ), 'A zero-day range is not a range.' );
		$this->assertNull( Report_Period::ending( Report_Period::MAX_DAYS + 1, '2026-08-13' ), 'An over-long range must be refused, not truncated.' );
		$this->assertNull( Report_Period::ending( 7, '13-08-2026' ), 'A non-ISO date must not be guessed at.' );
		$this->assertNull( Report_Period::between( '2026-08-13', '2026-08-07' ), 'A reversed range is not a range.' );
		$this->assertNull( Report_Period::between( '2026-01-01', '2026-12-31' ), 'A range longer than the bound must be refused.' );
	}

	/**
	 * A date the parser would silently rewrite is refused.
	 *
	 * `DateTimeImmutable` reads `2026-02-30` as 2 March. A report that
	 * reinterprets a date is worse than one that declines it, because nothing
	 * on the screen says it happened.
	 */
	public function test_an_impossible_date_is_refused_not_rolled_forward(): void {
		$this->assertNull( Report_Period::between( '2026-02-30', '2026-03-05' ) );
		$this->assertNull( Report_Period::ending( 7, '2026-13-01' ) );
	}

	/**
	 * The comparison window is equal in length and immediately before.
	 *
	 * Equal length is the whole point: a 31-day month against a 28-day one
	 * reports a 10% gain produced entirely by counting days.
	 */
	public function test_the_comparison_window_is_equal_and_immediately_preceding(): void {
		$period   = Report_Period::between( '2026-08-07', '2026-08-13' );
		$previous = $period?->previous();

		$this->assertNotNull( $previous );
		$this->assertSame( '2026-07-31', $previous->start );
		$this->assertSame( '2026-08-06', $previous->end );
		$this->assertSame( 7, $previous->days );
		$this->assertSame( $period?->days, $previous->days );
	}

	/**
	 * Comparison arithmetic survives a month and a year boundary.
	 */
	public function test_the_comparison_window_crosses_a_year_boundary(): void {
		$period   = Report_Period::between( '2026-01-01', '2026-01-31' );
		$previous = $period?->previous();

		$this->assertNotNull( $previous );
		$this->assertSame( '2025-12-01', $previous->start );
		$this->assertSame( '2025-12-31', $previous->end );
		$this->assertSame( 31, $previous->days );
	}

	/**
	 * February is 29 days in 2028, and the arithmetic must not assume 28.
	 */
	public function test_a_leap_day_is_a_day(): void {
		$period = Report_Period::between( '2028-02-01', '2028-02-29' );

		$this->assertNotNull( $period );
		$this->assertSame( 29, $period->days );
		$this->assertContains( '2028-02-29', $period->keys() );
	}

	/**
	 * A range including today is partial, whatever the watermark says.
	 *
	 * The most cautious state that applies wins, because the failure is
	 * one-directional: a settled range wrongly called provisional costs a
	 * second look, and a partial day presented as settled looks exactly like a
	 * decline in traffic.
	 */
	public function test_a_range_including_today_is_partial(): void {
		$period = Report_Period::between( '2026-08-07', '2026-08-13' );

		$this->assertSame( Report_Period::PARTIAL, $period?->freshness( '2026-08-13', '2026-08-13' ) );
		$this->assertSame( Report_Period::PARTIAL, $period?->freshness( '2026-08-12', '2026-08-10' ) );
	}

	/**
	 * A closed range past the watermark is provisional; at or before it, settled.
	 */
	public function test_freshness_follows_the_watermark_for_closed_days(): void {
		$period = Report_Period::between( '2026-08-07', '2026-08-13' );

		$this->assertSame( Report_Period::PROVISIONAL, $period?->freshness( '2026-08-12', '2026-08-20' ) );
		$this->assertSame( Report_Period::RECONCILED, $period?->freshness( '2026-08-13', '2026-08-20' ) );
		$this->assertSame( Report_Period::RECONCILED, $period?->freshness( '2026-09-01', '2026-09-02' ) );
	}

	/**
	 * A site that has never reconciled reports provisional, not settled.
	 *
	 * The empty watermark is the state a fresh install is in, and reading it as
	 * "everything is confirmed" would be the most confident possible answer at
	 * the moment there is least reason for confidence.
	 */
	public function test_no_watermark_at_all_is_provisional(): void {
		$period = Report_Period::between( '2026-08-07', '2026-08-13' );

		$this->assertSame( Report_Period::PROVISIONAL, $period?->freshness( '', '2026-08-20' ) );
		$this->assertSame( '2026-08-07', $period?->unreconciled_from( '', '2026-08-20' ) );
	}

	/**
	 * The boundary day is named, and clamped into the range.
	 */
	public function test_the_unreconciled_boundary_is_the_day_after_the_watermark(): void {
		$period = Report_Period::between( '2026-08-07', '2026-08-13' );

		$this->assertSame( '2026-08-11', $period?->unreconciled_from( '2026-08-10', '2026-08-20' ) );
		$this->assertNull( $period?->unreconciled_from( '2026-08-13', '2026-08-20' ), 'A fully reconciled range has no boundary to name.' );

		// A watermark older than the range does not point outside it.
		$this->assertSame( '2026-08-07', $period?->unreconciled_from( '2026-01-01', '2026-08-20' ) );
	}

	/**
	 * `trailing()` clamps where `ending()` refuses, and the difference is the caller.
	 *
	 * A request parameter is refused; a constant chosen in this codebase is
	 * clamped, because returning null there only moves the same problem to a
	 * caller with less context than this one.
	 */
	public function test_trailing_clamps_where_ending_refuses(): void {
		$this->assertSame( 1, Report_Period::trailing( 0, '2026-08-13' )->days );
		$this->assertSame( Report_Period::MAX_DAYS, Report_Period::trailing( 9_999, '2026-08-13' )->days );
		$this->assertSame( 30, Report_Period::trailing( 30, '2026-08-13' )->days );
	}
}
