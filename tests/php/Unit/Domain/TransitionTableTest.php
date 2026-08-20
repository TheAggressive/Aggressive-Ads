<?php
/**
 * The campaign lifecycle table.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Security\Capabilities;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Exhaustive, because it is affordable to be.
 *
 * The table calls no WordPress function, so every one of the 121 status pairs
 * can be checked in a few milliseconds. Testing only the transitions somebody
 * thought of is how an illegal edge ships: the interesting ones are always the
 * pairs nobody considered.
 */
final class TransitionTableTest extends TestCase {

	/**
	 * The legal edges, written out rather than derived from the table.
	 *
	 * Deriving the expectation from the subject would make this agree with any
	 * change, including a mistaken one. Duplicating the list means adding or
	 * removing an edge is a deliberate two-place edit, and a reviewer sees the
	 * lifecycle change in the diff.
	 *
	 * @return array<int, string>
	 */
	private static function expected_edges(): array {
		return array(
			'aggr_draft->aggr_submitted',
			'aggr_draft->aggr_cancelled',
			'aggr_submitted->aggr_draft',
			'aggr_submitted->aggr_review',
			'aggr_submitted->aggr_changes',
			'aggr_review->aggr_submitted',
			'aggr_review->aggr_changes',
			'aggr_review->aggr_rejected',
			'aggr_review->aggr_approved',
			'aggr_changes->aggr_submitted',
			'aggr_changes->aggr_cancelled',
			'aggr_rejected->aggr_draft',
			'aggr_approved->aggr_scheduled',
			'aggr_approved->aggr_live',
			'aggr_scheduled->aggr_live',
			'aggr_scheduled->aggr_paused',
			'aggr_scheduled->aggr_cancelled',
			'aggr_live->aggr_paused',
			'aggr_live->aggr_cancelled',
			'aggr_live->aggr_complete',
			'aggr_paused->aggr_live',
			'aggr_paused->aggr_cancelled',
		);
	}

