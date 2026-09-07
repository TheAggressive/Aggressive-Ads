<?php
/**
 * What a publisher may sell, and what the absence of history is worth.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Supply_Forecast;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * P16's inventory-commerce contract requires the phase to declare its source
 * history, exclusions, seasonality, minimum data, confidence representation and
 * sparse fallback. `Supply_Forecast` declares them; this asserts them, because
 * a declaration in a docblock is a claim and not yet a property.
 *
 * The distinctions under test are the ones that turn a forecast into an
 * under-delivered campaign: selling to a mean rather than a floor, reading no
 * data as no inventory, letting one viral day set a commitment, and pooling a
 * Sunday with a Tuesday.
 */
final class SupplyForecastTest extends TestCase {

	/**
	 * A run of consecutive days starting from a known Monday.
	 *
	 * @param array<int, int> $counts One observation per day, in order.
	 * @param string          $start  Y-m-d of the first day.
	 * @return array<string, int>
	 */
	private function days( array $counts, string $start = '2026-01-05' ): array {
		$observed = array();
		$day      = new \DateTimeImmutable( $start . ' 00:00:00', new \DateTimeZone( 'UTC' ) );

		foreach ( $counts as $count ) {
			$observed[ $day->format( 'Y-m-d' ) ] = $count;

			$day = $day->modify( '+1 day' );
		}

		return $observed;
	}

	public function test_no_history_is_not_no_inventory(): void {
		$forecast = Supply_Forecast::for_window( array(), Supply_Forecast::days_in_window( '2026-02-01', '2026-02-07' ) );

		$this->assertNull(
			$forecast['estimate'],
			'A placement with no history has not been measured as empty, and reporting zero would refuse a booking on the strength of no data.'
		);
		$this->assertNull( $forecast['optimistic'] );
		$this->assertSame( Supply_Forecast::CONFIDENCE_NONE, $forecast['confidence'] );
		$this->assertSame( 0, $forecast['days_observed'] );
	}

	public function test_an_empty_window_forecasts_nothing(): void {
		$forecast = Supply_Forecast::for_window( $this->days( array_fill( 0, 30, 100 ) ), array() );

		$this->assertNull( $forecast['estimate'] );
		$this->assertSame( Supply_Forecast::CONFIDENCE_NONE, $forecast['confidence'] );
		$this->assertSame( 0, $forecast['days_forecast'] );
	}

	public function test_sparse_history_falls_back_to_the_worst_day_observed(): void {
		$forecast = Supply_Forecast::for_window(
			$this->days( array( 400, 900, 1200 ) ),
			Supply_Forecast::days_in_window( '2026-02-01', '2026-02-02' )
		);

		$this->assertSame(
			800,
			$forecast['estimate'],
			'Three days is no distribution; the lowest day seen is the only figure that cannot be an overstatement.'
		);
		$this->assertSame( 2400, $forecast['optimistic'] );
		$this->assertSame( Supply_Forecast::CONFIDENCE_LOW, $forecast['confidence'] );
	}

	public function test_a_week_of_history_earns_a_quantile_rather_than_the_floor(): void {
		$forecast = Supply_Forecast::for_window(
			$this->days( array( 10, 20, 30, 40, 50, 60, 70 ) ),
			Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' )
		);

		$this->assertSame( Supply_Forecast::CONFIDENCE_MEDIUM, $forecast['confidence'] );
		$this->assertSame(
			20,
			$forecast['estimate'],
			'Nearest rank over seven samples puts the 20th percentile on the second-lowest day, above the floor of 10 a sparse fallback would have used.'
		);
		$this->assertSame( 60, $forecast['optimistic'] );
	}

	public function test_the_estimate_sits_below_the_mean(): void {
		$daily    = array( 5, 10, 100, 110, 120, 130, 140, 150 );
		$forecast = Supply_Forecast::for_window(
			$this->days( $daily ),
			Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' )
		);

		$mean = array_sum( $daily ) / count( $daily );

		$this->assertLessThan(
			$mean,
			(float) $forecast['estimate'],
			'A mean is missed on about half of days, so selling to it under-delivers half of what is sold — the failure this phase exists to prevent.'
		);
	}

