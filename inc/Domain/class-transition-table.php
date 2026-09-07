<?php
/**
 * The campaign lifecycle, as data.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Security\Capabilities;

/**
 * Every legal status change a campaign can make. **An edge absent from this
 * table cannot happen.**
 *
 * Keeping the lifecycle as one table rather than as branches spread across
 * controllers is the entire point: the question "can a campaign go from here
 * to there, and who may do it?" has exactly one answer, in one file, and every
 * surface gets the same answer. See docs/campaign-workflow.md and
 * docs/campaign-workflow.md.
 *
 * This class calls no WordPress function, which is what makes the rules
 * testable exhaustively in milliseconds — all 121 status pairs, not just the
 * happy ones.
 */
final class Transition_Table {

	public const ACTOR_ADVERTISER = 'advertiser';
	public const ACTOR_STAFF      = 'staff';
	public const ACTOR_SYSTEM     = 'system';

	/**
	 * The campaign passes its full validation.
	 */
	public const GUARD_VALIDATOR = 'validator';

	/**
	 * Everything the submission validator checks, except that the campaign has
	 * not started yet.
	 *
	 * A start date is in the future when an advertiser chooses it, and review
	 * takes time. Failing approval because the campaign it describes was due to
	 * begin on Tuesday punishes the reviewer for the queue length, and the
	 * advertiser cannot fix it — the campaign is in review, so it is no longer
	 * theirs to edit. The only route out was to reject a campaign that was
	 * never wrong.
	 *
	 * The clock already expects this: MAX_HOPS exists so a campaign approved
	 * after its own start date can cross approved → live → complete in one
	 * sweep.
	 */
	public const GUARD_APPROVABLE = 'approvable';

	/**
	 * No reviewer has claimed the campaign.
	 */
	public const GUARD_UNCLAIMED = 'unclaimed';

	/**
	 * Advertiser-visible review notes are present and non-empty.
	 */
	public const GUARD_REVIEW_NOTES = 'review_notes';

	/**
	 * The campaign's start time has arrived.
	 */
	public const GUARD_STARTED = 'started';

	/**
	 * The campaign's start time is still in the future.
	 */
	public const GUARD_NOT_STARTED = 'not_started';

	/**
	 * The campaign's end time has passed.
	 */
	public const GUARD_ENDED = 'ended';

	public const EFFECT_STAMP_SUBMITTED    = 'stamp_submitted';
	public const EFFECT_CLAIM_REVIEWER     = 'claim_reviewer';
	public const EFFECT_RELEASE_REVIEWER   = 'release_reviewer';
	public const EFFECT_INCREMENT_REVISION = 'increment_revision';
	public const EFFECT_PUBLISH            = 'publish';
	public const EFFECT_UNPUBLISH          = 'unpublish';
	public const EFFECT_SUPPRESS           = 'suppress';
	public const EFFECT_RESUME             = 'resume';

