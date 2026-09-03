<?php
/**
 * The domain layer's copy of REST's boolean rule, held to the original.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Slot_Options;
use WP_UnitTestCase;

/**
 * `Slot_Options` reads a written-out boolean the way `rest_sanitize_boolean()`
 * does, and cannot call it: the domain layer calls no WordPress function, which
 * is what makes its rules testable in milliseconds with no bootstrap.
 *
 * So the rule is written twice, and this is the test that stops the copies
 * drifting. It is here rather than in the unit suite for the obvious reason —
 * the thing being compared against is a WordPress function.
 *
 * The comparison is on `rotate` rather than `collapse_when_empty`, because
 * `collapse_when_empty` deliberately disagrees with `rest_sanitize_boolean()`
 * for one input: an absent attribute defaults to true there and to false here.
 * That disagreement is the feature, and asserting parity on the setting that
 * has it would either fail or have to carve out the case it exists to protect.
 */
final class SlotOptionsRestParityTest extends WP_UnitTestCase {

	/**
	 * Every spelling a person might reasonably write, plus the ones they do.
	 *
	 * @return array<int, mixed>
	 */
	private function vocabulary(): array {
		return array(
			'true',
			'false',
			'FALSE',
			'False',
			'TRUE',
			'yes',
			'no',
			'1',
			'0',
			'on',
			'off',
			'',
			' ',
			'null',
			'-1',
			'0.0',
			true,
			false,
			1,
			0,
			-1,
			1.5,
			0.0,
		);
	}

	/**
	 * The two readings agree on every value either of them can be handed.
	 *
	 * `no` and `off` are in the list and both read as **true**, which looks
	 * wrong and is: `rest_sanitize_boolean()` only knows `false` and `0`.
	 * Asserting the agreement rather than the intuition is the point — a change
	 * to WordPress's vocabulary should fail here, and so should a well-meant
	 * improvement to this plugin's copy of it.
	 *
	 * @return void
	 */
	public function test_the_copied_rule_agrees_with_the_original(): void {
		$checked = 0;

		foreach ( $this->vocabulary() as $written ) {
			$this->assertSame(
				rest_sanitize_boolean( $written ),
				Slot_Options::from_atts( array( 'rotate' => $written ) )->rotate,
				'Slot_Options and rest_sanitize_boolean disagree about ' . wp_json_encode( $written ) . '.'
			);

			++$checked;
		}

		// A guard that stops matching reports success over code it is no longer
		// reading. This one is a loop over a list, so it says how long the list
		// was.
		$this->assertSame( 23, $checked, 'The vocabulary shrank; parity was asserted over less than it claims.' );
	}
}
