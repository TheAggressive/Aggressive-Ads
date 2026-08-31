<?php
/**
 * Every string the conversions screen names is one the server sends.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The `Strings` type is a cast, not a check.
 *
 * The bundle reads its catalog out of a data attribute with
 * `JSON.parse( raw ) as Payload`, so TypeScript will happily believe in a key
 * PHP never sends. The compiler catches a *misspelling* inside the bundle and
 * nothing else; a control shipped without its catalog entry renders
 * `undefined` as a label and the screen merely looks unfinished.
 *
 * This is the guard for the other direction, and it exists because the
 * credentials card added twenty-odd strings at once — the exact circumstance in
 * which one gets dropped.
 */
final class ConversionStringsTest extends TestCase {

	/**
	 * Every field named by the `Strings` type.
	 *
	 * Read from the type rather than from `i18n.foo` uses, because a string is
	 * declared once and read in several places, and the declaration is the list
	 * PHP is actually contracted to fill.
	 *
	 * @return array<int, string>
	 */
	private function declared_keys(): array {
		$source = (string) file_get_contents( AGGR_PLUGIN_DIR . 'src/admin/conversions/types.ts' );

		$block = array();

		if ( 1 !== preg_match( '/export type Strings = \{(.*?)\};/s', $source, $block ) ) {
			return array();
		}

		$matches = array();

		preg_match_all( '/^\s*([a-zA-Z0-9_]+)\s*:\s*string;/m', $block[1], $matches );

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * The catalog the screen ships to the browser.
	 *
	 * Read from the source rather than by booting WordPress, so this stays in
	 * the unit suite where it costs nothing to run.
	 */
	private function catalog(): string {
		return (string) file_get_contents( AGGR_PLUGIN_DIR . 'inc/Admin/class-conversions-screen.php' );
	}

	/**
	 * The scan found something.
	 *
	 * A guard that stops matching reports success over code it is no longer
	 * reading — the failure mode most of this repository's guards shipped with —
	 * so the count is asserted before anything is asserted about it.
	 */
	public function test_the_scan_actually_finds_keys(): void {
		$this->assertGreaterThan(
			30,
			count( $this->declared_keys() ),
			'The Strings scan found almost nothing, so this guard is not reading the type it guards.'
		);
	}

	/** Every declared string has a translated value behind it. */
	public function test_every_declared_string_is_sent(): void {
		$catalog = $this->catalog();
		$missing = array();

		foreach ( $this->declared_keys() as $key ) {
			if ( ! str_contains( $catalog, "'{$key}'" ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'These render as “undefined” on the screen: ' . implode( ', ', $missing )
		);
	}
}
