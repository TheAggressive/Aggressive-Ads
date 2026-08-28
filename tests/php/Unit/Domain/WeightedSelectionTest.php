<?php
/**
 * Weighted selection among decision candidates.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Candidate;
use Aggressive\Ads\Domain\Weighted_Selection;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic replay and weight-shaped distribution.
 */
final class WeightedSelectionTest extends TestCase {

	/**
	 * Builds one candidate row.
	 *
	 * @param int $id     Assignment id.
	 * @param int $weight Delivery weight.
	 * @return Decision_Candidate
	 */
	private function candidate( int $id, int $weight ): Decision_Candidate {
		return new Decision_Candidate(
			array(
				'id'     => $id,
				'weight' => $weight,
			)
		);
	}

	public function test_empty_set_returns_no_winner(): void {
		$result = Weighted_Selection::choose( array(), 7 );

		$this->assertNull( $result['winner'] );
		$this->assertSame( array(), $result['losers'] );
	}

	public function test_the_same_seed_replays_the_same_winner(): void {
		$candidates = array(
			$this->candidate( 1, 100 ),
			$this->candidate( 2, 200 ),
			$this->candidate( 3, 300 ),
		);

		$first  = Weighted_Selection::choose( $candidates, 42 );
		$second = Weighted_Selection::choose( $candidates, 42 );

		$this->assertSame( $first['winner']?->id(), $second['winner']?->id() );
	}

	public function test_heavier_weight_wins_more_often_over_many_draws(): void {
		$heavy = $this->candidate( 1, 900 );
		$light = $this->candidate( 2, 100 );
		$wins  = array(
			1 => 0,
			2 => 0,
		);

		for ( $seed = 0; $seed < 1000; $seed++ ) {
			$result = Weighted_Selection::choose( array( $heavy, $light ), $seed );
			$this->assertNotNull( $result['winner'] );
			++$wins[ $result['winner']->id() ];
		}

		$this->assertGreaterThan( $wins[2], $wins[1] );
		$this->assertGreaterThan( 700, $wins[1] );
	}

	public function test_multi_candidate_share_of_voice_matches_proportions(): void {
		// 50% (500), 30% (300), 20% (200) share of voice.
		$candidates = array(
			$this->candidate( 1, 500 ),
			$this->candidate( 2, 300 ),
			$this->candidate( 3, 200 ),
		);

		$wins = array(
			1 => 0,
			2 => 0,
			3 => 0,
		);

		$draws = 10000;
		for ( $seed = 0; $seed < $draws; $seed++ ) {
			$result = Weighted_Selection::choose( $candidates, $seed );
			$this->assertNotNull( $result['winner'] );
			++$wins[ $result['winner']->id() ];
		}

		// Candidate 1 (50% target = 5000 wins): strictly between 4800 and 5200.
		$this->assertGreaterThanOrEqual( 4800, $wins[1] );
		$this->assertLessThanOrEqual( 5200, $wins[1] );

		// Candidate 2 (30% target = 3000 wins): strictly between 2800 and 3200.
		$this->assertGreaterThanOrEqual( 2800, $wins[2] );
		$this->assertLessThanOrEqual( 3200, $wins[2] );

		// Candidate 3 (20% target = 2000 wins): strictly between 1800 and 2200.
		$this->assertGreaterThanOrEqual( 1800, $wins[3] );
		$this->assertLessThanOrEqual( 2200, $wins[3] );
	}
}
