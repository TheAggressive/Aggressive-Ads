<?php
/**
 * WCAG contrast math.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Contrast;
use Aggressive\Ads\Domain\Settings_Schema;
use PHPUnit\Framework\TestCase;

/**
 * Brand save and the stylesheet test share this class. A palette that passes
 * one and fails the other is the defect this file exists to catch.
 */
final class ContrastTest extends TestCase {

	/**
	 * WCAG's worked example: black on white is 21:1.
	 *
	 * @return void
	 */
	public function test_black_on_white_is_twenty_one(): void {
		$this->assertEqualsWithDelta( 21.0, Contrast::ratio( '#000000', '#ffffff' ), 0.001 );
	}

	/**
	 * Identical colours have no contrast.
	 *
	 * @return void
	 */
	public function test_identical_colours_are_one(): void {
		$this->assertEqualsWithDelta( 1.0, Contrast::ratio( '#111214', '#111214' ), 0.001 );
	}

	/**
	 * Order does not matter: the formula uses the lighter luminance on top.
	 *
	 * @return void
	 */
	public function test_ratio_is_symmetric(): void {
		$this->assertSame(
			Contrast::ratio( '#111214', '#f7f4ee' ),
			Contrast::ratio( '#f7f4ee', '#111214' )
		);
	}

	/**
	 * The schema defaults are the same pairs the compiled stylesheet ships.
	 *
	 * @return void
	 */
	public function test_default_brand_pairs_pass_aa(): void {
		$brand = Settings_Schema::defaults()['brand'];

		$this->assertTrue( Contrast::passes( $brand['text'], $brand['canvas'] ) );
		$this->assertTrue( Contrast::passes( $brand['text'], $brand['surface'] ) );
		$this->assertTrue( Contrast::passes( Settings_Schema::ACCENT_CONTRAST, $brand['accent_strong'] ) );
		$this->assertTrue( Contrast::passes( $brand['accent_strong'], $brand['surface'] ) );
	}

	/**
	 * White on white is the obvious AA failure a save must reject.
	 *
	 * @return void
	 */
	public function test_white_on_white_fails_aa(): void {
		$this->assertFalse( Contrast::passes( '#ffffff', '#ffffff' ) );
	}
}
