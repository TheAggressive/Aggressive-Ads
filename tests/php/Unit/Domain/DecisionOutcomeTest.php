<?php
/**
 * The closed vocabulary of storable decision outcomes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use PHPUnit\Framework\TestCase;

/**
 * Pure, because the whole point of this class is that the vocabulary the table
 * accepts is decidable without a database.
 */
final class DecisionOutcomeTest extends TestCase {

	public function test_the_lifecycle_outcomes_are_storable(): void {
		$this->assertTrue( Decision_Outcome::is_storable( Decision_Outcome::REQUEST ) );
		$this->assertTrue( Decision_Outcome::is_storable( Decision_Outcome::FILL ) );
	}

	/**
	 * Every no-fill reason must be writable, or the pipeline computes a reason
	 * the store silently drops and the slot stays unexplained.
	 */
	public function test_every_no_fill_reason_is_storable(): void {
		$reasons = No_Fill_Reason::all();

		$this->assertNotEmpty( $reasons, 'Without reasons this test proves nothing.' );

		foreach ( $reasons as $reason ) {
			$this->assertTrue(
				Decision_Outcome::is_storable( $reason ),
				"No_Fill_Reason::{$reason} cannot be stored, so that slot would go unexplained."
			);
		}
	}

	/**
	 * **The bound that the option-backed counters did not have.**
	 *
	 * Any string became a key there, so the store grew with whatever a caller
	 * passed. Cardinality is now decided by the domain.
	 */
	public function test_an_invented_outcome_is_refused(): void {
		$this->assertFalse( Decision_Outcome::is_storable( 'made_up' ) );
		$this->assertFalse( Decision_Outcome::is_storable( '' ) );
		$this->assertFalse( Decision_Outcome::is_storable( 'REQUEST' ), 'Codes are lowercase; a case variant is a different key.' );
	}

	/**
	 * Nothing may be written that the column would truncate.
	 *
	 * A longer code does not error on write outside strict mode — it writes
	 * short and never matches on read, which is a row that exists and cannot be
	 * queried.
	 */
	public function test_no_storable_outcome_can_be_truncated_by_the_column(): void {
		foreach ( Decision_Outcome::all() as $outcome ) {
			$this->assertLessThanOrEqual(
				Decision_Outcome::MAX_LENGTH,
				strlen( $outcome ),
				"{$outcome} is longer than the column, so it would write short and never match."
			);
		}
	}

	/**
	 * The distinction a reader sums over: asked, succeeded, or a reason it did not.
	 */
	public function test_reasons_are_separated_from_the_lifecycle_counts(): void {
		$this->assertFalse( Decision_Outcome::is_no_fill_reason( Decision_Outcome::REQUEST ) );
		$this->assertFalse( Decision_Outcome::is_no_fill_reason( Decision_Outcome::FILL ) );
		$this->assertFalse( Decision_Outcome::is_no_fill_reason( 'made_up' ), 'An unstorable code is not a reason.' );

		foreach ( No_Fill_Reason::all() as $reason ) {
			$this->assertTrue( Decision_Outcome::is_no_fill_reason( $reason ) );
		}
	}

	/**
	 * And that the two halves partition the vocabulary with nothing left over,
	 * so a reader that sums requests, fills and reasons has counted everything.
	 */
	public function test_the_vocabulary_is_exactly_its_two_halves(): void {
		$all     = Decision_Outcome::all();
		$reasons = array_values( array_filter( $all, static fn ( string $o ): bool => Decision_Outcome::is_no_fill_reason( $o ) ) );

		$this->assertSame( count( $all ), count( $reasons ) + 2 );
		$this->assertSame( count( $all ), count( array_unique( $all ) ), 'A duplicated code would double-count a reason.' );
	}
}
