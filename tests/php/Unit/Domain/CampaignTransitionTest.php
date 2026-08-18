<?php
/**
 * One edge of the campaign state machine.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Campaign_Transition;
use Aggressive\Ads\Domain\Transition_Table;
use PHPUnit\Framework\TestCase;

/**
 * `TransitionTableTest` asserts which edges exist. This asserts what a single
 * edge answers about itself, which is what the state machine actually asks it
 * before allowing a change.
 */
final class CampaignTransitionTest extends TestCase {

	/**
	 * Builds an edge with only the parts a test cares about.
	 *
	 * @param array<int, string> $actors       Who may make it.
	 * @param array<int, string> $guards       Conditions.
	 * @param array<int, string> $effects      Side effects.
	 * @return Campaign_Transition
	 */
	private function edge( array $actors = array(), array $guards = array(), array $effects = array() ): Campaign_Transition {
		return new Campaign_Transition( 'aggr_draft', 'aggr_submitted', $actors, array(), $guards, $effects );
	}

	/**
	 * The id is what audit entries and error messages are keyed by.
	 *
	 * @return void
	 */
	public function test_the_id_names_both_ends(): void {
		$this->assertSame( 'aggr_draft->aggr_submitted', $this->edge()->id() );
	}

	/**
	 * An actor in the list may act; one outside it may not.
	 *
	 * @return void
	 */
	public function test_only_listed_actors_are_allowed(): void {
		$edge = $this->edge( array( Transition_Table::ACTOR_ADVERTISER ) );

		$this->assertTrue( $edge->allows_actor( Transition_Table::ACTOR_ADVERTISER ) );
		$this->assertFalse( $edge->allows_actor( Transition_Table::ACTOR_STAFF ) );
	}

	/**
	 * An edge with no actors admits nobody.
	 *
	 * The safe default: an edge that forgot to declare its actors must refuse
	 * everyone rather than admit everyone.
	 *
	 * @return void
	 */
	public function test_an_edge_with_no_actors_allows_nobody(): void {
		$edge = $this->edge();

		$this->assertFalse( $edge->allows_actor( Transition_Table::ACTOR_ADVERTISER ) );
		$this->assertFalse( $edge->allows_actor( Transition_Table::ACTOR_STAFF ) );
		$this->assertFalse( $edge->allows_actor( Transition_Table::ACTOR_SYSTEM ) );
	}

	/**
	 * Actor matching is exact, not loose.
	 *
	 * @return void
	 */
	public function test_actor_matching_is_strict(): void {
		$edge = $this->edge( array( Transition_Table::ACTOR_STAFF ) );

		$this->assertFalse( $edge->allows_actor( strtoupper( Transition_Table::ACTOR_STAFF ) ) );
		$this->assertFalse( $edge->allows_actor( '' ) );
	}

	/**
	 * System edges are the ones the clock drives.
	 *
	 * @return void
	 */
	public function test_a_system_edge_is_one_the_system_may_make(): void {
		$this->assertTrue( $this->edge( array( Transition_Table::ACTOR_SYSTEM ) )->is_system() );
		$this->assertFalse( $this->edge( array( Transition_Table::ACTOR_STAFF ) )->is_system() );
	}

	/**
	 * An edge open to both a person and the clock still counts as system.
	 *
	 * Resume is exactly this shape, and the clock has to be able to take it.
	 *
	 * @return void
	 */
	public function test_an_edge_shared_with_a_person_is_still_a_system_edge(): void {
		$edge = $this->edge( array( Transition_Table::ACTOR_STAFF, Transition_Table::ACTOR_SYSTEM ) );

		$this->assertTrue( $edge->is_system() );
		$this->assertTrue( $edge->allows_actor( Transition_Table::ACTOR_STAFF ) );
	}

	/**
	 * Effects and guards are reported only when declared.
	 *
	 * @return void
	 */
	public function test_effects_and_guards_are_reported_only_when_declared(): void {
		$edge = $this->edge( array(), array( 'has_creative' ), array( 'notify_staff' ) );

		$this->assertTrue( $edge->has_effect( 'notify_staff' ) );
		$this->assertFalse( $edge->has_effect( 'publish' ) );
		$this->assertTrue( $edge->has_guard( 'has_creative' ) );
		$this->assertFalse( $edge->has_guard( 'is_paid' ) );
	}

	/**
	 * A guard is not an effect, and an effect is not a guard.
	 *
	 * They are separate lists carrying similar-looking strings, so a lookup
	 * crossing between them would let an edge claim a condition it never
	 * declared.
	 *
	 * @return void
	 */
	public function test_guards_and_effects_do_not_answer_for_each_other(): void {
		$edge = $this->edge( array(), array( 'only_a_guard' ), array( 'only_an_effect' ) );

		$this->assertFalse( $edge->has_effect( 'only_a_guard' ) );
		$this->assertFalse( $edge->has_guard( 'only_an_effect' ) );
	}

	/**
	 * Both default to empty, so an edge declaring neither claims neither.
	 *
	 * @return void
	 */
	public function test_an_edge_declaring_neither_claims_neither(): void {
		$edge = $this->edge();

		$this->assertSame( array(), $edge->guards );
		$this->assertSame( array(), $edge->effects );
		$this->assertFalse( $edge->has_guard( 'anything' ) );
		$this->assertFalse( $edge->has_effect( 'anything' ) );
	}
}