	public function test_one_viral_day_does_not_raise_what_may_be_sold(): void {
		$steady = array_fill( 0, 10, 100 );
		$spiked = $steady;

		$spiked[9] = 500000;

		$before = Supply_Forecast::for_window( $this->days( $steady ), Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' ) );
		$after  = Supply_Forecast::for_window( $this->days( $spiked ), Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' ) );

		$this->assertSame(
			$before['estimate'],
			$after['estimate'],
			'An outlier lifts a mean and must not move a low quantile, or one good day talks the publisher into a commitment.'
		);
		$this->assertSame(
			$before['optimistic'],
			$after['optimistic'],
			'Nor may it move the optimistic bound. That bound is a quantile too — one day in ten sits above the 80th percentile — so a screen cannot present a single spike as headroom a buyer could book against.'
		);
	}

	public function test_an_explicit_zero_is_an_observation(): void {
		$with_zeros = Supply_Forecast::for_window(
			$this->days( array( 0, 0, 100, 100, 100, 100, 100 ) ),
			Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' )
		);

		$this->assertSame(
			0,
			$with_zeros['estimate'],
			'A day the placement produced nothing is history, not a gap, and a conservative forecast has to carry it.'
		);
	}

	public function test_an_omitted_day_is_unknown_rather_than_zero(): void {
		$observed = $this->days( array_fill( 0, 7, 100 ) );

		unset( $observed['2026-01-08'] );

		$forecast = Supply_Forecast::for_window( $observed, Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' ) );

		$this->assertSame( 6, $forecast['days_observed'] );
		$this->assertSame(
			100,
			$forecast['estimate'],
			'A day with no row may be a placement that did not exist yet; only the caller can tell, so an absent day supplies nothing.'
		);
	}

	public function test_the_weekly_cycle_is_used_once_every_weekday_is_well_observed(): void {
		$counts = array();

		for ( $week = 0; $week < 4; $week++ ) {
			// Monday through Friday busy, Saturday and Sunday quiet.
			array_push( $counts, 1000, 1000, 1000, 1000, 1000, 100, 100 );
		}

		$forecast = Supply_Forecast::for_window(
			$this->days( $counts ),
			Supply_Forecast::days_in_window( '2026-02-02', '2026-02-08' )
		);

		$this->assertSame( Supply_Forecast::CONFIDENCE_HIGH, $forecast['confidence'] );
		$this->assertSame(
			5200,
			$forecast['estimate'],
			'Five weekdays at 1000 and two weekend days at 100. Pooling would have charged the weekend the weekday rate.'
		);
	}

	public function test_a_weekday_window_does_not_need_weekend_history(): void {
		$counts = array();

		for ( $week = 0; $week < 4; $week++ ) {
			array_push( $counts, 1000, 1000, 1000, 1000, 1000, 100, 100 );
		}

		// Drop every Sunday, leaving that weekday under-observed.
		foreach ( array( '2026-01-11', '2026-01-18', '2026-01-25', '2026-02-01' ) as $sunday ) {
			unset( $counts[ $sunday ] );
		}

		$observed = $this->days( $counts );

		foreach ( array( '2026-01-11', '2026-01-18', '2026-01-25', '2026-02-01' ) as $sunday ) {
			unset( $observed[ $sunday ] );
		}

		$forecast = Supply_Forecast::for_window(
			$observed,
			// Monday to Friday only.
			array( '2026-02-02', '2026-02-03', '2026-02-04', '2026-02-05', '2026-02-06' )
		);

		$this->assertSame(
			Supply_Forecast::CONFIDENCE_HIGH,
			$forecast['confidence'],
			'Requiring history for a weekday the window excludes would drop a well-observed weekday window back to a pooled estimate that mixes in the weekend it left out.'
		);
		$this->assertSame( 5000, $forecast['estimate'] );
	}

	public function test_an_under_observed_weekday_in_the_window_pools_instead(): void {
		$counts = array();

		for ( $week = 0; $week < 4; $week++ ) {
			array_push( $counts, 1000, 1000, 1000, 1000, 1000, 100, 100 );
		}

		$observed = $this->days( $counts );

		// Three Sundays left, one short of the bar.
		unset( $observed['2026-01-11'] );

		$forecast = Supply_Forecast::for_window(
			$observed,
			Supply_Forecast::days_in_window( '2026-02-02', '2026-02-08' )
		);

		$this->assertSame(
			Supply_Forecast::CONFIDENCE_MEDIUM,
			$forecast['confidence'],
			'A weekday quantile drawn from too few observations is one number dressed up as a pattern.'
		);
	}

	public function test_the_optimistic_bound_is_never_below_the_estimate(): void {
		foreach ( array( array( 7 ), array( 1, 900 ), array_fill( 0, 9, 4 ), range( 1, 40 ) ) as $counts ) {
			$forecast = Supply_Forecast::for_window(
				$this->days( $counts ),
				Supply_Forecast::days_in_window( '2026-02-02', '2026-02-08' )
			);

			$this->assertGreaterThanOrEqual( (int) $forecast['estimate'], (int) $forecast['optimistic'] );
		}
	}

	public function test_every_figure_is_a_day_that_actually_happened(): void {
		$forecast = Supply_Forecast::for_window(
			$this->days( array( 10, 10, 10, 10, 10, 10, 90 ) ),
			Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' )
		);

		$this->assertContains(
			$forecast['estimate'],
			array( 10, 90 ),
			'Nearest rank returns an observed day rather than a number between two of them, which is what lets the estimate be described as a day the publisher has already had.'
		);
	}

	/**
	 * Runs a callable with the ambient timezone temporarily set.
	 *
	 * @param string   $zone Timezone identifier.
	 * @param callable $work What to run inside it.
	 * @return mixed Whatever the callable returned.
	 */
	private function in_timezone( string $zone, callable $work ) {
		$original = date_default_timezone_get();

		try {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Proving the class does not read the ambient zone is the assertion.
			date_default_timezone_set( $zone );

			return $work();
		} finally {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restoring what the test changed.
			date_default_timezone_set( $original );
		}
	}

	/**
	 * A day the ambient timezone does not have at all.
	 *
	 * Samoa moved across the date line at the end of 2011 and 2011-12-30 never
	 * happened there. It is the sharpest available case, because a zone that
	 * merely shifts an hour still has every date — parsing a date in it lands
	 * on the same Y-m-d, so an ambient zone looks harmless right up until a
	 * publisher is in one that skipped a day. That publisher's forecast window
	 * would silently be a day shorter than the one they asked for, and their
	 * history a day thinner, with nothing reporting either.
	 */
	public function test_a_window_keeps_a_day_the_site_timezone_skipped(): void {
		$days = $this->in_timezone(
			'Pacific/Apia',
			static fn (): array => Supply_Forecast::days_in_window( '2011-12-29', '2011-12-31' )
		);

		$this->assertSame(
			array( '2011-12-29', '2011-12-30', '2011-12-31' ),
			$days,
			'The counters are stored in UTC, so a window covers UTC days whatever calendar the publisher keeps.'
		);
	}

	public function test_history_on_a_day_the_site_timezone_skipped_is_still_history(): void {
		$observed = array(
			'2011-12-28' => 10,
			'2011-12-29' => 10,
			'2011-12-30' => 10,
			'2011-12-31' => 10,
		);

		$forecast = $this->in_timezone(
			'Pacific/Apia',
			static fn (): array => Supply_Forecast::for_window( $observed, array( '2012-01-05' ) )
		);

		$this->assertSame(
			4,
			$forecast['days_observed'],
			'A day the ambient zone cannot represent is dropped from history by the round-trip check, thinning the evidence behind an estimate for a reason no reader could see.'
		);
	}

	public function test_a_window_includes_both_ends(): void {
		$days = Supply_Forecast::days_in_window( '2026-03-01', '2026-03-03' );

		$this->assertSame( array( '2026-03-01', '2026-03-02', '2026-03-03' ), $days );
	}

	public function test_a_window_crosses_a_daylight_saving_boundary_without_losing_a_day(): void {
		$original = date_default_timezone_get();

		try {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- US DST starts 2026-03-08; UTC days must be unaffected.
			date_default_timezone_set( 'America/New_York' );
			$days = Supply_Forecast::days_in_window( '2026-03-07', '2026-03-09' );
		} finally {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restoring what the test changed.
			date_default_timezone_set( $original );
		}

		$this->assertSame( array( '2026-03-07', '2026-03-08', '2026-03-09' ), $days );
	}

	public function test_a_reversed_window_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Supply_Forecast::days_in_window( '2026-03-10', '2026-03-01' );
	}

	public function test_an_unparseable_date_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Supply_Forecast::days_in_window( '2026-02-30', '2026-03-01' );
	}

	public function test_an_oversized_window_is_refused_rather_than_truncated(): void {
		$this->expectException( InvalidArgumentException::class );

		Supply_Forecast::days_in_window( '2026-01-01', '2027-06-01' );
	}

	public function test_the_longest_allowed_window_is_accepted(): void {
		$days = Supply_Forecast::days_in_window( '2026-01-01', '2027-01-01' );

		$this->assertCount( Supply_Forecast::MAX_WINDOW_DAYS, $days );
	}

	public function test_an_impossible_count_stays_visible(): void {
		$observed = $this->days( array( -50, 100, 100 ) );

		$forecast = Supply_Forecast::for_window( $observed, Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' ) );

		$this->assertSame(
			-50,
			$forecast['estimate'],
			'The counter column is unsigned, so a negative means the ledger is wrong. Clamped to zero it would read as a placement that is merely unsold, which is a thing a publisher acts on.'
		);
	}

	public function test_history_the_caller_could_not_parse_is_dropped(): void {
		$observed = $this->days( array_fill( 0, 7, 100 ) );

		$observed['not-a-day']  = 999999;
		$observed['2026-13-01'] = 999999;

		$forecast = Supply_Forecast::for_window( $observed, Supply_Forecast::days_in_window( '2026-02-01', '2026-02-01' ) );

		$this->assertSame( 7, $forecast['days_observed'] );
		$this->assertSame( 100, $forecast['estimate'] );
	}
}