	/**
	 * Every legal edge.
	 *
	 * Built rather than declared as a constant because the entries reference
	 * capability and status names from their owning classes; a literal array
	 * would duplicate those strings, and a duplicated capability string is a
	 * typo waiting to silently grant or silently deny.
	 *
	 * @return array<int, Campaign_Transition>
	 */
	public static function all(): array {
		static $transitions = null;

		if ( null !== $transitions ) {
			return $transitions;
		}

		$edit   = Capabilities::meta_cap( Post_Types::CAMPAIGN, 'edit' );
		$delete = Capabilities::meta_cap( Post_Types::CAMPAIGN, 'delete' );

		$transitions = array(

			/*
			 * A campaign before anyone has looked at it.
			 *
			 * **Staff may submit as well as advertisers**, because a publisher
			 * who enters a campaign on an advertiser's behalf is the workflow
			 * on a site where staff do the data entry. Refusing them left the
			 * campaign stuck in draft with no route forward: every holder of
			 * `aggr_review_campaigns` is classed as staff, so an administrator
			 * could never submit anything, including a campaign they had just
			 * created themselves.
			 *
			 * **This widens who may act, not what they may do.** Both
			 * capabilities are still required and both are checked against the
			 * campaign, so `map_meta_cap` answers the ownership question in the
			 * same call: an advertiser from another organization is refused
			 * exactly as before, and so is anyone without
			 * `aggr_submit_campaign`.
			 *
			 * Withdrawal deliberately stays advertiser-only below. Submitting
			 * on someone's behalf is help; pulling a campaign back out from
			 * under the reviewer working on it is not.
			 */
			new Campaign_Transition(
				Post_Statuses::DRAFT,
				Post_Statuses::SUBMITTED,
				array( self::ACTOR_ADVERTISER, self::ACTOR_STAFF ),
				array( Capabilities::SUBMIT_CAMPAIGN, $edit ),
				array( self::GUARD_VALIDATOR ),
				array( self::EFFECT_STAMP_SUBMITTED )
			),
			new Campaign_Transition(
				Post_Statuses::DRAFT,
				Post_Statuses::CANCELLED,
				array( self::ACTOR_ADVERTISER ),
				array( $delete )
			),

			// Withdrawal is only possible while nobody is reviewing it. Once a
			// reviewer has claimed the campaign, pulling it out from under them
			// is how two people end up working from different versions.
			new Campaign_Transition(
				Post_Statuses::SUBMITTED,
				Post_Statuses::DRAFT,
				array( self::ACTOR_ADVERTISER ),
				array( $edit ),
				array( self::GUARD_UNCLAIMED )
			),

			// Staff review.
			new Campaign_Transition(
				Post_Statuses::SUBMITTED,
				Post_Statuses::REVIEW,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array(),
				array( self::EFFECT_CLAIM_REVIEWER )
			),
			new Campaign_Transition(
				Post_Statuses::SUBMITTED,
				Post_Statuses::CHANGES,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array( self::GUARD_REVIEW_NOTES )
			),
			new Campaign_Transition(
				Post_Statuses::REVIEW,
				Post_Statuses::SUBMITTED,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array(),
				array( self::EFFECT_RELEASE_REVIEWER )
			),
			new Campaign_Transition(
				Post_Statuses::REVIEW,
				Post_Statuses::CHANGES,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array( self::GUARD_REVIEW_NOTES )
			),
			new Campaign_Transition(
				Post_Statuses::REVIEW,
				Post_Statuses::REJECTED,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array( self::GUARD_REVIEW_NOTES )
			),

			// Approval is the transaction. The validator runs again before
			// anything is written. Native fill reads campaign status; there is
			// no downstream ad CPT to map.
			new Campaign_Transition(
				Post_Statuses::REVIEW,
				Post_Statuses::APPROVED,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS, Capabilities::PUBLISH_TO_ADSANITY ),
				array( self::GUARD_APPROVABLE ),
				array( self::EFFECT_PUBLISH )
			),

			/*
			 * Correction and resubmission.
			 *
			 * **Staff may resubmit, for the same reason they may submit.** The
			 * two are one action from the person doing them — "send this for
			 * review" — and fixing only the draft edge left a publisher able to
			 * submit a new campaign and unable to resubmit the one a reviewer
			 * had just asked them to correct. That is the more common half of
			 * the workflow, and it was the half still refused.
			 *
			 * The line this phase draws is between moving work *forward* and
			 * taking it *away*: submitting and resubmitting are help, while
			 * withdrawal and cancellation below stay with the advertiser whose
			 * campaign it is.
			 */
			new Campaign_Transition(
				Post_Statuses::CHANGES,
				Post_Statuses::SUBMITTED,
				array( self::ACTOR_ADVERTISER, self::ACTOR_STAFF ),
				array( Capabilities::SUBMIT_CAMPAIGN, $edit ),
				array( self::GUARD_VALIDATOR ),
				array( self::EFFECT_STAMP_SUBMITTED, self::EFFECT_INCREMENT_REVISION )
			),
			new Campaign_Transition(
				Post_Statuses::CHANGES,
				Post_Statuses::CANCELLED,
				array( self::ACTOR_ADVERTISER ),
				array( $delete )
			),

			// A rejection is not necessarily final; staff can reopen it.
			new Campaign_Transition(
				Post_Statuses::REJECTED,
				Post_Statuses::DRAFT,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS )
			),

			// Clock-derived. These are pure functions of time: there is no
			// moment at which something must run for them to become true.
			new Campaign_Transition(
				Post_Statuses::APPROVED,
				Post_Statuses::SCHEDULED,
				array( self::ACTOR_SYSTEM ),
				array(),
				array( self::GUARD_NOT_STARTED )
			),
			new Campaign_Transition(
				Post_Statuses::APPROVED,
				Post_Statuses::LIVE,
				array( self::ACTOR_SYSTEM ),
				array(),
				array( self::GUARD_STARTED )
			),
			new Campaign_Transition(
				Post_Statuses::SCHEDULED,
				Post_Statuses::LIVE,
				array( self::ACTOR_SYSTEM ),
				array(),
				array( self::GUARD_STARTED )
			),
			new Campaign_Transition(
				Post_Statuses::LIVE,
				Post_Statuses::COMPLETE,
				array( self::ACTOR_SYSTEM ),
				array(),
				array( self::GUARD_ENDED )
			),

