<?php
/**
 * The isolation a preview renders inside, and who agrees about it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Preview_Frame;
use PHPUnit\Framework\TestCase;

/**
 * P17 calls preview untrusted rendering and asks for isolation that can be
 * pointed at rather than looked at. These are the assertions that can run in
 * milliseconds: the policy's clauses, the sandbox, and the fact that three
 * surfaces draw the same frame from one set of numbers.
 *
 * The headers themselves are asserted where they are sent, in
 * `tests/php/Rest/CreativeFileTest.php`.
 */
final class PreviewFrameTest extends TestCase {

	/**
	 * An empty sandbox is every restriction, and that is the point.
	 *
	 * A capability added here is added to every preview on every surface, so
	 * it should be hard to do by accident — which is what this assertion is.
	 *
	 * @return void
	 */
	public function test_the_frame_grants_no_capability_back(): void {
		$this->assertSame( '', Preview_Frame::SANDBOX, 'A preview frame gained a capability; say which and why.' );
	}

	/**
	 * The policy allows the image and nothing else.
	 *
	 * @return void
	 */
	public function test_the_policy_permits_the_image_and_nothing_else(): void {
		$policy = Preview_Frame::POLICY;

		$this->assertStringContainsString( "default-src 'none'", $policy );
		$this->assertStringContainsString( "img-src 'self'", $policy );
		$this->assertStringContainsString( 'sandbox', $policy );
		$this->assertStringContainsString( "frame-ancestors 'self'", $policy );
		$this->assertStringContainsString( "base-uri 'none'", $policy );
		$this->assertStringContainsString( "form-action 'none'", $policy );

		foreach ( array( 'unsafe-inline', 'unsafe-eval', 'script-src', "'unsafe-hashes'", '*' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$policy,
				"The preview policy now contains {$forbidden}, which is a way for a creative to run."
			);
		}
	}

	/**
	 * The widths are offered narrowest first, which is the order they read in.
	 *
	 * @return void
	 */
	public function test_the_widths_are_offered_narrowest_first(): void {
		$widths = array_values( Preview_Frame::widths() );
		$sorted = $widths;

		sort( $sorted );

		$this->assertSame( $sorted, $widths );
		$this->assertNotSame( array(), $widths, 'A preview with no widths renders nothing to choose between.' );

		foreach ( Preview_Frame::widths() as $key => $width ) {
			$this->assertTrue( Preview_Frame::is_width( (string) $key ) );
			$this->assertGreaterThan( 0, $width );
		}

		$this->assertFalse( Preview_Frame::is_width( 'watch' ) );
	}
}
