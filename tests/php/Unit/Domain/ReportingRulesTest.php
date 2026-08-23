<?php
/**
 * CTR arithmetic.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Reporting_Rules;
use PHPUnit\Framework\TestCase;

/**
 * Zero impressions is "unknown", not 0%.
 */
final class ReportingRulesTest extends TestCase {

	/**
	 * Clicks over a positive impression count is a ratio.
	 *
	 * @return void
	 */
	public function test_ctr_is_clicks_per_impression(): void {
		$this->assertSame( 0.0, Reporting_Rules::ctr( 10, 0 ) );
		$this->assertSame( 0.1, Reporting_Rules::ctr( 10, 1 ) );
		$this->assertSame( 1.0, Reporting_Rules::ctr( 4, 4 ) );
	}

	/**
	 * Nothing to divide by must not become a fake 0%.
	 *
	 * @return void
	 */
	public function test_ctr_is_null_without_impressions(): void {
		$this->assertNull( Reporting_Rules::ctr( 0, 0 ) );
		$this->assertNull( Reporting_Rules::ctr( 0, 3 ) );
		$this->assertNull( Reporting_Rules::ctr( -1, 1 ) );
	}

	/**
	 * Seven UTC days ending on the given date, oldest first.
	 *
	 * @return void
	 */
	public function test_utc_day_keys_are_inclusive_and_oldest_first(): void {
		$this->assertSame(
			array( '2026-08-07', '2026-08-08', '2026-08-09', '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13' ),
			Reporting_Rules::utc_day_keys( 7, '2026-08-13' )
		);
		$this->assertSame( array( '2026-01-01' ), Reporting_Rules::utc_day_keys( 1, '2026-01-01' ) );
		$this->assertSame( array(), Reporting_Rules::utc_day_keys( 0, '2026-08-13' ) );
		$this->assertSame( array(), Reporting_Rules::utc_day_keys( 7, '13-08-2026' ) );
		$this->assertSame( array(), Reporting_Rules::utc_day_keys( 32, '2026-08-13' ) );
	}

	/**
	 * A zero max is a flat line. A lone impression still occupies one pixel.
	 *
	 * @return void
	 */
	public function test_bar_height_is_flat_when_empty_and_visible_when_not(): void {
		$this->assertSame( 0, Reporting_Rules::bar_height( 0, 0 ) );
		$this->assertSame( 0, Reporting_Rules::bar_height( 0, 10 ) );
		$this->assertSame( 1, Reporting_Rules::bar_height( 1, 100 ) );
		$this->assertSame( 100, Reporting_Rules::bar_height( 50, 50 ) );
		$this->assertSame( 50, Reporting_Rules::bar_height( 5, 10 ) );
	}
}
