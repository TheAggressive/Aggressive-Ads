<?php
/**
 * Equal rotation pick.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Fill_Rotation;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The draw selects a member; an empty set is not a fake first item.
 */
final class FillRotationTest extends TestCase {

	/**
	 * Known draws are wrap-safe and stable.
	 *
	 * @return void
	 */
	public function test_at_wraps_the_draw_across_the_set(): void {
		$set = array( 'a', 'b', 'c' );

		$this->assertSame( 'a', Fill_Rotation::at( $set, 0 ) );
		$this->assertSame( 'b', Fill_Rotation::at( $set, 1 ) );
		$this->assertSame( 'c', Fill_Rotation::at( $set, 2 ) );
		$this->assertSame( 'a', Fill_Rotation::at( $set, 3 ) );
		$this->assertSame( 'c', Fill_Rotation::at( $set, -1 ) );
	}

	/**
	 * Nothing to show must not invent a candidate.
	 *
	 * @return void
	 */
	public function test_empty_set_is_null(): void {
		$this->assertNull( Fill_Rotation::at( array(), 0 ) );
		$this->assertNull( Fill_Rotation::at( array(), 7 ) );
	}
}
