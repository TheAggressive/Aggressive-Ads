<?php
/**
 * Org-scoped reports group hourly rows onto the site's local day.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Repository\Hourly_Rollup_Repository;
use Aggressive\Ads\Repository\Rollup_Report_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

/**
 * The cases #303 named: a Pacific day is not a UTC day, Kolkata is not on
 * the hour, and another organization's hours do not appear in the sum.
 */
final class LocalDayReportTest extends WP_UnitTestCase {

	/**
	 * Hourly writes under test.
	 *
	 * @var Hourly_Rollup_Repository
	 */
	private Hourly_Rollup_Repository $hourly;

	/**
	 * Org-scoped reads under test.
	 *
	 * @var Rollup_Report_Repository
	 */
	private Rollup_Report_Repository $reports;

	public function set_up(): void {
		parent::set_up();

		( new Rollup_Repository() )->install_table();

		$this->hourly  = new Hourly_Rollup_Repository();
		$this->reports = new Rollup_Report_Repository();
	}

	/**
	 * An impression at 06:00 UTC on the 13th is still the 12th in Los Angeles;
	 * one at 08:00 UTC is the 13th. A UTC report for the 13th sees both.
	 */
	public function test_pacific_hours_are_grouped_onto_local_days(): void {
		$this->write( '2026-08-13 06:00:00', 11, 110, 1100 );
		$this->write( '2026-08-13 08:00:00', 11, 110, 1100 );
		$this->write( '2026-08-13 08:00:00', 11, 999, 9990 );

		$local_13 = Report_Period::between( '2026-08-13', '2026-08-13', 'America/Los_Angeles' );
		$local_12 = Report_Period::between( '2026-08-12', '2026-08-12', 'America/Los_Angeles' );
		$utc_13   = Report_Period::between( '2026-08-13', '2026-08-13', 'UTC' );

		$this->assertSame( 1, $this->reports->totals_for_org( 1100, $local_13 )['impressions'] );
		$this->assertSame( 1, $this->reports->totals_for_org( 1100, $local_12 )['impressions'] );
		$this->assertSame( 2, $this->reports->totals_for_org( 1100, $utc_13 )['impressions'] );
		$this->assertSame( 1, $this->reports->totals_for_org( 9990, $local_13 )['impressions'], 'The other organization must still see its own hour.' );
		$this->assertSame( 1, $this->reports->totals_for_org( 1100, $local_13 )['impressions'], 'Another organization\'s 08:00 hour leaked into the Pacific total.' );
		$this->assertSame( 0, $this->reports->totals_for_org( 1100, $local_13, 999 )['impressions'], 'A campaign predicate must still refuse a campaign the organization does not own.' );

		$series = $this->reports->series_for_org( 1100, $local_13 );

		$this->assertCount( 1, $series );
		$this->assertSame( '2026-08-13', $series[0]['day'] );
		$this->assertSame( 1, $series[0]['impressions'] );
	}

	/**
	 * Kolkata's 18:00 UTC hour is still the previous local day; 19:00 is not.
	 */
	public function test_kolkata_hours_follow_the_half_hour_offset(): void {
		$this->write( '2026-08-12 18:00:00', 12, 120, 1200 );
		$this->write( '2026-08-12 19:00:00', 12, 120, 1200 );

		$thirteenth = Report_Period::between( '2026-08-13', '2026-08-13', 'Asia/Kolkata' );
		$twelfth    = Report_Period::between( '2026-08-12', '2026-08-12', 'Asia/Kolkata' );

		$this->assertSame( 1, $this->reports->totals_for_org( 1200, $thirteenth )['impressions'] );
		$this->assertSame( 1, $this->reports->totals_for_org( 1200, $twelfth )['impressions'] );
	}

	/**
	 * A daily increment dual-writes midnight of that UTC day so a UTC report
	 * still finds a test that already named a day.
	 */
	public function test_a_daily_increment_also_writes_the_hour(): void {
		$this->assertTrue( ( new Rollup_Repository() )->increment( 'impressions', 13, 130, '2026-08-13', 130, 1300 ) );

		$utc = Report_Period::between( '2026-08-13', '2026-08-13', 'UTC' );

		$this->assertSame( 1, $this->reports->totals_for_org( 1300, $utc )['impressions'] );
	}

	/**
	 * Writes one impression at a named UTC hour.
	 *
	 * @param string $hour     UTC `Y-m-d H:00:00`.
	 * @param int    $placement Placement id.
	 * @param int    $campaign  Campaign id.
	 * @param int    $org       Organization id.
	 */
	private function write( string $hour, int $placement, int $campaign, int $org ): void {
		$this->assertTrue( $this->hourly->increment( 'impressions', $placement, $campaign, $hour, $campaign, $org ) );
	}
}
