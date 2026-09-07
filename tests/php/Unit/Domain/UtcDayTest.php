<?php
/**
 * One reading of a day, strict enough to refuse one that never happened.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Utc_Day;
use PHPUnit\Framework\TestCase;

/**
 * Three repositories each carried `/^\d{4}-\d{2}-\d{2}$/` and called it a date
 * check, while `Supply_Forecast` carried a stricter one that actually parsed.
 * They disagreed about `2026-13-45`: the domain refused it and storage ran a
 * query that matched nothing, which looks exactly like a placement with no
 * data.
 *
 * These pin the strict reading, and the timezone independence that is the
 * whole reason this is not a one-line regex.
 */
final class UtcDayTest extends TestCase {

	/**
	 * Runs a callable with the ambient timezone temporarily set.
	 *
	 * @param string   $zone Timezone identifier.
	 * @param callable $work What to run inside it.
	 * @return mixed
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

	public function test_a_real_day_parses(): void {
		$parsed = Utc_Day::parse( '2026-04-01' );

		$this->assertNotNull( $parsed );
		$this->assertSame( '2026-04-01', $parsed->format( 'Y-m-d' ) );
		$this->assertSame( '00:00:00', $parsed->format( 'H:i:s' ) );
		$this->assertSame( 'UTC', $parsed->getTimezone()->getName() );
	}

	public function test_a_day_that_never_happened_is_refused(): void {
		$this->assertNull(
			Utc_Day::parse( '2026-02-30' ),
			'PHP rolls the thirtieth of February forward to March, so a parse that merely succeeded would be taken as proof the input was a date.'
		);
		$this->assertFalse( Utc_Day::is_day( '2026-02-30' ) );
		$this->assertFalse( Utc_Day::is_day( '2026-13-45' ) );
	}

	public function test_a_leap_day_in_a_leap_year_is_a_day(): void {
		$this->assertTrue( Utc_Day::is_day( '2028-02-29' ) );
		$this->assertFalse( Utc_Day::is_day( '2027-02-29' ) );
	}

	public function test_something_that_is_not_a_date_is_refused(): void {
		foreach ( array( '', 'today', '2026-4-1', '2026/04/01', '2026-04-01T00:00:00Z', '  2026-04-01' ) as $candidate ) {
			$this->assertFalse( Utc_Day::is_day( $candidate ), "Accepted {$candidate}, which is not the stored format." );
		}
	}

	public function test_the_ambient_timezone_cannot_change_the_answer(): void {
		$answer = $this->in_timezone(
			'Pacific/Apia',
			static fn (): bool => Utc_Day::is_day( '2011-12-30' )
		);

		$this->assertTrue(
			$answer,
			'Samoa crossed the date line and 2011-12-30 does not exist there. The counters are stored in UTC, so a publisher in that zone must not lose a day of them.'
		);
	}

	public function test_a_window_runs_forwards(): void {
		$this->assertTrue( Utc_Day::is_window( '2026-04-01', '2026-04-30' ) );
		$this->assertTrue( Utc_Day::is_window( '2026-04-01', '2026-04-01' ) );
		$this->assertFalse(
			Utc_Day::is_window( '2026-04-30', '2026-04-01' ),
			'A caller checking only that both ends are dates would accept a reversed window of two perfectly valid ones.'
		);
	}

	public function test_a_window_needs_two_real_days(): void {
		$this->assertFalse( Utc_Day::is_window( '2026-02-30', '2026-04-01' ) );
		$this->assertFalse( Utc_Day::is_window( '2026-04-01', 'later' ) );
	}
}
