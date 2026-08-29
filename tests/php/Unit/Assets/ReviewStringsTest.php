<?php
/**
 * Every string the review screen asks for is one the server sends.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * `t()` answers a key it does not have with an empty string.
 *
 * That is the right runtime behaviour — a missing label should not throw on a
 * publisher's screen — but it means a typo, or a control shipped without its
 * catalog entry, renders a blank label and an unlabelled input. Nothing errors,
 * nothing logs, and the screen looks merely unfinished. This is the only thing
 * that notices.
 */
final class ReviewStringsTest extends TestCase {

	/**
	 * Every `t( 'key' )` in the review bundle.
	 *
	 * @return array<int, string>
	 */
	private function requested_keys(): array {
		$keys = array();

		$files = glob( AGGR_PLUGIN_DIR . 'src/admin/review/*.tsx' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$source  = (string) file_get_contents( $file );
			$matches = array();

			preg_match_all( "/\bt\(\s*'([a-zA-Z0-9_]+)'\s*\)/", $source, $matches );

			$keys = array_merge( $keys, $matches[1] );
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * The catalog the review screen ships to the browser.
	 *
	 * Read from the source rather than by booting WordPress, so this stays in
	 * the unit suite where it costs nothing to run.
	 *
	 * @return string
	 */
	private function catalog(): string {
		return (string) file_get_contents( AGGR_PLUGIN_DIR . 'inc/Admin/class-review-screen.php' );
	}

	/**
	 * The scan found something.
	 *
	 * A guard that stops matching reports success over code it is no longer
	 * reading, so the count is asserted before anything is asserted about it.
	 */
	public function test_the_scan_actually_finds_keys(): void {
		$keys = $this->requested_keys();

		$this->assertGreaterThan(
			30,
			count( $keys ),
			'The review screen scan found almost nothing, so this guard is not reading the code it guards.'
		);
	}

	/** Every key the screen renders has a translated string behind it. */
	public function test_every_requested_string_exists(): void {
		$catalog = $this->catalog();
		$missing = array();

		foreach ( $this->requested_keys() as $key ) {
			if ( ! str_contains( $catalog, "'{$key}'" ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'These keys render as an empty string: ' . implode( ', ', $missing )
		);
	}
}
