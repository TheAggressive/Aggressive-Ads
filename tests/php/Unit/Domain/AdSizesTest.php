<?php
/**
 * Common IAB sizes and custom WxH.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Ad_Sizes;
use PHPUnit\Framework\TestCase;

/**
 * Common sizes are a catalogue; custom is still `{width}x{height}`.
 */
final class AdSizesTest extends TestCase {

	/**
	 * Catalogue keys are stored sizes.
	 *
	 * @return void
	 */
	public function test_catalogue_keys_are_valid_sizes(): void {
		foreach ( Ad_Sizes::catalogue() as $size => $label ) {
			$this->assertTrue( Ad_Sizes::is_valid( $size ), $size );
			$this->assertNotSame( '', $label );
			$this->assertTrue( Ad_Sizes::is_listed( $size ) );
		}
	}

	/**
	 * Custom pixel pairs are valid when they fit the upload cap.
	 *
	 * @return void
	 */
	public function test_custom_sizes_are_accepted(): void {
		$this->assertTrue( Ad_Sizes::is_valid( '123x45' ) );
		$this->assertSame( '123x45', Ad_Sizes::from_dimensions( 123, 45 ) );
		$this->assertFalse( Ad_Sizes::is_listed( '123x45' ) );
	}

	/**
	 * Multiplication sign, zeros, and oversize rasters are refused.
	 *
	 * @return void
	 */
	public function test_invalid_sizes_are_refused(): void {
		$this->assertFalse( Ad_Sizes::is_valid( "728\u{00D7}90" ) );
		$this->assertFalse( Ad_Sizes::is_valid( Ad_Sizes::from_dimensions( 0, 90 ) ) );
		$this->assertFalse( Ad_Sizes::is_valid( 'leaderboard' ) );
		$this->assertFalse( Ad_Sizes::is_valid( Ad_Sizes::from_dimensions( 5000, 5001 ) ) );
	}
}
