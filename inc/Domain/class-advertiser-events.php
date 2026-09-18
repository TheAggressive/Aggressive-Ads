<?php
/**
 * Which audit events an advertiser may be shown, and as what.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;

/**
 * The audit trail is written for the publisher, and read by the advertiser.
 *
 * Those are different readers. The trail records refusals, internal notes,
 * mail failures and staff identities because the publisher has to be able to
 * reconstruct what happened; an advertiser watching their own campaign should
 * see what happened *to the campaign* and nothing about how the publisher
 * runs it.
 *
 * **An allowlist, not a denylist.** A new event added anywhere in the plugin
 * is invisible here until somebody decides it is safe, which is the failure
 * direction to choose: a missing line in an activity log costs an advertiser
 * nothing, and an unplanned one can disclose a reviewer's name, why somebody
 * was refused, or that a notification bounced.
 *
 * **Codes, not sentences.** This layer calls no WordPress function, so it
 * cannot translate; it names what happened and `Portal\Campaign_History_View_Data`
 * writes it in the reader's language.
 */
final class Advertiser_Events {

	public const SUBMITTED     = 'submitted';
	public const IN_REVIEW     = 'in_review';
	public const CHANGES       = 'changes_requested';
	public const APPROVED      = 'approved';
	public const SCHEDULED     = 'scheduled';
	public const LIVE          = 'live';
	public const PAUSED        = 'paused';
	public const RESUMED       = 'resumed';
	public const COMPLETE      = 'complete';
	public const CANCELLED     = 'cancelled';
	public const REJECTED      = 'rejected';
	public const WITHDRAWN     = 'withdrawn';
	public const AD_UPLOADED   = 'ad_uploaded';
	public const AD_REPLACED   = 'ad_replaced';
	public const AD_REMOVED    = 'ad_removed';
	public const LINK_CHANGED  = 'link_changed';
	public const CAMPAIGN_MADE = 'campaign_made';

	/**
	 * Transitions, by the state they arrive at.
	 *
	 * The state moved *to* is what an advertiser recognises. Where the same
	 * arrival means two different things — draft is reached both by a
	 * reviewer asking for changes and by the advertiser withdrawing — the
	 * state left behind decides which.
	 */
	private const ARRIVALS = array(
		Post_Statuses::SUBMITTED => self::SUBMITTED,
		Post_Statuses::REVIEW    => self::IN_REVIEW,
		Post_Statuses::CHANGES   => self::CHANGES,
		Post_Statuses::APPROVED  => self::APPROVED,
		Post_Statuses::SCHEDULED => self::SCHEDULED,
		Post_Statuses::LIVE      => self::LIVE,
		Post_Statuses::PAUSED    => self::PAUSED,
		Post_Statuses::COMPLETE  => self::COMPLETE,
		Post_Statuses::CANCELLED => self::CANCELLED,
		Post_Statuses::REJECTED  => self::REJECTED,
	);

	/**
	 * Events that are not transitions, by audit event name.
	 */
	private const EVENTS = array(
		'creative.uploaded'            => self::AD_UPLOADED,
		'creative.artwork_replaced'    => self::AD_REPLACED,
		'creative.removed'             => self::AD_REMOVED,
		'creative.destination_changed' => self::LINK_CHANGED,
		'campaign.copied'              => self::CAMPAIGN_MADE,
	);

	/**
	 * What to show an advertiser for one audit row, or '' to show nothing.
	 *
	 * @param string $event      Audit event name.
	 * @param string $outcome    Audit outcome.
	 * @param string $from_state Status the campaign left, for a transition.
	 * @param string $to_state   Status the campaign reached, for a transition.
	 * @return string One of the constants above, or ''.
	 */
	public static function code( string $event, string $outcome, string $from_state = '', string $to_state = '' ): string {
		/*
		 * A refusal is never the advertiser's news. "Denied" on their own
		 * screen reads as a decision about their campaign, when it records
		 * somebody — often staff — being stopped by a rule.
		 */
		if ( Audit_Event::OUTCOME_OK !== $outcome ) {
			return '';
		}

		if ( 'campaign.transitioned' === $event ) {
			return self::arrival( $from_state, $to_state );
		}

		return self::EVENTS[ $event ] ?? '';
	}

	/**
	 * What reaching a state means to the advertiser who owns the campaign.
	 *
	 * @param string $from_state Status left behind.
	 * @param string $to_state   Status reached.
	 * @return string
	 */
	private static function arrival( string $from_state, string $to_state ): string {
		if ( Post_Statuses::DRAFT === $to_state ) {
			// Back to draft from review is the review team asking for changes;
			// from submitted it is the advertiser taking it back.
			return Post_Statuses::REVIEW === $from_state ? self::CHANGES : self::WITHDRAWN;
		}

		if ( Post_Statuses::LIVE === $to_state && Post_Statuses::PAUSED === $from_state ) {
			return self::RESUMED;
		}

		return self::ARRIVALS[ $to_state ] ?? '';
	}

	/**
	 * The stage a dated timeline should record this arrival against.
	 *
	 * Only the states the timeline draws, so a pause or a withdrawal does not
	 * date a stage it is not part of.
	 *
	 * @param string $to_state Status reached.
	 * @return string A `Post_Statuses` value, or ''.
	 */
	public static function stage( string $to_state ): string {
		$stages = array(
			Post_Statuses::SUBMITTED,
			Post_Statuses::REVIEW,
			Post_Statuses::APPROVED,
			Post_Statuses::SCHEDULED,
			Post_Statuses::LIVE,
			Post_Statuses::COMPLETE,
		);

		return in_array( $to_state, $stages, true ) ? $to_state : '';
	}
}
