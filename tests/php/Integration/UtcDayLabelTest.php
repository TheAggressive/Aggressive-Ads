<?php
/**
 * A stored day is a day, and rendering it in local time moves it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Report_Data;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Found in a screenshot rather than by a test, which is why this exists.
 *
 * The portal dashboard showed a date input of `2026-08-09` beside a caption
 * reading "August 8, 2026 to September 6, 2026 (UTC)". Both came from the same
 * period object, so they could not disagree about the data — the caption was
 * re-formatting. `wp_date()` without a timezone renders in the site's, and the
 * site is `America/Los_Angeles`, so midnight UTC on the ninth is the eighth
 * there. Every date in a line ending "(UTC)" was a day early.
 *
 * Nothing was wrong with the figures. The caption was describing a different
 * window from the one it had, which is worse than a wrong number: a reader
 * comparing it against the input beside it has no way to tell which is lying.
 *
 * The suite runs UTC by default, where the bug is invisible. These set a
 * timezone behind Greenwich, because that is the only place it appears.
 */
final class UtcDayLabelTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		update_option( 'timezone_string', 'America/Los_Angeles' );
		update_option( 'date_format', 'F j, Y' );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', '' );

		parent::tear_down();
	}

	public function test_the_site_timezone_is_actually_behind_utc(): void {
		/*
		 * The fixture is the whole test. In UTC both readings agree and every
		 * assertion below passes over the defect.
		 */
		$this->assertSame( 'America/Los_Angeles', get_option( 'timezone_string' ) );
		$this->assertLessThan( 0, (int) wp_timezone()->getOffset( new \DateTimeImmutable( '2026-08-09' ) ) );
	}

	public function test_a_delivery_range_names_the_days_it_covers(): void {
		$view = Plugin::instance()->container()->get( Delivery_View_Data::class );

		$period = $view->period();
		$label  = $view->range_label();

		$this->assertStringContainsString(
			(string) wp_date( 'F j, Y', (int) strtotime( $period->start . ' UTC' ), new \DateTimeZone( 'UTC' ) ),
			$label,
			'The caption named a different first day from the period it was built from, under a sentence ending "(UTC)".'
		);
		$this->assertStringContainsString(
			(string) wp_date( 'F j, Y', (int) strtotime( $period->end . ' UTC' ), new \DateTimeZone( 'UTC' ) ),
			$label
		);
	}

	public function test_the_range_agrees_with_the_date_input_beside_it(): void {
		$view = Plugin::instance()->container()->get( Delivery_View_Data::class );

		$period = $view->period();

		/*
		 * The input carries the raw `Y-m-d`, so this is the comparison a reader
		 * makes without thinking about it: the day in the box and the day in
		 * the sentence have to be the same day.
		 */
		$day = (int) gmdate( 'j', (int) strtotime( $period->start . ' UTC' ) );

		$this->assertStringContainsString(
			' ' . $day . ',',
			$view->range_label(),
			'The date input shows the UTC day and the caption showed the day before it.'
		);
	}

	public function test_the_freshness_note_names_the_day_that_is_still_moving(): void {
		$data = Plugin::instance()->container()->get( Report_Data::class );

		$note = $data->freshness_note( $data->period( 30 ) );

		if ( '' === $note ) {
			$this->markTestSkipped( 'Every day in range is reconciled, so there is no boundary to name.' );
		}

		$boundary = $data->period( 30 )->unreconciled_from( '', gmdate( 'Y-m-d' ) );

		$this->assertIsString( $boundary );
		$this->assertStringContainsString(
			(string) wp_date( 'F j, Y', (int) strtotime( $boundary . ' UTC' ), new \DateTimeZone( 'UTC' ) ),
			$note,
			'It named the day before the one still being counted, so a publisher re-checking rows would check the wrong day.'
		);
	}
}
