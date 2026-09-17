<?php
/**
 * Blank ad templates.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Size_Template;
use PHPUnit\Framework\TestCase;

/**
 * The file handed out has to be the size the upload is checked against.
 */
final class SizeTemplateTest extends TestCase {

	/**
	 * The artboard carries the placement's exact pixel size.
	 *
	 * @return void
	 */
	public function test_the_template_is_the_placement_size(): void {
		$svg = Size_Template::svg( '728x90' );

		$this->assertStringContainsString( 'width="728" height="90"', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 728 90"', $svg );
		$this->assertStringContainsString( '>728 x 90</text>', $svg );
	}

	/**
	 * Anything that is not a size gets no file, not a guessed one.
	 *
	 * @return void
	 */
	public function test_a_size_that_is_not_one_gets_nothing(): void {
		$this->assertSame( '', Size_Template::svg( '' ) );
		$this->assertSame( '', Size_Template::svg( '728×90' ) );
		$this->assertSame( '', Size_Template::svg( 'wide' ) );
	}
}
