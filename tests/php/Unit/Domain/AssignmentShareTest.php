<?php
/**
 * Shares of one placement, which always add to a hundred.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Assignment_Rules;
use PHPUnit\Framework\TestCase;

/**
 * `rebalance()` against the arithmetic that has to hold every time.
 *
 * The column adding to a hundred is the whole promise of the control, so it
 * is asserted on every case here rather than on a chosen few — including the
 * ones that only round badly, which is where a per-creative rounding would
 * leave 99 or 101.
 */
final class AssignmentShareTest extends TestCase {

	/**
	 * Two creatives: what one takes, the other leaves.
	 *
	 * @return void
	 */
	public function test_two_creatives_split_what_is_asked(): void {
		$this->assertSame(
			array(
				1 => 70,
				2 => 30,
			),
			Assignment_Rules::rebalance(
				array(
					1 => 50,
					2 => 50,
				),
				1,
				70
			)
		);
	}

	/**
	 * The others keep the proportions they had between them.
	 *
	 * @return void
	 */
	public function test_the_others_keep_their_proportions(): void {
		// 3:1 between the others, so the 40 left over goes 30:10.
		$this->assertSame(
			array(
				1 => 60,
				2 => 30,
				3 => 10,
			),
			Assignment_Rules::rebalance(
				array(
					1 => 20,
					2 => 60,
					3 => 20,
				),
				1,
				60
			)
		);
	}

	/**
	 * Others with no weight between them share what is left evenly.
	 *
	 * @return void
	 */
	public function test_others_without_weight_split_evenly(): void {
		$this->assertSame(
			array(
				1 => 50,
				2 => 25,
				3 => 25,
			),
			Assignment_Rules::rebalance(
				array(
					1 => 10,
					2 => 0,
					3 => 0,
				),
				1,
				50
			)
		);
	}

	/**
	 * A share of one creative's placement is all of it.
	 *
	 * @return void
	 */
	public function test_one_creative_takes_the_whole_placement(): void {
		$this->assertSame( array( 1 => 100 ), Assignment_Rules::rebalance( array( 1 => 4 ), 1, 30 ) );
	}

	/**
	 * Everything keeps a share: nothing is switched off by arithmetic.
	 *
	 * @return void
	 */
	public function test_every_creative_keeps_at_least_one_per_cent(): void {
		$high = Assignment_Rules::rebalance(
			array(
				1 => 50,
				2 => 50,
				3 => 50,
			),
			1,
			100
		);

		$this->assertSame( 98, $high[1] );
		$this->assertSame( array( 1, 1 ), array( $high[2], $high[3] ) );

		$low = Assignment_Rules::rebalance(
			array(
				1 => 50,
				2 => 50,
			),
			1,
			0
		);

		$this->assertSame(
			array(
				1 => 1,
				2 => 99,
			),
			$low
		);
	}

	/**
	 * Whatever is asked, of however many, the column adds to a hundred.
	 *
	 * @return void
	 */
	public function test_the_shares_always_add_to_a_hundred(): void {
		for ( $count = 1; $count <= 9; $count++ ) {
			$weights = array();

			for ( $i = 1; $i <= $count; $i++ ) {
				// Uneven on purpose: equal weights never round badly.
				$weights[ $i ] = $i * 7;
			}

			for ( $percent = -5; $percent <= 105; $percent++ ) {
				$result = Assignment_Rules::rebalance( $weights, 1, $percent );

				$this->assertSame(
					Assignment_Rules::SHARE_TOTAL,
					array_sum( $result ),
					"{$count} creatives at {$percent}% do not add to a hundred."
				);
				$this->assertSame( array_keys( $weights ), array_keys( $result ), 'A creative left the placement.' );

				foreach ( $result as $id => $weight ) {
					$this->assertGreaterThanOrEqual( Assignment_Rules::MIN_WEIGHT, $weight, "Creative {$id} was left without a share." );
					$this->assertTrue( Assignment_Rules::is_weight( $weight ), "Creative {$id} is not a storable weight." );
				}
			}
		}
	}

	/**
	 * Asking for the share a creative already has changes nothing at all.
	 *
	 * @return void
	 */
	public function test_setting_the_share_it_already_has_is_a_no_op(): void {
		$settled = array(
			1 => 60,
			2 => 30,
			3 => 10,
		);

		$this->assertSame( $settled, Assignment_Rules::rebalance( $settled, 1, 60 ) );
	}

	/**
	 * An assignment that is not on the placement changes nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_assignment_is_refused_quietly(): void {
		$weights = array(
			1 => 50,
			2 => 50,
		);

		$this->assertSame( $weights, Assignment_Rules::rebalance( $weights, 99, 70 ) );
	}
}
