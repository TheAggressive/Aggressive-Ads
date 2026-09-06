<?php
/**
 * A denominator, and what it means when there is not one.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\Fill_Figures;
use Aggressive\Ads\Domain\No_Fill_Reason;
use PHPUnit\Framework\TestCase;

/**
 * This arithmetic used to live inside `Admin\Report_Data`, where reaching it
 * meant a database and a bootstrap. It was extracted when the utilisation view
 * needed the same sums per placement — two implementations of a fill rate being
 * two chances to disagree about what a denominator is.
 *
 * The distinctions under test are the ones a screen gets wrong: no data is not
 * zero, and a discrepancy is reported rather than normalised away.
 */
final class FillFiguresTest extends TestCase {

	public function test_a_rate_is_fills_over_requests(): void {
		$figures = Fill_Figures::from_totals(
			array(
				Decision_Outcome::REQUEST     => 200,
				Decision_Outcome::FILL        => 50,
				No_Fill_Reason::NO_CANDIDATES => 150,
			)
		);

		$this->assertSame( 200, $figures['requests'] );
		$this->assertSame( 50, $figures['fills'] );
		$this->assertSame( 0.25, $figures['fill_rate'] );
		$this->assertSame( 0, $figures['unaccounted'] );
	}

	/**
	 * **Nothing asked for is null, not zero.**
	 *
	 * A placement nobody requested did not fail to fill. Rendering 0% for it
	 * tells a publisher they have a problem where they have no data, and it is
	 * the single most likely thing for a dashboard to get wrong.
	 */
	public function test_no_requests_gives_no_rate_rather_than_zero(): void {
		$figures = Fill_Figures::from_totals( array() );

		$this->assertSame( 0, $figures['requests'] );
		$this->assertNull( $figures['fill_rate'] );
		$this->assertNotSame( 0.0, $figures['fill_rate'] );
	}

	/** A measured zero is a real zero, and must not become null. */
	public function test_requests_with_no_fills_is_a_measured_zero(): void {
		$figures = Fill_Figures::from_totals(
			array(
				Decision_Outcome::REQUEST     => 40,
				No_Fill_Reason::NO_CANDIDATES => 40,
			)
		);

		$this->assertSame( 0.0, $figures['fill_rate'] );
		$this->assertNotNull( $figures['fill_rate'] );
	}

	/**
	 * A discrepancy is reported, not normalised.
	 *
	 * P13's invariant — requests equal fills plus every no-fill reason — is a
	 * property of the engine rather than of the table, so a gap here is a
	 * defect worth surfacing.
	 */
	public function test_an_unexplained_remainder_is_reported(): void {
		$figures = Fill_Figures::from_totals(
			array(
				Decision_Outcome::REQUEST     => 100,
				Decision_Outcome::FILL        => 60,
				No_Fill_Reason::NO_CANDIDATES => 30,
			)
		);

		$this->assertSame( 10, $figures['unaccounted'] );
	}

	/** The remainder never goes negative, which would read as a phantom surplus. */
	public function test_the_remainder_never_goes_negative(): void {
		$figures = Fill_Figures::from_totals(
			array(
				Decision_Outcome::REQUEST     => 10,
				Decision_Outcome::FILL        => 8,
				No_Fill_Reason::NO_CANDIDATES => 40,
			)
		);

		$this->assertSame( 0, $figures['unaccounted'] );
	}

	/**
	 * **A rate above 1 is shown, not capped.**
	 *
	 * Fills cannot exceed requests: the engine records the request first, on
	 * the one path that records either. So this state means the ledger is
	 * wrong, and capping it at 100% would turn a defect worth finding into a
	 * number that looks healthy.
	 */
	public function test_an_impossible_rate_is_not_clamped(): void {
		$figures = Fill_Figures::from_totals(
			array(
				Decision_Outcome::REQUEST => 10,
				Decision_Outcome::FILL    => 25,
			)
		);

		$this->assertSame( 2.5, $figures['fill_rate'] );
	}

	/** Request and fill are never listed as no-fill reasons. */
	public function test_lifecycle_outcomes_are_not_reasons(): void {
		$figures = Fill_Figures::from_totals(
			array(
				Decision_Outcome::REQUEST     => 10,
				Decision_Outcome::FILL        => 4,
				No_Fill_Reason::NO_CANDIDATES => 6,
			)
		);

		$codes = array_column( $figures['reasons'], 'code' );

		$this->assertSame( array( No_Fill_Reason::NO_CANDIDATES ), $codes );
		$this->assertNotContains( Decision_Outcome::REQUEST, $codes );
		$this->assertNotContains( Decision_Outcome::FILL, $codes );
	}

	/** A reason's share uses the same denominator the rate does. */
	public function test_a_reason_share_is_against_requests(): void {
		$figures = Fill_Figures::from_totals(
			array(
				Decision_Outcome::REQUEST        => 200,
				Decision_Outcome::FILL           => 50,
				No_Fill_Reason::NO_CANDIDATES    => 100,
				No_Fill_Reason::SIZE_UNAVAILABLE => 50,
			)
		);

		$shares = array_column( $figures['reasons'], 'share', 'code' );

		$this->assertSame( 0.5, $shares[ No_Fill_Reason::NO_CANDIDATES ] );
		$this->assertSame( 0.25, $shares[ No_Fill_Reason::SIZE_UNAVAILABLE ] );
	}

	/** With no denominator a share is null too, for the same reason a rate is. */
	public function test_a_reason_share_is_null_without_requests(): void {
		$figures = Fill_Figures::from_totals(
			array( No_Fill_Reason::NO_CANDIDATES => 5 )
		);

		$this->assertNull( $figures['reasons'][0]['share'] );
	}

	/** Numeric strings out of the database are counted as numbers. */
	public function test_numeric_strings_are_counted(): void {
		$figures = Fill_Figures::from_totals(
			array(
				Decision_Outcome::REQUEST => '80',
				Decision_Outcome::FILL    => '20',
			)
		);

		$this->assertSame( 80, $figures['requests'] );
		$this->assertSame( 0.25, $figures['fill_rate'] );
	}
}
