<?php
/**
 * What an advertiser has asked for on a running campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

/**
 * Proposed edits, requested actions, and the counter that keys their notices.
 *
 * Split from `Campaign_Repository`, which had reached its size limit holding
 * two kinds of state: what a campaign *is* — its dates, package, status and
 * title — and what an advertiser has *asked* staff to change about one that is
 * already running. The second is a lifecycle of its own, with its own writers
 * (`Campaign_Change_Manager`) and readers (the review screen and the request
 * mailer), so it moved as one unit rather than a few methods at a time.
 *
 * `Campaign_Repository` keeps the public methods as delegations, so no caller
 * changed, and keeps the meta key constants, which callers outside this class
 * reference directly.
 */
final class Campaign_Request_Repository {

	/**
	 * The change set an advertiser has proposed for a running campaign.
	 *
	 * Stored as meta on the campaign rather than as a shadow post, unlike a
	 * creative replacement. That asymmetry is deliberate: a replacement has
	 * *bytes* to hold, validate and stream before anyone approves it, and a
	 * post is what owns bytes here. A field change is a handful of scalars, and
	 * giving it a post would buy a second thing to keep in step with the
	 * campaign for no capability in return.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed> Empty when nothing is pending.
	 */
	public function pending_edits( int $campaign_id ): array {
		$stored = get_post_meta( $campaign_id, Campaign_Repository::META_PENDING_EDITS, true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether a change is waiting for a decision.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function has_pending_edits( int $campaign_id ): bool {
		return array() !== $this->pending_edits( $campaign_id );
	}

	/**
	 * Records a proposed change, replacing any previous one.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       Validated change set.
	 * @param int                  $user_id     Proposing user.
	 * @param bool                 $submitted   Whether it has been sent for review.
	 * @return bool
	 */
	public function set_pending_edits( int $campaign_id, array $edits, int $user_id, bool $submitted = false ): bool {
		if ( array() === $edits ) {
			return $this->clear_pending_edits( $campaign_id );
		}

		/*
		 * **Who proposed this and when are not stored here.** They were, in
		 * `_aggr_pending_edits_at` and `_aggr_pending_edits_by`, and nothing
		 * ever read either — while the audit row written on the same line of
		 * `Campaign_Change_Manager` records `actor_user_id` and
		 * `created_at_ts` for the identical event. Two copies of one fact, one
		 * of them durable, queryable and covered by retention, and the other a
		 * pair of post meta rows with no reader. The audit log is the answer to
		 * "who asked for this change"; `$user_id` stays a parameter because the
		 * caller that logs it takes it from here.
		 */
		update_post_meta( $campaign_id, Campaign_Repository::META_PENDING_EDITS, $edits );
		update_post_meta( $campaign_id, Campaign_Repository::META_PENDING_EDITS_SENT, $submitted ? 1 : 0 );

		// Read back rather than trusting update_post_meta()'s return, which is
		// false both when the write failed and when the value was unchanged.
		return $this->pending_edits( $campaign_id ) === $edits;
	}

	/**
	 * Drops a proposed change.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function clear_pending_edits( int $campaign_id ): bool {
		delete_post_meta( $campaign_id, Campaign_Repository::META_PENDING_EDITS );
		delete_post_meta( $campaign_id, Campaign_Repository::META_PENDING_EDITS_SENT );

		return array() === $this->pending_edits( $campaign_id );
	}

	/**
	 * Whether the pending change has been sent for review.
	 *
	 * A proposal being assembled across wizard steps is not one a reviewer
	 * should see. Without this flag the review queue would show half-finished
	 * edits and staff would approve a change the advertiser had not finished
	 * making.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function pending_edits_submitted( int $campaign_id ): bool {
		return 1 === (int) get_post_meta( $campaign_id, Campaign_Repository::META_PENDING_EDITS_SENT, true );
	}

	/**
	 * An advertiser's request for a staff-only action on a running campaign.
	 *
	 * Kept apart from pending edits even though both are "the advertiser wants
	 * something": an edit proposes new *values* and is applied on approval,
	 * while this proposes a *transition* that staff perform themselves through
	 * the review screen. Sharing storage would mean one approval path deciding
	 * two different kinds of thing.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array{action: string, reason: string, at: int, by: int}|array{}
	 */
	public function action_request( int $campaign_id ): array {
		$stored = get_post_meta( $campaign_id, Campaign_Repository::META_ACTION_REQUEST, true );

		if ( ! is_array( $stored ) || ! isset( $stored['action'] ) ) {
			return array();
		}

		return array(
			'action' => (string) $stored['action'],
			'reason' => (string) ( $stored['reason'] ?? '' ),
			'at'     => (int) ( $stored['at'] ?? 0 ),
			'by'     => (int) ( $stored['by'] ?? 0 ),
		);
	}

	/**
	 * Records a requested action, replacing any previous one.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $action      Target status.
	 * @param string $reason      Advertiser's explanation.
	 * @param int    $user_id     Requesting user.
	 * @return bool
	 */
	public function set_action_request( int $campaign_id, string $action, string $reason, int $user_id ): bool {
		update_post_meta(
			$campaign_id,
			Campaign_Repository::META_ACTION_REQUEST,
			array(
				'action' => $action,
				'reason' => $reason,
				'at'     => time(),
				'by'     => $user_id,
			)
		);

		return array() !== $this->action_request( $campaign_id );
	}

	/**
	 * Drops a requested action.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function clear_action_request( int $campaign_id ): bool {
		delete_post_meta( $campaign_id, Campaign_Repository::META_ACTION_REQUEST );

		return array() === $this->action_request( $campaign_id );
	}

	/**
	 * How many times an advertiser has asked staff for something on this campaign.
	 *
	 * Deliberately not `revision()`. That counter tracks *submissions of the
	 * campaign itself* and only moves on a transition, whereas a request is a
	 * meta write against a campaign that stays live throughout. Keying request
	 * notification receipts on `revision()` would leave the number unchanged
	 * across a withdraw and a resubmit, so the second ask would be suppressed as
	 * a duplicate and nobody would be told about it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function request_revision( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, Campaign_Repository::META_REQUEST_REVISION, true );
	}

	/**
	 * Bumps the request counter and returns the new value.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function increment_request_revision( int $campaign_id ): int {
		$next = $this->request_revision( $campaign_id ) + 1;

		update_post_meta( $campaign_id, Campaign_Repository::META_REQUEST_REVISION, $next );

		return $next;
	}
}
