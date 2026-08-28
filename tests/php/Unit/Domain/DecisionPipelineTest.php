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
		$this->assertSame( Exclusion_Reason::NO_FILL, $result['result']->reason );
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
}
