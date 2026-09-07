<?php
/**
 * Being beaten is the plan; being short is the failure.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Forecast_Error;
use PHPUnit\Framework\TestCase;

/**
 * The forecast is the twentieth percentile of observed days, so it is *meant*
 * to be beaten about four windows in five. That makes a plain accuracy figure
 * actively misleading: a large average miss is the model working, and the one
 * window a publisher sold against and could not fill is the thing worth
 * acting on.
 *
 * These assert that asymmetry, and the distinctions a screen gets wrong around
 * it — an unmeasured window is not an accurate one, and a mean of nothing is
 * not nought.
 */
final class ForecastErrorTest extends TestCase {

	public function test_supplying_more_than_forecast_is_the_design_working(): void {
		$measured = Forecast_Error::measure( 1000, 1400 );

		$this->assertSame( Forecast_Error::UNDER, $measured['direction'] );
		$this->assertSame(
			400,
			$measured['error'],
			'Positive means the placement beat its forecast, which is the safe miss. The opposite sign would put the alarming case in positive numbers and invite a screen to show the reassuring one in red.'
		);
		$this->assertSame( 400, $measured['absolute'] );
	}

	public function test_supplying_less_than_forecast_is_the_case_that_oversells(): void {
		$measured = Forecast_Error::measure( 1000, 600 );

		$this->assertSame( Forecast_Error::OVER, $measured['direction'] );
		$this->assertSame( -400, $measured['error'] );
		$this->assertSame( 400, $measured['absolute'] );
	}

	public function test_an_exact_forecast_is_neither(): void {
		$measured = Forecast_Error::measure( 1000, 1000 );

		$this->assertSame( Forecast_Error::EXACT, $measured['direction'] );
		$this->assertSame( 0, $measured['error'] );
	}

	public function test_an_open_window_has_not_been_judged(): void {
		$measured = Forecast_Error::measure( 1000, null );

		$this->assertNull(
			$measured['direction'],
			'A window nobody has measured has not been forecast accurately; it has not been judged at all, and a perfect score would make it the best performing placement on the screen.'
		);
		$this->assertNull( $measured['error'] );
		$this->assertNull( $measured['relative'] );
	}

	public function test_a_window_with_no_forecast_has_not_been_judged(): void {
		$this->assertNull( Forecast_Error::measure( null, 900 )['direction'] );
	}

	public function test_relative_error_is_a_share_of_what_really_happened(): void {
		$measured = Forecast_Error::measure( 800, 1000 );

		$this->assertSame(
			0.2,
			$measured['relative'],
			'Measured against the actual, which is the only denominator that answers how far off the forecast was as a share of what happened.'
		);
	}

	public function test_a_window_that_supplied_nothing_has_no_percentage(): void {
		$measured = Forecast_Error::measure( 500, 0 );

		$this->assertSame( Forecast_Error::OVER, $measured['direction'] );
		$this->assertSame( 500, $measured['absolute'] );
		$this->assertNull(
			$measured['relative'],
			'Every miss against a zero denominator is infinite, and an infinity is not a percentage anybody can act on.'
		);
	}

	public function test_oversold_windows_are_counted_rather_than_averaged_away(): void {
		$summary = Forecast_Error::summarise(
			array(
				array(
					'estimate' => 100,
					'actual'   => 900,
				),
				array(
					'estimate' => 100,
					'actual'   => 900,
				),
				array(
					'estimate' => 100,
					'actual'   => 900,
				),
				array(
					'estimate' => 100,
					'actual'   => 10,
				),
			)
		);

		$this->assertSame( 4, $summary['judged'] );
		$this->assertSame(
			1,
			$summary['oversold'],
			'Three comfortable beats and one shortfall average to a healthy-looking figure. The shortfall is the only one a publisher could not fill, and it has to survive the summary.'
		);
	}

	public function test_an_unmatured_window_is_skipped_rather_than_scored(): void {
		$summary = Forecast_Error::summarise(
			array(
				array(
					'estimate' => 100,
					'actual'   => 200,
				),
				array(
					'estimate' => 100,
					'actual'   => null,
				),
				array( 'estimate' => 100 ),
			)
		);

		$this->assertSame(
			1,
			$summary['judged'],
			'A summary over one window and a summary over three are different claims and must not look alike.'
		);
		$this->assertSame( 100.0, $summary['mean_absolute'] );
	}

	public function test_nothing_judged_is_not_perfect_accuracy(): void {
		$summary = Forecast_Error::summarise(
			array(
				array(
					'estimate' => 100,
					'actual'   => null,
				),
			)
		);

		$this->assertSame( 0, $summary['judged'] );
		$this->assertSame( 0, $summary['oversold'] );
		$this->assertNull(
			$summary['mean_absolute'],
			'A mean of nothing is not nought, and a screen rendering zero reports perfect accuracy for a placement nobody has measured.'
		);
		$this->assertNull( $summary['mean_relative'] );
	}

	public function test_a_zero_actual_still_counts_as_judged(): void {
		$summary = Forecast_Error::summarise(
			array(
				array(
					'estimate' => 500,
					'actual'   => 0,
				),
			)
		);

		$this->assertSame( 1, $summary['judged'] );
		$this->assertSame( 1, $summary['oversold'] );
		$this->assertSame( 500.0, $summary['mean_absolute'] );
		$this->assertNull(
			$summary['mean_relative'],
			'It has no percentage, but it is the worst possible outcome and must not be dropped from the count that names it.'
		);
	}

	public function test_an_empty_run_judges_nothing(): void {
		$summary = Forecast_Error::summarise( array() );

		$this->assertSame( 0, $summary['judged'] );
		$this->assertNull( $summary['mean_absolute'] );
	}
}
