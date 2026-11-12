<?php
/**
 * Local calendar release rules, including DST and missed cron.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Report_Cadence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportCadenceTest extends TestCase {

	/**
	 * Only released, complete calendar periods can be mailed.
	 *
	 * @param string $cadence Frequency.
	 * @param string $now Current UTC instant.
	 * @param string $zone Calendar timezone.
	 * @param string $start Expected first day.
	 * @param string $end Expected last day.
	 */
	#[DataProvider( 'periods' )]
	public function test_most_recent_released_period( string $cadence, string $now, string $zone, string $start, string $end ): void {
		$period = Report_Cadence::period( $cadence, strtotime( $now . ' UTC' ), $zone );
		$this->assertNotNull( $period );
		$this->assertSame( $start, $period->start );
		$this->assertSame( $end, $period->end );
		$this->assertSame( $zone, $period->timezone );
	}

	/**
	 * Calendar examples are literal dates, independent of the implementation.
	 *
	 * @return array<string, array{string, string, string, string, string}>
	 */
	public static function periods(): array {
		return array(
			'before Monday release' => array( 'weekly', '2026-11-09 08:59:59', 'UTC', '2026-10-26', '2026-11-01' ),
			'Monday release'        => array( 'weekly', '2026-11-09 09:00:00', 'UTC', '2026-11-02', '2026-11-08' ),
			'late Friday cron'      => array( 'weekly', '2026-11-13 23:00:00', 'UTC', '2026-11-02', '2026-11-08' ),
			'leap February'         => array( 'monthly', '2028-03-01 09:00:00', 'UTC', '2028-02-01', '2028-02-29' ),
			'ordinary February'     => array( 'monthly', '2027-03-01 09:00:00', 'UTC', '2027-02-01', '2027-02-28' ),
			'year boundary'         => array( 'monthly', '2027-01-01 09:00:00', 'UTC', '2026-12-01', '2026-12-31' ),
			'quarter hour zone'     => array( 'monthly', '2026-12-01 03:15:00', 'Asia/Kathmandu', '2026-11-01', '2026-11-30' ),
			'spring DST'            => array( 'weekly', '2026-03-09 13:00:00', 'America/New_York', '2026-03-02', '2026-03-08' ),
			'fall DST'              => array( 'weekly', '2026-11-02 14:00:00', 'America/New_York', '2026-10-26', '2026-11-01' ),
		);
	}

	/** Fixed-second weekly intervals would move local delivery across DST. */
	public function test_next_release_uses_calendar_arithmetic_and_is_strictly_future(): void {
		$this->assertSame( strtotime( '2026-03-09 13:00:00 UTC' ), Report_Cadence::next_due( 'weekly', strtotime( '2026-03-02 14:00:00 UTC' ), 'America/New_York' ) );
		$this->assertSame( strtotime( '2026-11-02 14:00:00 UTC' ), Report_Cadence::next_due( 'weekly', strtotime( '2026-10-26 13:00:00 UTC' ), 'America/New_York' ) );
		$this->assertSame( strtotime( '2027-03-01 09:00:00 UTC' ), Report_Cadence::next_due( 'monthly', strtotime( '2027-02-28 12:00:00 UTC' ), 'UTC' ) );
		$this->assertSame( strtotime( '2026-11-09 09:00:00 UTC' ), Report_Cadence::next_due( 'weekly', strtotime( '2026-11-09 08:59:59 UTC' ), 'UTC' ) );
		$this->assertSame( strtotime( '2026-11-16 09:00:00 UTC' ), Report_Cadence::next_due( 'weekly', strtotime( '2026-11-09 09:00:00 UTC' ), 'UTC' ) );
	}

	/** Unsupported stored/request cadences never acquire a release date. */
	public function test_off_and_unknown_cadences_have_no_period(): void {
		foreach ( array( 'off', '', 'daily', 'WEEKLY' ) as $cadence ) {
			$this->assertSame( 0, Report_Cadence::next_due( $cadence, 1700000000, 'UTC' ) );
			$this->assertNull( Report_Cadence::period( $cadence, 1700000000, 'UTC' ) );
		}
	}
}
