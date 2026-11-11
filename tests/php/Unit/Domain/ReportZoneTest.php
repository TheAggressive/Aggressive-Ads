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

	/** Test helper: enumerate the UTC intervals described by production's bounds. */
	private function buckets_in( string $from, string $to, string $timezone ): array {
		$bounds = Report_Zone::utc_bounds( $from, $to, $timezone );
		$this->assertNotNull( $bounds );
		$out = array();
		for ( $at = $bounds['start']; $at < $bounds['end']; $at = $at->modify( '+15 minutes' ) ) {
			$out[] = $at->format( 'Y-m-d H:i:s' );
		}
		return $out;
	}

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
		$this->assertCount( 96, $this->buckets_in( '2026-08-13', '2026-08-13', 'America/Los_Angeles' ) );
	}

	/**
	 * 2026-03-08 is the US spring-forward: 23 hours, 02:00 local does not exist.
	 */
	public function test_a_spring_forward_day_has_twenty_three_hours(): void {
		$hours = $this->buckets_in( '2026-03-08', '2026-03-08', 'America/Los_Angeles' );

		$this->assertCount( 92, $hours );
		$this->assertSame( '2026-03-08 08:00:00', $hours[0] );
		$this->assertSame( '2026-03-09 06:45:00', $hours[91] );
	}

	/**
	 * 2026-11-01 is the US fall-back: 25 hours, 01:00 local happens twice.
	 */
	public function test_a_fall_back_day_has_twenty_five_hours(): void {
		$hours = $this->buckets_in( '2026-11-01', '2026-11-01', 'America/Los_Angeles' );

		$this->assertCount( 100, $hours );
		$this->assertSame( '2026-11-01 07:00:00', $hours[0] );
		$this->assertSame( '2026-11-02 07:45:00', $hours[99] );
	}

	/**
	 * Kolkata is UTC+05:30. Local midnight is the previous UTC day at 18:30.
	 *
	 * The quarter-hour starting at 18:30 UTC is exactly local midnight;
	 * no part of the preceding bucket is counted on the new local day.
	 */
	public function test_kolkata_days_start_on_the_half_hour(): void {
		$bounds = Report_Zone::utc_bounds( '2026-08-13', '2026-08-13', 'Asia/Kolkata' );

		$this->assertNotNull( $bounds );
		$this->assertSame( '2026-08-12 18:30:00', $bounds['start']->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-08-13 18:30:00', $bounds['end']->format( 'Y-m-d H:i:s' ) );

		$hours = $this->buckets_in( '2026-08-13', '2026-08-13', 'Asia/Kolkata' );

		$this->assertCount( 96, $hours );
		$this->assertSame( '2026-08-12 18:30:00', $hours[0] );
		$this->assertSame( '2026-08-13 18:15:00', $hours[95] );
		$this->assertSame( '2026-08-12', Report_Zone::local_day( '2026-08-12 18:00:00', 'Asia/Kolkata' ) );
		$this->assertSame( '2026-08-13', Report_Zone::local_day( '2026-08-12 19:00:00', 'Asia/Kolkata' ) );
	}

	/** A fractional-hour day needs a fractional-hour reporting grain. */
	public function test_a_nepal_day_starts_at_the_quarter_hour(): void {
		$buckets = $this->buckets_in( '2026-08-13', '2026-08-13', 'Asia/Kathmandu' );
		$this->assertCount( 96, $buckets );
		$this->assertSame( '2026-08-12 18:15:00', $buckets[0] );
		$this->assertSame( '2026-08-13 18:00:00', $buckets[95] );
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
