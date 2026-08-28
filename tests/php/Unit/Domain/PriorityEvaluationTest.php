<?php
/**
 * Priority tier evaluation tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Candidate;
use Aggressive\Ads\Domain\Decision_Context;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Domain\Priority_Rules;
use Aggressive\Ads\Domain\Priority_Stage;
use PHPUnit\Framework\TestCase;

/**
 * Pure priority tests without WordPress.
 */
final class PriorityEvaluationTest extends TestCase {

	public function test_default_priority_is_hundred(): void {
		$this->assertSame( 100, Priority_Rules::extract_priority( array() ) );
		$this->assertSame( 100, Priority_Rules::extract_priority( array( 'priority' => 0 ) ) );
		$this->assertSame( 100, Priority_Rules::extract_priority( array( 'priority' => -5 ) ) );
	}

	public function test_custom_priority_is_extracted(): void {
		$this->assertSame( 1, Priority_Rules::extract_priority( array( 'priority' => 1 ) ) );
		$this->assertSame( 50, Priority_Rules::extract_priority( array( 'priority' => 50 ) ) );
	}

	public function test_highest_priority_identifies_minimum_value_among_eligible(): void {
		$candidates = array(
			new Decision_Candidate(
				array(
					'id'       => 1,
					'priority' => 100,
				)
			),
			new Decision_Candidate(
				array(
					'id'       => 2,
					'priority' => 10,
				)
			),
			new Decision_Candidate(
				array(
					'id'       => 3,
					'priority' => 50,
				)
			),
			( new Decision_Candidate(
				array(
					'id'       => 4,
					'priority' => 1,
				)
			) )->exclude( 'schedule', Exclusion_Reason::SCHEDULE_EXPIRED ),
		);

		// Eligible minimum is 10, because candidate 4 with priority 1 is excluded.
		$this->assertSame( 10, Priority_Rules::highest_priority( $candidates ) );
	}

	public function test_priority_stage_excludes_lower_tiers_with_reason(): void {
		$stage   = new Priority_Stage();
		$context = new Decision_Context( 1, 1_700_000_000 );

		$candidates = array(
			new Decision_Candidate(
				array(
					'id'       => 1,
					'priority' => 10,
					'weight'   => 100,
				)
			),
			new Decision_Candidate(
				array(
					'id'       => 2,
					'priority' => 10,
					'weight'   => 200,
				)
			),
			new Decision_Candidate(
				array(
					'id'       => 3,
					'priority' => 50,
					'weight'   => 500,
				)
			),
			new Decision_Candidate(
				array(
					'id'       => 4,
					'priority' => 100,
					'weight'   => 900,
				)
			),
		);

		$evaluated = $stage->evaluate( $candidates, $context );

		// Tier 10 candidates survive.
		$this->assertTrue( $evaluated[0]->is_eligible() );
		$this->assertTrue( $evaluated[1]->is_eligible() );

		// Lower tiers (50 and 100) are excluded.
		$this->assertFalse( $evaluated[2]->is_eligible() );
		$this->assertSame( 'priority', $evaluated[2]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::PRIORITY_LOWER, $evaluated[2]->exclusion_reason );

		$this->assertFalse( $evaluated[3]->is_eligible() );
		$this->assertSame( 'priority', $evaluated[3]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::PRIORITY_LOWER, $evaluated[3]->exclusion_reason );
	}

	public function test_priority_stage_leaves_empty_or_all_excluded_candidates_alone(): void {
		$stage   = new Priority_Stage();
		$context = new Decision_Context( 1, 1_700_000_000 );

		$this->assertSame( array(), $stage->evaluate( array(), $context ) );

		$already_excluded = array(
			( new Decision_Candidate(
				array(
					'id'       => 1,
					'priority' => 10,
				)
			) )->exclude( 'eligibility', Exclusion_Reason::ELIGIBILITY_INVALID_WEIGHT ),
		);

		$evaluated = $stage->evaluate( $already_excluded, $context );
		$this->assertFalse( $evaluated[0]->is_eligible() );
		$this->assertSame( 'eligibility', $evaluated[0]->exclusion_stage );
	}

	public function test_priority_stage_handles_unparseable_priority_values_gracefully(): void {
		$stage   = new Priority_Stage();
		$context = new Decision_Context( 1, 1_700_000_000 );

		$candidates = array(
			new Decision_Candidate(
				array(
					'id'       => 1,
					'priority' => 10,
				)
			),
			new Decision_Candidate(
				array(
					'id'       => 2,
					'priority' => 'invalid_non_numeric',
				)
			),
		);

		$evaluated = $stage->evaluate( $candidates, $context );
		$this->assertCount( 2, $evaluated );

		// Candidate 1 (priority 10) is highest.
		$this->assertTrue( $evaluated[0]->is_eligible() );

		// Candidate 2 falls back to default priority (100) which is lower than 10.
		$this->assertFalse( $evaluated[1]->is_eligible() );
		$this->assertSame( 'priority', $evaluated[1]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::PRIORITY_LOWER, $evaluated[1]->exclusion_reason );
	}
}
