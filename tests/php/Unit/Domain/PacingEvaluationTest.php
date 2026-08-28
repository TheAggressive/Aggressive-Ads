<?php
/**
 * Pacing and delivery goal unit tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Candidate;
use Aggressive\Ads\Domain\Decision_Context;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Domain\Pacing_Rules;
use Aggressive\Ads\Domain\Pacing_Stage;
use PHPUnit\Framework\TestCase;

/**
 * Proves delivery caps, ASAP vs EVEN velocity calculations, and Pacing_Stage behavior.
 */
final class PacingEvaluationTest extends TestCase {

	public function test_unbounded_candidate_passes_pacing(): void {
		$row = array(
			'id'                 => 1,
			'lifetime_cap'       => 0,
			'daily_cap'          => 0,
			'delivered_lifetime' => 5000,
			'delivered_today'    => 500,
		);

		$this->assertNull( Pacing_Rules::evaluate_candidate( $row, 1_700_000_000 ) );
	}

	public function test_lifetime_cap_exceeded_excludes_candidate(): void {
		$row = array(
			'id'                 => 1,
			'lifetime_cap'       => 1000,
			'delivered_lifetime' => 1000,
		);

		$this->assertSame(
			Exclusion_Reason::PACING_LIFETIME_CAP_REACHED,
			Pacing_Rules::evaluate_candidate( $row, 1_700_000_000 )
		);
	}

	public function test_goal_amount_fallback_enforces_lifetime_cap(): void {
		$row = array(
			'id'                 => 1,
			'goal_amount'        => 500,
			'delivered_lifetime' => 501,
		);

		$this->assertSame(
			Exclusion_Reason::PACING_LIFETIME_CAP_REACHED,
			Pacing_Rules::evaluate_candidate( $row, 1_700_000_000 )
		);
	}

	public function test_daily_cap_exceeded_excludes_candidate(): void {
		$row = array(
			'id'              => 1,
			'daily_cap'       => 200,
			'delivered_today' => 200,
		);

		$this->assertSame(
			Exclusion_Reason::PACING_DAILY_CAP_REACHED,
			Pacing_Rules::evaluate_candidate( $row, 1_700_000_000 )
		);
	}

	public function test_asap_mode_does_not_throttle_when_under_caps(): void {
		$row = array(
			'id'                 => 1,
			'pacing_mode'        => 'asap',
			'lifetime_cap'       => 1000,
			'delivered_lifetime' => 900,
			'start_at_ts'        => 1_000_000,
			'end_at_ts'          => 2_000_000,
		);

		// At start of schedule (now = 1_000_100), delivered 90% (900/1000). ASAP should not throttle.
		$this->assertNull( Pacing_Rules::evaluate_candidate( $row, 1_000_100 ) );
	}

	public function test_even_pacing_throttles_when_ahead_of_flight_schedule(): void {
		$row = array(
			'id'                 => 1,
			'pacing_mode'        => 'even',
			'lifetime_cap'       => 1000,
			'delivered_lifetime' => 500, // 50% delivered.
			'start_at_ts'        => 1_000_000,
			'end_at_ts'          => 2_000_000, // Duration = 1,000,000s.
		);

		// Now is 1_100_000 (10% elapsed). 50% delivered > (10% + 10% tolerance) -> Throttled.
		$this->assertSame(
			Exclusion_Reason::PACING_THROTTLED,
			Pacing_Rules::evaluate_candidate( $row, 1_100_000 )
		);
	}

	public function test_even_pacing_passes_when_on_or_behind_flight_schedule(): void {
		$row = array(
			'id'                 => 1,
			'pacing_mode'        => 'even',
			'lifetime_cap'       => 1000,
			'delivered_lifetime' => 500, // 50% delivered.
			'start_at_ts'        => 1_000_000,
			'end_at_ts'          => 2_000_000, // Duration = 1,000,000s.
		);

		// Now is 1_600_000 (60% elapsed). 50% delivered <= (60% + 10% tolerance) -> Eligible.
		$this->assertNull( Pacing_Rules::evaluate_candidate( $row, 1_600_000 ) );
	}

	public function test_pacing_stage_excludes_with_correct_stage_and_reason(): void {
		$stage   = new Pacing_Stage();
		$context = new Decision_Context( 1, 1_700_000_000 );

		$candidates = array(
			new Decision_Candidate(
				array(
					'id'                 => 1,
					'lifetime_cap'       => 100,
					'delivered_lifetime' => 10,
				)
			),
			new Decision_Candidate(
				array(
					'id'                 => 2,
					'lifetime_cap'       => 100,
					'delivered_lifetime' => 100,
				)
			),
		);

		$evaluated = $stage->evaluate( $candidates, $context );

		$this->assertTrue( $evaluated[0]->is_eligible() );

		$this->assertFalse( $evaluated[1]->is_eligible() );
		$this->assertSame( 'pacing', $evaluated[1]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::PACING_LIFETIME_CAP_REACHED, $evaluated[1]->exclusion_reason );
	}

	public function test_pacing_stage_leaves_already_excluded_candidates_alone(): void {
		$stage   = new Pacing_Stage();
		$context = new Decision_Context( 1, 1_700_000_000 );

		$already_excluded = array(
			( new Decision_Candidate(
				array(
					'id'                 => 1,
					'lifetime_cap'       => 100,
					'delivered_lifetime' => 100,
				)
			) )->exclude( 'schedule', Exclusion_Reason::SCHEDULE_EXPIRED ),
		);

		$evaluated = $stage->evaluate( $already_excluded, $context );
		$this->assertFalse( $evaluated[0]->is_eligible() );
		$this->assertSame( 'schedule', $evaluated[0]->exclusion_stage );
	}
}
