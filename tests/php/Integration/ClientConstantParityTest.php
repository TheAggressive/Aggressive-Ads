<?php
/**
 * Numbers that exist in both PHP and JavaScript, held to each other.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Refresh_Policy;
use Aggressive\Ads\Domain\Size_Map;
use Aggressive\Ads\Domain\Slot_Options;
use WP_UnitTestCase;

/**
 * Three copies of the rotation floor exist — PHP, `rotation.js` and the editor
 * — and the editor's docblock has said "`PlacementSlotTest` asserts the three
 * agree" for as long as the third copy has existed. **It did not.** Nothing read
 * the JavaScript, so the sentence was a description of an intention.
 *
 * That was survivable while the only shared number was a floor of one. It stops
 * being survivable now the migration hands existing placements the client's own
 * refresh cap: if `MAX_ROTATIONS` moves and PHP does not, every upgraded
 * placement is given a bound the client no longer enforces, and nothing
 * anywhere would say so.
 *
 * So this reads the source. A constant duplicated across two languages is a
 * constant that drifts, and the only guard that works is the one that looks.
 */
final class ClientConstantParityTest extends WP_UnitTestCase {

	/**
	 * Reads a `const NAME = <integer>;` declaration out of a JavaScript file.
	 *
	 * Deliberately strict about the shape. A looser pattern that matched the
	 * name anywhere would also match the docblock above the declaration, and a
	 * guard satisfied by prose is the failure this whole file is about.
	 *
	 * @param string $relative Path under the plugin directory.
	 * @param string $name     Constant name.
	 * @return int|null The value, or null when the declaration is absent.
	 */
	private function js_constant( string $relative, string $name ): ?int {
		$source = (string) file_get_contents( AGGR_PLUGIN_DIR . $relative );

		$found = preg_match(
			'/^(?:export\s+)?const\s+' . preg_quote( $name, '/' ) . '\s*=\s*(\d+)\s*;$/m',
			$source,
			$matches
		);

		return 1 === $found ? (int) $matches[1] : null;
	}

	/**
	 * Every number that exists in more than one language agrees with itself.
	 *
	 * @return void
	 */
	public function test_the_shared_constants_agree_across_languages(): void {
		$pairs = array(
			'view store rotation floor' => array(
				'src/blocks-interactivity/ad-slot/rotation.js',
				'MIN_ROTATE_SECONDS',
				Slot_Options::MIN_ROTATE_SECONDS,
			),
			'editor rotation floor'     => array(
				'src/blocks-interactivity/ad-slot/edit.js',
				'MIN_ROTATE_SECONDS',
				Slot_Options::MIN_ROTATE_SECONDS,
			),
			'client refresh hard stop'  => array(
				'src/blocks-interactivity/ad-slot/rotation.js',
				'MAX_ROTATIONS',
				Refresh_Policy::LEGACY_CLIENT_MAX_PER_VIEW,
			),
			'breakpoint ceiling'        => array(
				'src/admin/inventory/types.ts',
				'MAX_BREAKPOINTS',
				Size_Map::MAX_BREAKPOINTS,
			),
		);

		$checked = 0;

		foreach ( $pairs as $label => $pair ) {
			list( $file, $name, $expected ) = $pair;

			$actual = $this->js_constant( $file, $name );

			$this->assertNotNull(
				$actual,
				$label . ': ' . $name . ' was not found in ' . $file . '. A renamed constant fails here rather than passing over nothing.'
			);
			$this->assertSame(
				$expected,
				$actual,
				$label . ': PHP says ' . $expected . ' and ' . $file . ' says ' . $actual . '.'
			);

			++$checked;
		}

		// A guard that stops matching reports success over code it is no longer
		// reading, so it says how much it read.
		$this->assertSame( 4, $checked, 'The parity list shrank; fewer constants are guarded than this claims.' );
	}
}
