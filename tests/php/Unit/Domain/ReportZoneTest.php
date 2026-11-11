<?php
/**
 * Local-day hour bounds, including DST and a half-hour offset.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Report_Zone;
use PHPUnit\Framework\TestCase;

/**
 * The cases that made hourly rollups necessary, tested where they cost
 * milliseconds. A local Tuesday in Los Angeles is not a UTC Tuesday, a
 * spring-forward day is not 24 hours, and `Asia/Kolkata` does not start on
 * a clock hour.
 */
final class ReportZoneTest extends TestCase {

	/**
	 * An invalid timezone name is UTC, not a throw.
	 */
	public function test_an_invalid_timezone_becomes_utc(): void {
		$this->assertSame( Report_Zone::UTC, Report_Zone::normalize( 'Not/A_Zone' ) );
		$this->assertSame( Report_Zone::UTC, Report_Zone::normalize( '' ) );
		$this->assertSame( Report_Zone::UTC, Report_Zone::normalize( '+00:00' ) );
		$this->assertSame( 'America/Los_Angeles', Report_Zone::normalize( 'America/Los_Angeles' ) );
	}

	/**
	 * A Pacific day starts at 07:00 UTC in August, and has 24 hours.
	 */
	public function test_a_pacific_summer_day_starts_at_seven_utc(): void {
		$bounds = Report_Zone::utc_bounds( '2026-08-13', '2026-08-13', 'America/Los_Angeles' );

		$this->assertNotNull( $bounds );
		$this->assertSame( '2026-08-13 07:00:00', $bounds['start']->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-08-14 07:00:00', $bounds['end']->format( 'Y-m-d H:i:s' ) );
		$this->assertCount( 24, Report_Zone::hours_in( '2026-08-13', '2026-08-13', 'America/Los_Angeles' ) );
	}

	/**
	 * 2026-03-08 is the US spring-forward: 23 hours, 02:00 local does not exist.
	 */
	public function test_a_spring_forward_day_has_twenty_three_hours(): void {
		$hours = Report_Zone::hours_in( '2026-03-08', '2026-03-08', 'America/Los_Angeles' );

		$this->assertCount( 23, $hours );
		$this->assertSame( '2026-03-08 08:00:00', $hours[0] );
		$this->assertSame( '2026-03-09 06:00:00', $hours[22] );
	}

	/**
	 * 2026-11-01 is the US fall-back: 25 hours, 01:00 local happens twice.
	 */
	public function test_a_fall_back_day_has_twenty_five_hours(): void {
		$hours = Report_Zone::hours_in( '2026-11-01', '2026-11-01', 'America/Los_Angeles' );

		$this->assertCount( 25, $hours );
		$this->assertSame( '2026-11-01 07:00:00', $hours[0] );
		$this->assertSame( '2026-11-02 07:00:00', $hours[24] );
	}

	/**
	 * Kolkata is UTC+05:30. Local midnight is the previous UTC day at 18:30.
	 *
	 * The hour that straddles midnight is assigned to the day that contains
	 * its start: 18:00 UTC on the 12th is still the 12th locally (23:30),
	 * 19:00 UTC is the 13th (00:30).
	 */
	public function test_kolkata_days_start_on_the_half_hour(): void {
		$bounds = Report_Zone::utc_bounds( '2026-08-13', '2026-08-13', 'Asia/Kolkata' );

		$this->assertNotNull( $bounds );
		$this->assertSame( '2026-08-12 18:30:00', $bounds['start']->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-08-13 18:30:00', $bounds['end']->format( 'Y-m-d H:i:s' ) );

		$hours = Report_Zone::hours_in( '2026-08-13', '2026-08-13', 'Asia/Kolkata' );

		$this->assertCount( 24, $hours );
		$this->assertSame( '2026-08-12 19:00:00', $hours[0] );
		$this->assertSame( '2026-08-13 18:00:00', $hours[23] );
		$this->assertSame( '2026-08-12', Report_Zone::local_day( '2026-08-12 18:00:00', 'Asia/Kolkata' ) );
		$this->assertSame( '2026-08-13', Report_Zone::local_day( '2026-08-12 19:00:00', 'Asia/Kolkata' ) );
	}

	/**
	 * An hour before Pacific midnight belongs to the previous local day.
	 */
	public function test_an_hour_is_assigned_to_the_local_day_that_contains_its_start(): void {
		$this->assertSame( '2026-08-12', Report_Zone::local_day( '2026-08-13 06:00:00', 'America/Los_Angeles' ) );
		$this->assertSame( '2026-08-13', Report_Zone::local_day( '2026-08-13 07:00:00', 'America/Los_Angeles' ) );
		$this->assertSame( '2026-08-13', Report_Zone::local_day( '2026-08-13 06:00:00', Report_Zone::UTC ) );
	}
}