	/**
	 * The table contains exactly the expected edges, and nothing else.
	 *
	 * @return void
	 */
	public function test_the_table_contains_exactly_the_expected_edges(): void {
		$actual = array();

		foreach ( Transition_Table::all() as $transition ) {
			$actual[] = $transition->id();
		}

		sort( $actual );

		$expected = self::expected_edges();
		sort( $expected );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * **Every one of the 121 status pairs answers correctly.**
	 *
	 * This is the assertion the whole layer exists to make affordable. An edge
	 * absent from the table cannot happen, and the only way to know that is to
	 * ask about every pair rather than the handful anyone remembers.
	 *
	 * @return void
	 */
	public function test_every_status_pair_is_answered_correctly(): void {
		$expected = self::expected_edges();
		$statuses = Post_Statuses::all();
		$checked  = 0;

		foreach ( $statuses as $from ) {
			foreach ( $statuses as $to ) {
				++$checked;

				$edge     = $from . '->' . $to;
				$is_legal = in_array( $edge, $expected, true );

				$this->assertSame(
					$is_legal,
					Transition_Table::is_legal( $from, $to ),
					$is_legal
						? "{$edge} should be legal and is not."
						: "{$edge} is legal and must not be."
				);
			}
		}

		$this->assertSame( 121, $checked, 'The status set changed; this test is no longer exhaustive.' );
	}

	/**
	 * A campaign never transitions to the status it is already in.
	 *
	 * A self-transition would write an audit row and fire a domain event for a
	 * change that did not happen, which is how a duplicate notification is sent
	 * for a campaign nobody touched.
	 *
	 * @return void
	 */
	public function test_there_are_no_self_transitions(): void {
		foreach ( Post_Statuses::all() as $status ) {
			$this->assertFalse(
				Transition_Table::is_legal( $status, $status ),
				"{$status} transitions to itself."
			);
		}
	}

	/**
	 * No edge is declared twice.
	 *
	 * Two rows for one pair means the first match wins and the second is dead —
	 * silently, and with different capabilities or guards.
	 *
	 * @return void
	 */
	public function test_no_edge_is_declared_twice(): void {
		$ids = array();

		foreach ( Transition_Table::all() as $transition ) {
			$ids[] = $transition->id();
		}

		$this->assertSame( $ids, array_unique( $ids ) );
	}

	/**
	 * Completed and cancelled are the terminal states.
	 *
	 * A completed campaign is duplicated into a new draft, never reopened —
	 * "renew" is a copy operation, not a transition backwards.
	 *
	 * @return void
	 */
	public function test_terminal_states_have_no_outgoing_edges(): void {
		$this->assertTrue( Transition_Table::is_terminal( Post_Statuses::COMPLETE ) );
		$this->assertTrue( Transition_Table::is_terminal( Post_Statuses::CANCELLED ) );

		$this->assertSame(
			Post_Statuses::terminal(),
			array_values(
				array_filter(
					Post_Statuses::all(),
					static fn ( string $status ): bool => Transition_Table::is_terminal( $status )
				)
			)
		);
	}

	/**
	 * Every status named by an edge is a registered status.
	 *
	 * A typo here produces an edge that can never match, which reads as "the
	 * approve button does nothing".
	 *
	 * @return void
	 */
	public function test_every_edge_names_registered_statuses(): void {
		foreach ( Transition_Table::all() as $transition ) {
			$this->assertTrue(
				Post_Statuses::is_valid( $transition->from ),
				"Unknown from-status: {$transition->from}"
			);
			$this->assertTrue(
				Post_Statuses::is_valid( $transition->to ),
				"Unknown to-status: {$transition->to}"
			);
		}
	}

	/**
	 * Every capability named by an edge is one the plugin actually defines.
	 *
	 * @return void
	 */
	public function test_every_edge_names_real_capabilities(): void {
		$known = Capabilities::all();

		foreach ( array( 'edit', 'read', 'delete' ) as $action ) {
			$known[] = Capabilities::meta_cap( \Aggressive\Ads\Core\Post_Types::CAMPAIGN, $action );
		}

		foreach ( Transition_Table::all() as $transition ) {
			foreach ( $transition->capabilities as $capability ) {
				$this->assertContains(
					$capability,
					$known,
					"{$transition->id()} requires unknown capability {$capability}"
				);
			}
		}
	}

	/**
	 * Clock-driven transitions require no capability, and everything else does.
	 *
	 * A person-driven transition with no capability is an unauthenticated state
	 * change. A system transition with one would need a current user, and there
	 * is none during cron.
	 *
	 * @return void
	 */
	public function test_capability_requirements_match_the_actor(): void {
		foreach ( Transition_Table::all() as $transition ) {
			if ( $transition->is_system() ) {
				$this->assertSame(
					array(),
					$transition->capabilities,
					"{$transition->id()} is clock-driven but requires a capability."
				);

				continue;
			}

			$this->assertNotEmpty(
				$transition->capabilities,
				"{$transition->id()} is person-driven but requires no capability."
			);
		}
	}

	/**
	 * Guards, effects and actors are declared constants, not loose strings.
	 *
	 * @return void
	 */
	public function test_guards_effects_and_actors_are_declared(): void {
		foreach ( Transition_Table::all() as $transition ) {
			foreach ( $transition->guards as $guard ) {
				$this->assertContains( $guard, Transition_Table::guards(), "{$transition->id()}: guard {$guard}" );
			}

			foreach ( $transition->effects as $effect ) {
				$this->assertContains( $effect, Transition_Table::effects(), "{$transition->id()}: effect {$effect}" );
			}

			$this->assertNotEmpty( $transition->actors, "{$transition->id()} has no actor." );

			foreach ( $transition->actors as $actor ) {
				$this->assertContains( $actor, Transition_Table::actors(), "{$transition->id()}: actor {$actor}" );
			}
		}
	}

	/**
	 * Approval is reachable only from review, and requires both capabilities.
	 *
	 * Reviewing is a judgement; publishing writes to a public site and can bill
	 * a customer. A path to approved that skipped review would skip both.
	 *
	 * @return void
	 */
	public function test_approval_is_reachable_only_from_review(): void {
		$sources = array();

		foreach ( Transition_Table::all() as $transition ) {
			if ( Post_Statuses::APPROVED === $transition->to ) {
				$sources[] = $transition->from;
			}
		}

		$this->assertSame( array( Post_Statuses::REVIEW ), $sources );

		$approval = Transition_Table::find( Post_Statuses::REVIEW, Post_Statuses::APPROVED );

		$this->assertNotNull( $approval );
		$this->assertContains( Capabilities::REVIEW_CAMPAIGNS, $approval->capabilities );
		$this->assertContains( Capabilities::PUBLISH_TO_ADSANITY, $approval->capabilities );
	}

	/**
	 * Approval re-runs the validator before anything is written.
	 *
	 * A placement can be deactivated, an organization suspended, or a start
	 * date fall into the past while a campaign sits in the queue. Native fill
	 * reads campaign status; there is no downstream ad to map.
	 *
	 * @return void
	 */
	public function test_approval_revalidates_before_publish(): void {
		$approval = Transition_Table::find( Post_Statuses::REVIEW, Post_Statuses::APPROVED );

		$this->assertNotNull( $approval );
		// Approval runs the approvable guard, not the submission validator. The
		// difference is deliberate: a start date that has passed while the
		// campaign sat in review is not a defect, and the advertiser cannot fix
		// it because the campaign is no longer theirs to edit.
		$this->assertTrue( $approval->has_guard( Transition_Table::GUARD_APPROVABLE ) );
		$this->assertFalse( $approval->has_guard( Transition_Table::GUARD_VALIDATOR ) );
		$this->assertFalse( $approval->has_guard( 'mappings_resolve' ) );
		$this->assertTrue( $approval->has_effect( Transition_Table::EFFECT_PUBLISH ) );
	}

	/**
	 * Only two paths return a campaign to draft.
	 *
	 * @return void
	 */
	public function test_draft_is_re_enterable_only_by_withdrawal_or_reopen(): void {
		$sources = array();

		foreach ( Transition_Table::all() as $transition ) {
			if ( Post_Statuses::DRAFT === $transition->to ) {
				$sources[] = $transition->from;
			}
		}

		sort( $sources );

		$this->assertSame( array( Post_Statuses::REJECTED, Post_Statuses::SUBMITTED ), $sources );
	}

	/**
	 * Withdrawal is only possible while no reviewer has claimed the campaign.
	 *
	 * @return void
	 */
	public function test_withdrawal_requires_an_unclaimed_campaign(): void {
		$withdrawal = Transition_Table::find( Post_Statuses::SUBMITTED, Post_Statuses::DRAFT );

		$this->assertNotNull( $withdrawal );
		$this->assertTrue( $withdrawal->has_guard( Transition_Table::GUARD_UNCLAIMED ) );
		$this->assertTrue( $withdrawal->allows_actor( Transition_Table::ACTOR_ADVERTISER ) );
		$this->assertFalse( $withdrawal->allows_actor( Transition_Table::ACTOR_STAFF ) );
	}

	/**
	 * Every transition that ends the advertiser's turn requires feedback.
	 *
	 * Sending a campaign back without saying why is the single most expensive
	 * thing a reviewer can do: the advertiser cannot act on it, so it becomes a
	 * support conversation.
	 *
	 * @return void
	 */
	public function test_sending_a_campaign_back_requires_review_notes(): void {
		$requires_notes = array(
			array( Post_Statuses::SUBMITTED, Post_Statuses::CHANGES ),
			array( Post_Statuses::REVIEW, Post_Statuses::CHANGES ),
			array( Post_Statuses::REVIEW, Post_Statuses::REJECTED ),
		);

		foreach ( $requires_notes as list( $from, $to ) ) {
			$transition = Transition_Table::find( $from, $to );

			$this->assertNotNull( $transition );
			$this->assertTrue(
				$transition->has_guard( Transition_Table::GUARD_REVIEW_NOTES ),
				"{$from}->{$to} does not require advertiser-visible feedback."
			);
		}
	}

	/**
	 * Leaving a state that has provider objects behind it unpublishes them.
	 *
	 * Otherwise the campaign stops being billed while its ads keep rendering —
	 * which is the mirror image of billing for a campaign nobody saw, and just
	 * as bad.
	 *
	 * @return void
	 */
	public function test_cancelling_a_published_campaign_unpublishes_it(): void {
		foreach ( Post_Statuses::published() as $from ) {
			$transition = Transition_Table::find( $from, Post_Statuses::CANCELLED );

			$this->assertNotNull( $transition, "{$from} cannot be cancelled." );
			$this->assertTrue(
				$transition->has_effect( Transition_Table::EFFECT_UNPUBLISH ),
				"{$from}->cancelled does not unpublish the provider objects."
			);
		}
	}

	/**
	 * Resubmission after changes increments the revision counter.
	 *
	 * @return void
	 */
	public function test_resubmission_increments_the_revision(): void {
		$resubmit = Transition_Table::find( Post_Statuses::CHANGES, Post_Statuses::SUBMITTED );

		$this->assertNotNull( $resubmit );
		$this->assertTrue( $resubmit->has_effect( Transition_Table::EFFECT_INCREMENT_REVISION ) );
		$this->assertTrue( $resubmit->has_effect( Transition_Table::EFFECT_STAMP_SUBMITTED ) );

		// The first submission does not, or every campaign would start at 1.
		$submit = Transition_Table::find( Post_Statuses::DRAFT, Post_Statuses::SUBMITTED );

		$this->assertNotNull( $submit );
		$this->assertFalse( $submit->has_effect( Transition_Table::EFFECT_INCREMENT_REVISION ) );
	}

	/**
	 * Claiming and releasing a review are mirror transitions.
	 *
	 * @return void
	 */
	public function test_claiming_and_releasing_a_review_are_mirrored(): void {
		$claim   = Transition_Table::find( Post_Statuses::SUBMITTED, Post_Statuses::REVIEW );
		$release = Transition_Table::find( Post_Statuses::REVIEW, Post_Statuses::SUBMITTED );

		$this->assertNotNull( $claim );
		$this->assertNotNull( $release );
		$this->assertTrue( $claim->has_effect( Transition_Table::EFFECT_CLAIM_REVIEWER ) );
		$this->assertTrue( $release->has_effect( Transition_Table::EFFECT_RELEASE_REVIEWER ) );
	}

	/**
	 * Every status except draft is reachable from somewhere.
	 *
	 * An unreachable status is a state the product can describe and never
	 * enter — usually the sign of a renamed slug.
	 *
	 * @return void
	 */
	public function test_every_status_except_draft_is_reachable(): void {
		$reachable = array();

		foreach ( Transition_Table::all() as $transition ) {
			$reachable[] = $transition->to;
		}

		foreach ( Post_Statuses::all() as $status ) {
			if ( Post_Statuses::DRAFT === $status ) {
				continue;
			}

			$this->assertContains( $status, $reachable, "{$status} is unreachable." );
		}
	}

	/**
	 * An advertiser can never reach approval, no matter the status.
	 *
	 * @return void
	 */
	public function test_no_advertiser_transition_reaches_approval_or_live(): void {
		foreach ( Post_Statuses::all() as $from ) {
			foreach ( Transition_Table::available_to( $from, Transition_Table::ACTOR_ADVERTISER ) as $transition ) {
				$this->assertNotSame( Post_Statuses::APPROVED, $transition->to );
				$this->assertNotSame( Post_Statuses::LIVE, $transition->to );
				$this->assertNotSame( Post_Statuses::SCHEDULED, $transition->to );
			}
		}
	}

	/**
	 * An unknown status has no transitions rather than throwing.
	 *
	 * The state machine turns an illegal transition into a WP_Error, and a
	 * client-supplied status is an expected input, not an exceptional one.
	 *
	 * @return void
	 */
	public function test_an_unknown_status_yields_nothing(): void {
		$this->assertSame( array(), Transition_Table::targets_from( 'publish' ) );
		$this->assertSame( array(), Transition_Table::targets_from( '' ) );
		$this->assertNull( Transition_Table::find( 'publish', Post_Statuses::LIVE ) );
		$this->assertFalse( Transition_Table::is_legal( 'aggr_bogus', Post_Statuses::APPROVED ) );
	}
}
