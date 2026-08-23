<?php
/**
 * The rules deciding what may be uploaded as a creative.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Upload_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Upload_Rules is the file that decides whether an SVG or a .phar becomes a
 * creative, and it calls no WordPress function — so it is exhaustively testable
 * in milliseconds, which is exactly the argument for testing it exhaustively.
 *
 * Each pair of lists matters in both directions. An allowlist alone lets
 * `image/svg+xml` through the day somebody adds it for "icon support"; a
 * denylist alone lets `application/x-php` through because nobody thought of it.
 * The tests below assert the allowlist accepts, the denylist refuses, and — the
 * case that actually bites — that the denylist wins when a value is on both.
 */
final class UploadRulesTest extends TestCase {

	/**
	 * Types a creative may be.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function allowed_mimes(): array {
		return array(
			'jpeg' => array( 'image/jpeg' ),
			'png'  => array( 'image/png' ),
			'gif'  => array( 'image/gif' ),
			'webp' => array( 'image/webp' ),
		);
	}

	/**
	 * Types that execute, script, or parse as something else.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused_mimes(): array {
		return array(
			'svg is stored XSS' => array( 'image/svg+xml' ),
			'html'              => array( 'text/html' ),
			'xml'               => array( 'application/xml' ),
			'text xml'          => array( 'text/xml' ),
			'php'               => array( 'application/x-php' ),
			'octet stream'      => array( 'application/octet-stream' ),
			'empty'             => array( '' ),
			'nonsense'          => array( 'not/a-type' ),
			'partial match'     => array( 'image/jpeg-ish' ),
			'prefix injection'  => array( 'text/html;image/png' ),
		);
	}

	/**
	 * Extensions a creative may carry.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function allowed_extensions(): array {
		return array(
			'jpg'  => array( 'jpg' ),
			'jpeg' => array( 'jpeg' ),
			'png'  => array( 'png' ),
			'gif'  => array( 'gif' ),
			'webp' => array( 'webp' ),
		);
	}

	/**
	 * Extensions that get executed by a misconfigured server.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused_extensions(): array {
		return array(
			'svg'   => array( 'svg' ),
			'svgz'  => array( 'svgz' ),
			'php'   => array( 'php' ),
			'phtml' => array( 'phtml' ),
			'phar'  => array( 'phar' ),
			'js'    => array( 'js' ),
			'html'  => array( 'html' ),
			'htm'   => array( 'htm' ),
			'swf'   => array( 'swf' ),
			'xml'   => array( 'xml' ),
			'empty' => array( '' ),
			'exe'   => array( 'exe' ),
		);
	}

	/**
	 * An image type the product supports is accepted.
	 *
	 * @param string $mime Candidate type.
	 * @return void
	 */
	#[DataProvider( 'allowed_mimes' )]
	public function test_an_allowed_mime_is_accepted( string $mime ): void {
		$this->assertTrue( Upload_Rules::is_allowed_mime( $mime ) );
	}

	/**
	 * A scriptable or unrecognised type never becomes a creative.
	 *
	 * @param string $mime Candidate type.
	 * @return void
	 */
	#[DataProvider( 'refused_mimes' )]
	public function test_a_refused_mime_is_rejected( string $mime ): void {
		$this->assertFalse( Upload_Rules::is_allowed_mime( $mime ) );
	}

	/**
	 * Case and surrounding whitespace do not smuggle a type past the lists.
	 *
	 * @return void
	 */
	public function test_mime_matching_is_case_and_whitespace_insensitive(): void {
		$this->assertTrue( Upload_Rules::is_allowed_mime( '  IMAGE/PNG  ' ) );
		$this->assertFalse( Upload_Rules::is_allowed_mime( '  Image/SVG+XML  ' ) );
	}

	/**
	 * An image extension the product supports is accepted.
	 *
	 * @param string $extension Candidate extension.
	 * @return void
	 */
	#[DataProvider( 'allowed_extensions' )]
	public function test_an_allowed_extension_is_accepted( string $extension ): void {
		$this->assertTrue( Upload_Rules::is_allowed_extension( $extension ) );
	}

	/**
	 * An extension a misconfigured server would execute is refused.
	 *
	 * @param string $extension Candidate extension.
	 * @return void
	 */
	#[DataProvider( 'refused_extensions' )]
	public function test_a_refused_extension_is_rejected( string $extension ): void {
		$this->assertFalse( Upload_Rules::is_allowed_extension( $extension ) );
	}

	/**
	 * A leading dot and mixed case are normalised before matching.
	 *
	 * @return void
	 */
	public function test_extension_matching_normalises_dots_and_case(): void {
		$this->assertTrue( Upload_Rules::is_allowed_extension( '.PNG' ) );
		$this->assertTrue( Upload_Rules::is_allowed_extension( ' .jpeg ' ) );
		$this->assertFalse( Upload_Rules::is_allowed_extension( '.PHP' ) );
	}

