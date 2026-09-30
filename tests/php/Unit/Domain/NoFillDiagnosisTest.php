<?php
/**
 * Which empty slots are the system working, and which are worth a look.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\No_Fill_Reason;
use PHPUnit\Framework\TestCase;

/**
 * The Reports screen labels every no-fill row with this answer, so a wrong one
 * tells a publisher to ignore unsold inventory or to chase a frequency cap.
 * The negatives are the half that matters: a reason wrongly marked expected is
 * a problem the screen now actively hides.
 */
final class NoFillDiagnosisTest extends TestCase {

	public function test_exactly_the_rules_somebody_set_are_expected(): void {
		$expected = array_values( array_filter( No_Fill_Reason::all(), array( No_Fill_Reason::class, 'is_expected' ) ) );

		$this->assertSame(
			array(
				No_Fill_Reason::TARGETING_MISMATCH,
				No_Fill_Reason::FREQUENCY_CAPPED,
				No_Fill_Reason::PACING_THROTTLED,
				No_Fill_Reason::COMPETITIVE_EXCLUDE,
			),
			$expected
		);
	}

	public function test_unsold_blocked_misconfigured_and_broken_are_worth_a_look(): void {
		foreach ( array(
			No_Fill_Reason::NO_CANDIDATES,
			No_Fill_Reason::ALL_INELIGIBLE,
			No_Fill_Reason::SCHEDULE_EXCLUDED,
			No_Fill_Reason::SIZE_UNAVAILABLE,
			No_Fill_Reason::PIPELINE_ERROR,
			No_Fill_Reason::UNKNOWN,
		) as $reason ) {
			$this->assertFalse( No_Fill_Reason::is_expected( $reason ), $reason );
		}

		// Every reason is classified one way or the other: six plus four.
		$this->assertCount( 10, No_Fill_Reason::all() );
	}

	/**
	 * A reason added later, and not yet classified, must surface rather than be
	 * waved through as normal.
	 */
	public function test_an_unrecognised_reason_is_worth_a_look(): void {
		$this->assertFalse( No_Fill_Reason::is_expected( 'some_future_reason' ) );
		$this->assertFalse( No_Fill_Reason::is_expected( '' ) );
	}
}
