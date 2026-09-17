<?php
/**
 * The time note a date field starts with.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Portal\Date_Input;
use WP_UnitTestCase;

/**
 * The server's note is what a page without script keeps, so it has to name the
 * zone for the date in the field, not for the day the page was drawn.
 */
final class DateInputEdgeLabelTest extends WP_UnitTestCase {

	/**
	 * Restores the site zone.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 0 );
		parent::tear_down();
	}

	public function test_it_names_the_zone_for_the_day_in_the_field(): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );

		$this->assertSame( '12:00 AM PDT', Date_Input::edge_label( '2030-09-17', false ) );
		$this->assertSame( '12:00 AM PST', Date_Input::edge_label( '2030-12-17', false ) );
		$this->assertSame( '11:59 PM PST', Date_Input::edge_label( '2030-12-17', true ) );
		$this->assertSame( 'PST', Date_Input::zone_abbreviation( '2030-12-17' ) );
	}

	public function test_a_manual_offset_reads_as_utc(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 5.5 );

		$this->assertSame( '12:00 AM UTC+05:30', Date_Input::edge_label( '2030-09-17', false ) );
	}

	public function test_an_empty_or_invalid_day_describes_today(): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );

		$today = Date_Input::edge_label( (string) wp_date( 'Y-m-d' ), false );

		$this->assertSame( $today, Date_Input::edge_label( '', false ) );
		$this->assertSame( $today, Date_Input::edge_label( '2030-02-31', false ) );
	}
}
