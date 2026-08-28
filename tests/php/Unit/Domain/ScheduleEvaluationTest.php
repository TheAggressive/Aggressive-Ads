<?php
/**
 * Exact schedule and daypart evaluation tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Candidate;
use Aggressive\Ads\Domain\Decision_Context;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Domain\Schedule_Rules;
use Aggressive\Ads\Domain\Schedule_Stage;
use PHPUnit\Framework\TestCase;

/**
 * Pure schedule and daypart tests without WordPress.
 */
final class ScheduleEvaluationTest extends TestCase {

	public function test_unbounded_window_passes(): void {
		$this->assertNull( Schedule_Rules::evaluate_window( 1_700_000_000, 0, 0 ) );
	}

	public function test_candidate_before_start_time_is_excluded_not_started(): void {
		$start = 1_700_000_100;
		$now   = 1_700_000_000;

		$this->assertSame(
			Exclusion_Reason::SCHEDULE_NOT_STARTED,
			Schedule_Rules::evaluate_window( $now, $start, 0 )
		);
	}

	public function test_candidate_at_or_after_end_time_is_excluded_expired(): void {
		$end = 1_700_000_100;

		// Exact boundary.
		$this->assertSame(
			Exclusion_Reason::SCHEDULE_EXPIRED,
			Schedule_Rules::evaluate_window( $end, 0, $end )
		);

		// After boundary.
		$this->assertSame(
			Exclusion_Reason::SCHEDULE_EXPIRED,
			Schedule_Rules::evaluate_window( $end + 1, 0, $end )
		);
	}

	public function test_candidate_within_window_passes(): void {
		$start = 1_700_000_000;
		$now   = 1_700_000_500;
		$end   = 1_700_001_000;

		$this->assertNull( Schedule_Rules::evaluate_window( $now, $start, $end ) );
	}

	public function test_daypart_matching_active_day_and_hours_passes(): void {
		// 2026-08-28 14:30:00 UTC is a Friday (ISO day 5).
		// 14:30 is minute 870 (14*60 + 30).
		$now = (int) strtotime( '2026-08-28 14:30:00 UTC' );

		$dayparts = array(
			array(
				'days'         => array( 1, 2, 3, 4, 5 ),
				'start_minute' => 540,  // 09:00
				'end_minute'   => 1020, // 17:00
			),
		);

		$this->assertNull( Schedule_Rules::evaluate_dayparts( $now, $dayparts, 'UTC' ) );
	}

	public function test_daypart_outside_active_day_is_excluded(): void {
		// 2026-08-28 is Friday (ISO day 5).
		$now = (int) strtotime( '2026-08-28 14:30:00 UTC' );

		$dayparts = array(
			array(
				'days'       => array( 6, 7 ), // Weekends only.
				'start_time' => '09:00',
				'end_time'   => '17:00',
			),
		);

		$this->assertSame(
			Exclusion_Reason::SCHEDULE_DAYPART_EXCLUDED,
			Schedule_Rules::evaluate_dayparts( $now, $dayparts, 'UTC' )
		);
	}

	public function test_daypart_outside_active_hour_is_excluded(): void {
		// 14:30 UTC.
		$now = (int) strtotime( '2026-08-28 14:30:00 UTC' );

		$dayparts = array(
			array(
				'days'       => array( 5 ),
				'start_hour' => 9,
				'end_hour'   => 12, // 09:00 to 12:00
			),
		);

		$this->assertSame(
			Exclusion_Reason::SCHEDULE_DAYPART_EXCLUDED,
			Schedule_Rules::evaluate_dayparts( $now, $dayparts, 'UTC' )
		);
	}

	public function test_daypart_overnight_span(): void {
		// 23:30 on Friday.
		$now_night = (int) strtotime( '2026-08-28 23:30:00 UTC' );
		// 03:30 on Friday.
		$now_early = (int) strtotime( '2026-08-28 03:30:00 UTC' );
		// 12:00 on Friday.
		$now_noon = (int) strtotime( '2026-08-28 12:00:00 UTC' );

		$overnight_rule = array(
			array(
				'days'       => array( 5 ),
				'start_time' => '22:00',
				'end_time'   => '06:00',
			),
		);

		$this->assertNull( Schedule_Rules::evaluate_dayparts( $now_night, $overnight_rule, 'UTC' ) );
		$this->assertNull( Schedule_Rules::evaluate_dayparts( $now_early, $overnight_rule, 'UTC' ) );
		$this->assertSame(
			Exclusion_Reason::SCHEDULE_DAYPART_EXCLUDED,
			Schedule_Rules::evaluate_dayparts( $now_noon, $overnight_rule, 'UTC' )
		);
	}