			// Pause and resume suppress delivery without destroying anything.
			new Campaign_Transition(
				Post_Statuses::SCHEDULED,
				Post_Statuses::PAUSED,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array(),
				array( self::EFFECT_SUPPRESS )
			),
			new Campaign_Transition(
				Post_Statuses::LIVE,
				Post_Statuses::PAUSED,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array(),
				array( self::EFFECT_SUPPRESS )
			),
			new Campaign_Transition(
				Post_Statuses::PAUSED,
				Post_Statuses::LIVE,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array(),
				array( self::EFFECT_RESUME )
			),

			// Termination. Anything with provider objects behind it has to
			// unpublish them, or the campaign stops being billed while its ads
			// keep rendering.
			new Campaign_Transition(
				Post_Statuses::SCHEDULED,
				Post_Statuses::CANCELLED,
				array( self::ACTOR_STAFF, self::ACTOR_ADVERTISER ),
				array( $delete ),
				array(),
				array( self::EFFECT_UNPUBLISH )
			),
			new Campaign_Transition(
				Post_Statuses::LIVE,
				Post_Statuses::CANCELLED,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array(),
				array( self::EFFECT_UNPUBLISH )
			),
			new Campaign_Transition(
				Post_Statuses::PAUSED,
				Post_Statuses::CANCELLED,
				array( self::ACTOR_STAFF ),
				array( Capabilities::REVIEW_CAMPAIGNS ),
				array(),
				array( self::EFFECT_UNPUBLISH )
			),
		);

		return $transitions;
	}

	/**
	 * The transition between two statuses, or null when there is none.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return Campaign_Transition|null
	 */
	public static function find( string $from, string $to ): ?Campaign_Transition {
		foreach ( self::all() as $transition ) {
			if ( $transition->from === $from && $transition->to === $to ) {
				return $transition;
			}
		}

		return null;
	}

	/**
	 * Whether a status change is legal at all, before considering who is asking.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public static function is_legal( string $from, string $to ): bool {
		return null !== self::find( $from, $to );
	}

	/**
	 * Every status reachable from one status.
	 *
	 * @param string $from Current status.
	 * @return array<int, string>
	 */
	public static function targets_from( string $from ): array {
		$targets = array();

		foreach ( self::all() as $transition ) {
			if ( $transition->from === $from ) {
				$targets[] = $transition->to;
			}
		}

		return $targets;
	}

	/**
	 * Every transition a given kind of actor may make from a status.
	 *
	 * @param string $from  Current status.
	 * @param string $actor One of the ACTOR_* constants.
	 * @return array<int, Campaign_Transition>
	 */
	public static function available_to( string $from, string $actor ): array {
		$available = array();

		foreach ( self::all() as $transition ) {
			if ( $transition->from === $from && $transition->allows_actor( $actor ) ) {
				$available[] = $transition;
			}
		}

		return $available;
	}

	/**
	 * Every status the clock can move a campaign out of.
	 *
	 * Derived from the table rather than listed, so adding a fifth system edge
	 * puts its source in the reconciler's sweep without a second edit. A list
	 * kept by hand is a list that goes stale silently — the campaigns in the
	 * status nobody remembered simply stop moving, and nothing reports it.
	 *
	 * @return array<int, string>
	 */
	public static function system_sources(): array {
		$sources = array();

		foreach ( self::all() as $transition ) {
			if ( $transition->allows_actor( self::ACTOR_SYSTEM ) && ! in_array( $transition->from, $sources, true ) ) {
				$sources[] = $transition->from;
			}
		}

		return $sources;
	}

	/**
	 * Whether a status has no outgoing transitions.
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	public static function is_terminal( string $status ): bool {
		return array() === self::targets_from( $status );
	}

	/**
	 * Every guard name the table uses.
	 *
	 * @return array<int, string>
	 */
	public static function guards(): array {
		return array(
			self::GUARD_VALIDATOR,
			self::GUARD_APPROVABLE,
			self::GUARD_UNCLAIMED,
			self::GUARD_REVIEW_NOTES,
			self::GUARD_STARTED,
			self::GUARD_NOT_STARTED,
			self::GUARD_ENDED,
		);
	}

	/**
	 * Every effect name the table uses.
	 *
	 * @return array<int, string>
	 */
	public static function effects(): array {
		return array(
			self::EFFECT_STAMP_SUBMITTED,
			self::EFFECT_CLAIM_REVIEWER,
			self::EFFECT_RELEASE_REVIEWER,
			self::EFFECT_INCREMENT_REVISION,
			self::EFFECT_PUBLISH,
			self::EFFECT_UNPUBLISH,
			self::EFFECT_SUPPRESS,
			self::EFFECT_RESUME,
		);
	}

	/**
	 * Every actor name the table uses.
	 *
	 * @return array<int, string>
	 */
	public static function actors(): array {
		return array( self::ACTOR_ADVERTISER, self::ACTOR_STAFF, self::ACTOR_SYSTEM );
	}
}