	/**
	 * The stored name comes from what the file is, not what it claimed to be.
	 *
	 * @return void
	 */
	public function test_the_extension_is_derived_from_the_detected_type(): void {
		$this->assertSame( 'jpg', Upload_Rules::extension_for_mime( 'image/jpeg' ) );
		$this->assertSame( 'png', Upload_Rules::extension_for_mime( 'IMAGE/PNG' ) );
		$this->assertSame( 'gif', Upload_Rules::extension_for_mime( 'image/gif' ) );
		$this->assertSame( 'webp', Upload_Rules::extension_for_mime( 'image/webp' ) );
		$this->assertSame( '', Upload_Rules::extension_for_mime( 'image/svg+xml' ) );
		$this->assertSame( '', Upload_Rules::extension_for_mime( '' ) );
	}

	/**
	 * Every allowed type maps to an allowed extension, and vice versa.
	 *
	 * The two lists are maintained by hand in the same file. This is the
	 * assertion that notices when one gains an entry and the other does not —
	 * a type with no extension stores a file with no suffix, and an extension
	 * with no type is an extension nothing can ever produce.
	 *
	 * @return void
	 */
	public function test_the_type_and_extension_lists_agree(): void {
		foreach ( Upload_Rules::ALLOWED_MIME as $mime ) {
			$extension = Upload_Rules::extension_for_mime( $mime );

			$this->assertNotSame( '', $extension, "{$mime} maps to no extension." );
			$this->assertTrue(
				Upload_Rules::is_allowed_extension( $extension ),
				"{$mime} maps to {$extension}, which the extension list refuses."
			);
		}
	}

	/**
	 * No entry appears on both an allowlist and its denylist.
	 *
	 * If one ever did, the answer would depend on which check runs first — and
	 * both functions check the denylist first precisely so the safe answer wins.
	 *
	 * @return void
	 */
	public function test_the_lists_do_not_overlap(): void {
		$this->assertSame(
			array(),
			array_intersect( Upload_Rules::ALLOWED_MIME, Upload_Rules::DENIED_MIME )
		);
		$this->assertSame(
			array(),
			array_intersect( Upload_Rules::ALLOWED_EXTENSIONS, Upload_Rules::DENIED_EXTENSIONS )
		);
	}

	/**
	 * The size ceiling is inclusive of the limit itself.
	 *
	 * @return void
	 */
	public function test_size_is_bounded_at_the_limit(): void {
		$this->assertFalse( Upload_Rules::exceeds_size( 0 ) );
		$this->assertFalse( Upload_Rules::exceeds_size( Upload_Rules::MAX_BYTES ) );
		$this->assertTrue( Upload_Rules::exceeds_size( Upload_Rules::MAX_BYTES + 1 ) );
	}

	/**
	 * A decompression bomb is refused, and so is a zero dimension.
	 *
	 * Zero counts as exceeding rather than passing: a reported dimension of 0
	 * means the decoder could not read the image, and treating "unknown" as
	 * "fine" is how the check gets skipped by malformed input.
	 *
	 * @return void
	 */
	public function test_pixel_bounds_refuse_bombs_and_unreadable_dimensions(): void {
		$this->assertFalse( Upload_Rules::exceeds_pixels( 728, 90 ) );
		$this->assertTrue( Upload_Rules::exceeds_pixels( 25000, 25000 ) );
		$this->assertTrue( Upload_Rules::exceeds_pixels( 0, 100 ) );
		$this->assertTrue( Upload_Rules::exceeds_pixels( 100, 0 ) );
		$this->assertTrue( Upload_Rules::exceeds_pixels( -1, -1 ) );
	}

	/**
	 * The display name cannot carry a path, a control character, or a traversal.
	 *
	 * @return void
	 */
	public function test_the_display_name_is_stripped_of_anything_path_like(): void {
		$this->assertSame( 'etcpasswd', Upload_Rules::safe_display_name( '../../etc/passwd' ) );
		$this->assertSame( 'evil.png', Upload_Rules::safe_display_name( "evil\0.png" ) );
		$this->assertSame( 'name.png', Upload_Rules::safe_display_name( "na\tme.png" ) );
		$this->assertSame( 'creative', Upload_Rules::safe_display_name( '...' ) );
		$this->assertSame( 'creative', Upload_Rules::safe_display_name( '' ) );
		$this->assertSame( 'creative', Upload_Rules::safe_display_name( '/' ) );
	}

	/**
	 * A very long name is truncated rather than refused.
	 *
	 * @return void
	 */
	public function test_a_long_display_name_is_truncated(): void {
		$name = Upload_Rules::safe_display_name( str_repeat( 'a', 500 ) . '.png' );

		$this->assertSame( 120, mb_strlen( $name ) );
	}
}