	public function test_timezone_translation_applies_correctly(): void {
		// 2026-08-28 14:00:00 UTC is 10:00:00 AM EDT (America/New_York, UTC-4).
		$now = (int) strtotime( '2026-08-28 14:00:00 UTC' );

		// Rule in NY time: active 09:00 - 12:00.
		$ny_morning = array(
			array(
				'days'       => array( 5 ),
				'start_hour' => 9,
				'end_hour'   => 12,
			),
		);

		// Rule in NY time: active 15:00 - 18:00 (which is 19:00-22:00 UTC).
		$ny_afternoon = array(
			array(
				'days'       => array( 5 ),
				'start_hour' => 15,
				'end_hour'   => 18,
			),
		);

		$this->assertNull( Schedule_Rules::evaluate_dayparts( $now, $ny_morning, 'America/New_York' ) );
		$this->assertSame(
			Exclusion_Reason::SCHEDULE_DAYPART_EXCLUDED,
			Schedule_Rules::evaluate_dayparts( $now, $ny_afternoon, 'America/New_York' )
		);
	}

	public function test_invalid_timezone_is_rejected(): void {
		$now = (int) strtotime( '2026-08-28 14:00:00 UTC' );

		$dayparts = array(
			array(
				'days' => array( 5 ),
			),
		);

		$this->assertSame(
			Exclusion_Reason::SCHEDULE_INVALID_TIMEZONE,
			Schedule_Rules::evaluate_dayparts( $now, $dayparts, 'Invalid/Timezone_Name' )
		);
	}

	public function test_stage_catches_unexpected_throwables_and_isolates_candidate(): void {
		$stage   = new Schedule_Stage();
		$context = new Decision_Context( 1, 1_700_000_500 );

		// Malformed string structure in daypart element to provoke a TypeError/Throwable.
		$malformed_row = array(
			'id'          => 99,
			'start_at_ts' => 1_700_000_000,
			'end_at_ts'   => 1_700_001_000,
			'dayparts'    => array( 'unparseable_malformed_structure' ),
		);

		$candidates = array( new Decision_Candidate( $malformed_row ) );
		$evaluated  = $stage->evaluate( $candidates, $context );

		$this->assertCount( 1, $evaluated );
		$this->assertFalse( $evaluated[0]->is_eligible() );
		$this->assertSame( 'schedule', $evaluated[0]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::SCHEDULE_STAGE_ERROR, $evaluated[0]->exclusion_reason );
	}

	public function test_schedule_stage_evaluates_candidate_correctly(): void {
		$stage   = new Schedule_Stage();
		$context = new Decision_Context( 1, 1_700_000_500 );

		$valid_row = array(
			'id'          => 1,
			'start_at_ts' => 1_700_000_000,
			'end_at_ts'   => 1_700_001_000,
			'timezone'    => 'UTC',
			'weight'      => 100,
		);

		$expired_row = array(
			'id'          => 2,
			'start_at_ts' => 1_700_000_000,
			'end_at_ts'   => 1_700_000_400,
			'weight'      => 100,
		);

		$candidates = array(
			new Decision_Candidate( $valid_row ),
			new Decision_Candidate( $expired_row ),
			( new Decision_Candidate( $valid_row ) )->exclude( 'eligibility', Exclusion_Reason::ELIGIBILITY_INVALID_WEIGHT ),
		);

		$evaluated = $stage->evaluate( $candidates, $context );

		$this->assertTrue( $evaluated[0]->is_eligible() );

		$this->assertFalse( $evaluated[1]->is_eligible() );
		$this->assertSame( 'schedule', $evaluated[1]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::SCHEDULE_EXPIRED, $evaluated[1]->exclusion_reason );

		// Already excluded candidate remains unchanged.
		$this->assertFalse( $evaluated[2]->is_eligible() );
		$this->assertSame( 'eligibility', $evaluated[2]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::ELIGIBILITY_INVALID_WEIGHT, $evaluated[2]->exclusion_reason );
	}
}
