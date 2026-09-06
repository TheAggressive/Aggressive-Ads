<?php
/**
 * Pure decision pipeline stages.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Candidate;
use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Domain\Decision_Request;
use Aggressive\Ads\Domain\Exclusion_Reason;
use PHPUnit\Framework\TestCase;

/**
 * Every stage verdict and weighted selection without WordPress.
 */
final class DecisionPipelineTest extends TestCase {

	/**
	 * Builds one assignment row for pipeline tests.
	 *
	 * @param array<string, mixed> $overrides Row overrides.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 10,
				'line_item_id'  => 3,
				'campaign_id'   => 5,
				'revision_id'   => 9,
				'weight'        => 100,
				'attachment_id' => 77,
				'click_url'     => 'https://example.com/ad',
			),
			$overrides
		);
	}

	public function test_invalid_click_url_is_excluded_before_selection(): void {
		$pipeline = Decision_Pipeline::standard();
		$result   = $pipeline->decide(
			array( $this->row( array( 'click_url' => 'javascript:alert(1)' ) ) ),
			new Decision_Request( 1, 1_700_000_000, 3 )
		);

		$this->assertFalse( $result['result']->has_winner() );

		/*
		 * The slot's reason is the candidate's, because every candidate lost
		 * for it. A generic no-fill here would tell a publisher nothing was
		 * assigned when something was, and send them looking at a campaign that
		 * is working exactly as configured.
		 */
		$this->assertSame( Exclusion_Reason::ELIGIBILITY_INVALID_CLICK_URL, $result['result']->reason );
		$this->assertSame(
			Exclusion_Reason::ELIGIBILITY_INVALID_CLICK_URL,
			$result['trace']->entries[0]['reason']
		);
	}

	public function test_weight_decides_among_eligible_candidates(): void {
		$pipeline = Decision_Pipeline::standard();
		$rows     = array(
			$this->row(
				array(
					'id'     => 1,
					'weight' => 100,
				)
			),
			$this->row(
				array(
					'id'     => 2,
					'weight' => 900,
				)
			),
		);

		$result = $pipeline->decide( $rows, new Decision_Request( 1, 1_700_000_000, 850 ) );

		$this->assertTrue( $result['result']->has_winner() );
		$this->assertSame( 2, (int) $result['result']->winner['id'] );
		$this->assertSame(
			Exclusion_Reason::COMPETITION_LOST,
			$result['trace']->entries[0]['reason']
		);
	}

	public function test_every_candidate_leaves_with_a_reason_when_none_survive(): void {
		$pipeline = Decision_Pipeline::standard();
		$result   = $pipeline->decide(
			array(
				$this->row(
					array(
						'id'            => 1,
						'attachment_id' => 0,
					)
				),
				$this->row(
					array(
						'id'     => 2,
						'weight' => 0,
					)
				),
			),
			new Decision_Request( 1, 1_700_000_000, 1 )
		);

		foreach ( $result['trace']->entries as $entry ) {
			$this->assertTrue( $entry['excluded'] );
			$this->assertIsString( $entry['reason'] );
		}
	}

	public function test_schedule_expired_candidate_is_excluded_in_pipeline(): void {
		$pipeline = Decision_Pipeline::standard();
		$result   = $pipeline->decide(
			array(
				$this->row(
					array(
						'id'          => 1,
						'start_at_ts' => 1_700_000_000,
						'end_at_ts'   => 1_700_000_100,
					)
				),
			),
			new Decision_Request( 1, 1_700_000_200, 1 )
		);

		$this->assertFalse( $result['result']->has_winner() );

		/*
		 * The slot's reason is the candidate's, because every candidate lost
		 * for it. A generic no-fill here would tell a publisher nothing was
		 * assigned when something was, and send them looking at a campaign that
		 * is working exactly as configured.
		 */
		$this->assertSame( Exclusion_Reason::SCHEDULE_EXPIRED, $result['result']->reason );
		$this->assertSame(
			Exclusion_Reason::SCHEDULE_EXPIRED,
			$result['trace']->entries[0]['reason']
		);
		$this->assertSame(
			Decision_Pipeline::STAGE_SCHEDULE,
			$result['trace']->entries[0]['stage']
		);
	}

	public function test_higher_priority_tier_candidate_wins_over_lower_tier_regardless_of_weight(): void {
		$pipeline = Decision_Pipeline::standard();
		$rows     = array(
			$this->row(
				array(
					'id'       => 1,
					'priority' => 10,
					'weight'   => 10, // Small weight, but high priority tier (10).
				)
			),
			$this->row(
				array(
					'id'       => 2,
					'priority' => 100,
					'weight'   => 990, // Massive weight, but lower priority tier (100).
				)
			),
		);

		$result = $pipeline->decide( $rows, new Decision_Request( 1, 1_700_000_000, 999 ) );

		$this->assertTrue( $result['result']->has_winner() );
		$this->assertSame( 1, (int) $result['result']->winner['id'] );

		// Candidate 2 was excluded by Priority stage.
		$this->assertSame(
			Exclusion_Reason::PRIORITY_LOWER,
			$result['trace']->entries[1]['reason']
		);
		$this->assertSame(
			Decision_Pipeline::STAGE_PRIORITY,
			$result['trace']->entries[1]['stage']
		);
	}

	public function test_lifetime_cap_candidate_is_excluded_in_pipeline_pacing_stage(): void {
		$pipeline = Decision_Pipeline::standard();
		$result   = $pipeline->decide(
			array(
				$this->row(
					array(
						'id'                 => 1,
						'lifetime_cap'       => 500,
						'delivered_lifetime' => 500,
					)
				),
			),
			new Decision_Request( 1, 1_700_000_000, 1 )
		);

		$this->assertFalse( $result['result']->has_winner() );

		/*
		 * The slot's reason is the candidate's, because every candidate lost
		 * for it. A generic no-fill here would tell a publisher nothing was
		 * assigned when something was, and send them looking at a campaign that
		 * is working exactly as configured.
		 */
		$this->assertSame( Exclusion_Reason::PACING_LIFETIME_CAP_REACHED, $result['result']->reason );
		$this->assertSame(
			Exclusion_Reason::PACING_LIFETIME_CAP_REACHED,
			$result['trace']->entries[0]['reason']
		);
		$this->assertSame(
			Decision_Pipeline::STAGE_PACING,
			$result['trace']->entries[0]['stage']
		);
	}
}
