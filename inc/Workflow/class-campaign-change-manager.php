<?php
/**
 * Advertiser-proposed changes to a campaign that is already running.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Live_Edit_Rules;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Notification\Request_Mailer;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Throwable;
use WP_Error;

/**
 * Stage a change, keep serving the approved version, reconcile on approval.
 *
 * The shape is taken from `Creative_Change_Manager`, and for the same reason:
 * a campaign that is scheduled, live or paused was approved by staff as a
 * specific thing, and letting an advertiser rewrite it in place would mean the
 * approval no longer describes what is being served. So nothing an advertiser
 * submits here touches the campaign. It sits beside it until somebody with
 * `aggr_review_campaigns` accepts or refuses it.
 *
 * What may be proposed at all is site policy, held in Settings. This class
 * asks; it does not decide, and it never trusts the request to say what was
 * allowed.
 */
final class Campaign_Change_Manager implements Service {

	public const MAX_REVIEW_NOTES_LENGTH = 2000;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Creative_Repository  $creatives  Creative persistence.
	 * @param Revision_Policy      $revisions  Immutability policy for approved creatives.
	 * @param Placement_Repository $placements Placement names for the change summary.
	 * @param Settings             $settings   Site policy.
	 * @param Fill_Cache           $fill       Delivery cache.
	 * @param Rate_Limiter         $limiter    Abuse bounding.
	 * @param Audit_Repository     $audit      Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Revision_Policy $revisions,
		private readonly Placement_Repository $placements,
		private readonly Settings $settings,
		private readonly Fill_Cache $fill,
		private readonly Rate_Limiter $limiter,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Listens for status changes so a request cannot outlive its subject.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'aggr_campaign_transitioned', array( $this, 'clear_request_on_transition' ) );
	}

	/**
	 * Drops a request once the campaign has moved.
	 *
	 * Whatever the advertiser asked for, the status it asked about is gone —
	 * leaving the request in place would show staff a pending ask against a
	 * campaign that has already changed, and show the advertiser a request that
	 * will never be answered.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return void
	 */
	public function clear_request_on_transition( int $campaign_id ): void {
		if ( array() !== $this->campaigns->action_request( $campaign_id ) ) {
			$this->campaigns->clear_action_request( $campaign_id );
		}

		/*
		 * Staged edits go the same way, and for the same reason.
		 *
		 * This used to clear only the action request, which left a submitted
		 * edit attached to a campaign that had since been cancelled or
		 * completed. Nothing downstream re-checked the status, so a reviewer
		 * working the queue could approve a title and a date window onto a
		 * finished campaign — rewriting it, busting the fill cache and auditing
		 * the change as applied.
		 */
		if ( array() === $this->submitted_edits( $campaign_id ) ) {
			return;
		}

		if ( in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true ) ) {
			return;
		}

		$this->campaigns->clear_pending_edits( $campaign_id );
		$this->log(
			'campaign.changes_dropped',
			$campaign_id,
			array(),
			'Pending campaign changes dropped: the campaign is no longer running.',
			Audit_Event::OUTCOME_FAILED
		);
	}

	/**
	 * The statuses this workflow applies to.
	 *
	 * Exactly `Post_Statuses::published()` — the campaigns that occupy the live
	 * set. A draft needs no proposal workflow because it can simply be edited,
	 * and anything terminal is finished.
	 *
	 * @return array<int, string>
	 */
	public static function editable_statuses(): array {
		return Post_Statuses::published();
	}

	/**
	 * The fields an advertiser may propose on this site right now.
	 *
	 * @return array<int, string>
	 */
	public function allowed_fields(): array {
		return Live_Edit_Rules::allowed_fields( $this->settings->live_edit_fields() );
	}

	/**
	 * Whether this campaign can currently take a proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function accepts_changes( int $campaign_id ): bool {
		return array() !== $this->settings->live_edit_fields()
			&& in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true );
	}

	/**
	 * Stages a change without touching the running campaign.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $proposed    Advertiser input.
	 * @return array<string, mixed>|WP_Error The stored change set.
	 */
	public function request( int $campaign_id, array $proposed ): array|WP_Error {
		// Retained as the one-shot form: stage the fields, then send them. The
		// stepped portal flow calls the two halves separately.
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$allowed = $this->settings->live_edit_fields();

		if ( array() === $allowed ) {
			return $this->error( 'aggr_live_edits_disabled', __( 'Changes to running campaigns are not accepted on this site.', 'aggressive-ads' ), 404 );
		}

		if ( ! in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true ) ) {
			return $this->error( 'aggr_campaign_not_running', __( 'This campaign is not running, so there is nothing to change.', 'aggressive-ads' ), 409 );
		}

		$limited = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( $this->campaigns->pending_edits_submitted( $campaign_id ) ) {
			return $this->error( 'aggr_edits_pending', __( 'This campaign already has changes waiting for review. Withdraw them first.', 'aggressive-ads' ), 409 );
		}

		$diff = Live_Edit_Rules::diff( $allowed, $this->current( $campaign_id ), $proposed );

		$validation = Live_Edit_Rules::validate( $diff, $this->current( $campaign_id ), time() );

		if ( ! $validation->is_valid() ) {
			return new WP_Error(
				'aggr_live_edit_invalid',
				__( 'These changes cannot be requested yet.', 'aggressive-ads' ),
				array(
					'status'   => 422,
					'problems' => $validation->problems(),
				)
			);
		}

		if ( ! $this->campaigns->set_pending_edits( $campaign_id, $diff, get_current_user_id() ) ) {
			return $this->error( 'aggr_edits_not_saved', __( 'The requested changes could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->log( 'campaign.changes_requested', $campaign_id, $diff, 'Campaign changes requested.' );

		return $diff;
	}

	/**
	 * The transitions an advertiser may ask staff to perform from here.
	 *
	 * Derived: everything staff can drive from this status, minus everything
	 * the advertiser can already drive themselves. So it is never possible to
	 * request something you could just do, and a new staff edge added to
	 * Transition_Table becomes requestable without touching this method.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{action: string, label: string}>
	 */
	public function requestable_actions( int $campaign_id ): array {
		$status = $this->campaigns->status( $campaign_id );

		if ( ! in_array( $status, self::editable_statuses(), true ) ) {
			return array();
		}

		$own = array();

		foreach ( Transition_Table::available_to( $status, Transition_Table::ACTOR_ADVERTISER ) as $transition ) {
			$own[ $transition->to ] = true;
		}

		$actions = array();

		foreach ( Transition_Table::available_to( $status, Transition_Table::ACTOR_STAFF ) as $transition ) {
			if ( isset( $own[ $transition->to ] ) || $transition->is_system() ) {
				continue;
			}

			$actions[] = array(
				'action' => $transition->to,
				'label'  => self::request_label( $transition->to ),
			);
		}

		return $actions;
	}

	/**
	 * Asks staff to perform one of those transitions.
	 *
	 * Records a request; it never performs the transition. Staff decide, and
	 * they decide with the buttons the review screen already derives from
	 * Transition_Table — so this adds a message, not a second way to change a
	 * campaign's status.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $action      Requested target status.
	 * @param string $reason      Advertiser's explanation.
	 * @return true|WP_Error
	 */
	public function request_action( int $campaign_id, string $action, string $reason ): bool|WP_Error {
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$allowed = array();

		foreach ( $this->requestable_actions( $campaign_id ) as $candidate ) {
			$allowed[] = $candidate['action'];
		}

		if ( ! in_array( $action, $allowed, true ) ) {
			return $this->error( 'aggr_action_not_requestable', __( 'That cannot be requested for this campaign.', 'aggressive-ads' ), 422 );
		}

		$limited = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( array() !== $this->campaigns->action_request( $campaign_id ) ) {
			return $this->error( 'aggr_action_already_requested', __( 'You have already asked the review team about this campaign.', 'aggressive-ads' ), 409 );
		}

		$reason = trim( $reason );

		if ( '' === $reason ) {
			return $this->error( 'aggr_action_reason_required', __( 'Tell the review team why.', 'aggressive-ads' ), 422 );
		}

		if ( strlen( $reason ) > self::MAX_REVIEW_NOTES_LENGTH ) {
			return $this->error( 'aggr_action_reason_long', __( 'That explanation is too long.', 'aggressive-ads' ), 422 );
		}

		if ( ! $this->campaigns->set_action_request( $campaign_id, $action, $reason, get_current_user_id() ) ) {
			return $this->error( 'aggr_action_not_saved', __( 'The request could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->log( 'campaign.action_requested', $campaign_id, array( $action => $reason ), 'Advertiser requested a campaign action.' );
		$this->notify_request( $campaign_id, $action );

		return true;
	}

	/**
	 * Lets the advertiser take back their request.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function withdraw_action( int $campaign_id ): bool|WP_Error {
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		if ( array() === $this->campaigns->action_request( $campaign_id ) ) {
			return $this->error( 'aggr_no_action_request', __( 'There is no request to withdraw.', 'aggressive-ads' ), 404 );
		}

		$this->campaigns->clear_action_request( $campaign_id );
		$this->log( 'campaign.action_request_withdrawn', $campaign_id, array(), 'Advertiser withdrew a campaign action request.' );

		return true;
	}

	/**
	 * Clears a request staff have dealt with, one way or the other.
	 *
	 * Called after a staff transition so a request cannot outlive the thing it
	 * asked for, and separately when staff decline it.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Advertiser-facing explanation when declining.
	 * @return true|WP_Error
	 */
	public function resolve_action( int $campaign_id, string $notes = '' ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to decide campaign requests.', 'aggressive-ads' ), 403 );
		}

		if ( array() === $this->campaigns->action_request( $campaign_id ) ) {
			return $this->error( 'aggr_no_action_request', __( 'This campaign has no request waiting.', 'aggressive-ads' ), 404 );
		}

		$notes = trim( $notes );

		if ( '' !== $notes ) {
			$this->campaigns->set_review_notes( $campaign_id, $notes );
		}

		$this->campaigns->clear_action_request( $campaign_id );
		$this->log( 'campaign.action_request_resolved', $campaign_id, array(), 'Campaign action request resolved by staff.' );

		return true;
	}

	/**
	 * Advertiser-facing name for a requested transition.
	 *
	 * @param string $status Target status.
	 */
	public static function request_label( string $status ): string {
		switch ( $status ) {
			case Post_Statuses::PAUSED:
				return __( 'Pause this campaign', 'aggressive-ads' );
			case Post_Statuses::LIVE:
				return __( 'Restart this campaign', 'aggressive-ads' );
			case Post_Statuses::CANCELLED:
				return __( 'Cancel this campaign', 'aggressive-ads' );
			case Post_Statuses::COMPLETE:
				return __( 'End this campaign now', 'aggressive-ads' );
		}

		return $status;
	}

	/**
	 * Merges one wizard step into the proposal without sending it for review.
	 *
	 * Each step posts only its own fields, so the merge is over the *stored*
	 * proposal rather than a whole-campaign diff: saving the schedule step must
	 * not discard a name change made two steps earlier. Fields absent from this
	 * step are therefore left alone rather than treated as cleared.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $proposed    One step's fields.
	 * @return array<string, mixed>|WP_Error The accumulated proposal.
	 */
	public function stage( int $campaign_id, array $proposed ): array|WP_Error {
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$allowed = $this->settings->live_edit_fields();

		if ( array() === $allowed ) {
			return $this->error( 'aggr_live_edits_disabled', __( 'Changes to running campaigns are not accepted on this site.', 'aggressive-ads' ), 404 );
		}

		if ( ! in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true ) ) {
			return $this->error( 'aggr_campaign_not_running', __( 'This campaign is not running, so there is nothing to change.', 'aggressive-ads' ), 409 );
		}

		if ( $this->campaigns->pending_edits_submitted( $campaign_id ) ) {
			return $this->error( 'aggr_edits_pending', __( 'These changes are already with the review team. Withdraw them to keep editing.', 'aggressive-ads' ), 409 );
		}

		$current = $this->current( $campaign_id );
		$step    = Live_Edit_Rules::diff( $allowed, $current, $proposed );
		$merged  = $this->campaigns->pending_edits( $campaign_id );

		// A field the advertiser has put back to its original value is no
		// longer a change, so it leaves the proposal rather than lingering as
		// a no-op row a reviewer has to read and dismiss.
		foreach ( Live_Edit_Rules::allowed_fields( $allowed ) as $field ) {
			if ( ! array_key_exists( $field, $proposed ) ) {
				continue;
			}

			unset( $merged[ $field ] );

			if ( array_key_exists( $field, $step ) ) {
				$merged[ $field ] = $step[ $field ];
			}
		}

		/*
		 * Judged at each step, not only at submission. Telling somebody their
		 * destination URL is malformed four screens after they typed it is a
		 * worse version of not telling them.
		 *
		 * "Nothing changed" is excluded: clearing a field back to its original
		 * value is a legitimate thing to do on a step, and it is only a problem
		 * at submission time.
		 */
		$validation = Live_Edit_Rules::validate( $merged, $current, time() );

		if ( ! $validation->is_valid() && ! $validation->has( Live_Edit_Rules::ERROR_NOTHING_CHANGED ) ) {
			return new WP_Error(
				'aggr_live_edit_invalid',
				__( 'These changes cannot be saved yet.', 'aggressive-ads' ),
				array(
					'status'   => 422,
					'problems' => $validation->problems(),
				)
			);
		}

		if ( ! $this->campaigns->set_pending_edits( $campaign_id, $merged, get_current_user_id() ) ) {
			return $this->error( 'aggr_edits_not_saved', __( 'The changes could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		return $merged;
	}

	/**
	 * Sends the accumulated proposal to the review team.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function submit( int $campaign_id ): bool|WP_Error {
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$limited = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( $this->campaigns->pending_edits_submitted( $campaign_id ) ) {
			return $this->error( 'aggr_edits_pending', __( 'These changes are already with the review team.', 'aggressive-ads' ), 409 );
		}

		$edits      = $this->campaigns->pending_edits( $campaign_id );
		$validation = Live_Edit_Rules::validate( $edits, $this->current( $campaign_id ), time() );

		if ( ! $validation->is_valid() ) {
			return new WP_Error(
				'aggr_live_edit_invalid',
				__( 'These changes cannot be submitted yet.', 'aggressive-ads' ),
				array(
					'status'   => 422,
					'problems' => $validation->problems(),
				)
			);
		}

		if ( ! $this->campaigns->set_pending_edits( $campaign_id, $edits, get_current_user_id(), true ) ) {
			return $this->error( 'aggr_edits_not_saved', __( 'The changes could not be submitted. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->log( 'campaign.changes_requested', $campaign_id, $edits, 'Campaign changes submitted for review.' );
		$this->notify_request( $campaign_id, Request_Mailer::KIND_EDITS );

		return true;
	}

	/**
	 * Tells the review team something is waiting, after the write has committed.
	 *
	 * The counter is bumped here rather than in the mailer, and that is the
	 * whole point of it: the mailer reads it, so a cron retry re-reads the same
	 * number and reserves the same receipt. Bumping it on the retry path would
	 * make every attempt a new notification and mail the review team on every
	 * tick.
	 *
	 * Failures are swallowed for the reason `Campaign_State_Machine::notify()`
	 * swallows them — the advertiser's request is already saved, and returning
	 * an error now would tell them it was not.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $kind        `edits`, or the requested target status.
	 * @return void
	 */
	private function notify_request( int $campaign_id, string $kind ): void {
		$this->campaigns->increment_request_revision( $campaign_id );

		try {
			// Spelled out rather than referenced through Request_Mailer, as
			// Campaign_State_Machine spells out its own notify hook: a hook name
			// that only exists as a constant is a hook nobody can grep for.
			do_action( 'aggr_notify_advertiser_request', $campaign_id, $kind );
		} catch ( Throwable $exception ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'campaign.notification_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					org_id: $this->campaigns->org_id( $campaign_id ),
					message: $exception->getMessage(),
					context: array( 'kind' => $kind )
				)
			);
		}
	}

	/**
	 * Applies a pending change to the running campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function approve( int $campaign_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) || ! current_user_can( Capabilities::PUBLISH_TO_ADSANITY ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to approve campaign changes.', 'aggressive-ads' ), 403 );
		}

		$edits = $this->submitted_edits( $campaign_id );

		if ( array() === $edits ) {
			return $this->error( 'aggr_no_pending_edits', __( 'This campaign has no changes waiting for review.', 'aggressive-ads' ), 404 );
		}

		/*
		 * The campaign must still be one this workflow applies to.
		 *
		 * The transition hook clears stale edits, but a hook is a promise about
		 * a code path rather than about state: a status written by a migration,
		 * a direct repository call, or a transition that fired before this
		 * service was initialized leaves edits behind with nothing to catch
		 * them. This is the check that makes approving onto a finished campaign
		 * impossible rather than merely unlikely.
		 */
		if ( ! in_array( $this->campaigns->status( $campaign_id ), self::editable_statuses(), true ) ) {
			return $this->error( 'aggr_campaign_not_running', __( 'This campaign is no longer running, so its changes cannot be approved.', 'aggressive-ads' ), 409 );
		}

		// Re-judged at approval, not merely at request. A proposal can sit in
		// the queue past its own end date, and approving it then would write a
		// window that was valid on Tuesday and is nonsense today.
		$validation = Live_Edit_Rules::validate( $edits, $this->current( $campaign_id ), time() );

		if ( ! $validation->is_valid() ) {
			$this->log( 'campaign.changes_stale', $campaign_id, $edits, 'Pending campaign changes were no longer valid at approval.', Audit_Event::OUTCOME_FAILED );

			return new WP_Error(
				'aggr_live_edit_stale',
				__( 'These changes are no longer valid and cannot be approved. Ask the advertiser to request them again.', 'aggressive-ads' ),
				array(
					'status'   => 409,
					'problems' => $validation->problems(),
				)
			);
		}

		$applied = $this->apply( $campaign_id, $edits );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$this->campaigns->clear_pending_edits( $campaign_id );

		// The campaign is in the live set, so anything the fill payload quotes
		// — the destination above all — is stale the instant this returns.
		// Busting here rather than on a schedule is what stops an approved
		// destination change riding out a CDN TTL pointing at the old URL.
		$this->fill->bust_campaign( $campaign_id );

		$this->log( 'campaign.changes_approved', $campaign_id, $edits, 'Campaign changes approved and applied.' );

		return true;
	}

	/**
	 * Refuses a pending change, with a reason the advertiser will read.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Advertiser-facing reason.
	 * @return true|WP_Error
	 */
	public function reject( int $campaign_id, string $notes ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to decide campaign changes.', 'aggressive-ads' ), 403 );
		}

		$notes = trim( $notes );

		if ( '' === $notes ) {
			return $this->error( 'aggr_review_notes_required', __( 'Tell the advertiser why the changes were not accepted.', 'aggressive-ads' ), 422 );
		}

		if ( strlen( $notes ) > self::MAX_REVIEW_NOTES_LENGTH ) {
			return $this->error( 'aggr_review_notes_long', __( 'That explanation is too long.', 'aggressive-ads' ), 422 );
		}

		$edits = $this->submitted_edits( $campaign_id );

		if ( array() === $edits ) {
			return $this->error( 'aggr_no_pending_edits', __( 'This campaign has no changes waiting for review.', 'aggressive-ads' ), 404 );
		}

		$this->campaigns->set_review_notes( $campaign_id, $notes );
		$this->campaigns->clear_pending_edits( $campaign_id );

		$this->log( 'campaign.changes_rejected', $campaign_id, $edits, 'Campaign changes rejected.' );

		return true;
	}

	/**
	 * Lets the advertiser take back their own proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function withdraw( int $campaign_id ): bool|WP_Error {
		$authorized = $this->authorize( $campaign_id, Capabilities::SUBMIT_CAMPAIGN );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$edits = $this->campaigns->pending_edits( $campaign_id );

		if ( array() === $edits ) {
			return $this->error( 'aggr_no_pending_edits', __( 'There are no requested changes to withdraw.', 'aggressive-ads' ), 404 );
		}

		$this->campaigns->clear_pending_edits( $campaign_id );
		$this->log( 'campaign.changes_withdrawn', $campaign_id, $edits, 'Campaign changes withdrawn by the advertiser.' );

		return true;
	}

	/**
	 * A pending change rendered as before/after rows.
	 *
	 * One presenter, used by both the advertiser's screen and the reviewer's,
	 * because a reviewer approving a change described differently from the one
	 * the advertiser requested is the failure this whole workflow exists to
	 * avoid.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{field: string, label: string, from: string, to: string}>
	 */
	public function pending_summary( int $campaign_id ): array {
		return $this->summarize( $this->submitted_edits( $campaign_id ), $campaign_id );
	}

	/**
	 * The change set an advertiser is still assembling, submitted or not.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{field: string, label: string, from: string, to: string}>
	 */
	public function draft_summary( int $campaign_id ): array {
		return $this->summarize( $this->campaigns->pending_edits( $campaign_id ), $campaign_id );
	}

	/**
	 * A proposal only once the advertiser has finished it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	private function submitted_edits( int $campaign_id ): array {
		return $this->campaigns->pending_edits_submitted( $campaign_id )
			? $this->campaigns->pending_edits( $campaign_id )
			: array();
	}

	/**
	 * Renders one change set as before/after rows.
	 *
	 * @param array<string, mixed> $edits       Change set.
	 * @param int                  $campaign_id Campaign post id.
	 * @return array<int, array{field: string, label: string, from: string, to: string}>
	 */
	private function summarize( array $edits, int $campaign_id ): array {
		if ( array() === $edits ) {
			return array();
		}

		$current = $this->current( $campaign_id );
		$rows    = array();

		foreach ( $edits as $field => $value ) {
			$rows[] = array(
				'field' => (string) $field,
				'label' => self::field_label( (string) $field ),
				'from'  => $this->render( (string) $field, $current[ $field ] ?? null ),
				'to'    => $this->render( (string) $field, $value ),
			);
		}

		return $rows;
	}

	/**
	 * Human label for one proposal field.
	 *
	 * @param string $field Field name.
	 */
	public static function field_label( string $field ): string {
		switch ( $field ) {
			case 'title':
				return __( 'Campaign name', 'aggressive-ads' );
			case 'advertiser_notes':
				return __( 'Advertiser notes', 'aggressive-ads' );
			case 'start_ts':
				return __( 'Start date', 'aggressive-ads' );
			case 'end_ts':
				return __( 'End date', 'aggressive-ads' );
			case 'click_urls':
				return __( 'Destination', 'aggressive-ads' );
			case 'placement_ids':
				return __( 'Placements', 'aggressive-ads' );
		}

		return $field;
	}

	/**
	 * Displayable form of one field's value.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 */
	private function render( string $field, mixed $value ): string {
		if ( 'start_ts' === $field || 'end_ts' === $field ) {
			$timestamp = (int) $value;

			if ( $timestamp <= 0 ) {
				return __( 'Open-ended', 'aggressive-ads' );
			}

			// wp_date() returns false when the timezone cannot be resolved.
			// Falling back to the raw ISO date keeps a reviewer looking at a
			// date rather than at an empty cell.
			$formatted = wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp );

			return false === $formatted ? gmdate( 'Y-m-d', $timestamp ) : $formatted;
		}

		if ( 'placement_ids' === $field ) {
			$names = array();

			foreach ( is_array( $value ) ? $value : array() as $placement_id ) {
				$names[] = $this->placements->name( (int) $placement_id );
			}

			return implode( ', ', array_filter( $names ) );
		}

		if ( 'click_urls' === $field ) {
			return implode( ', ', array_map( 'strval', is_array( $value ) ? $value : array() ) );
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The campaign as it stands, in the shape Live_Edit_Rules compares against.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, mixed>
	 */
	public function current( int $campaign_id ): array {
		$click_urls = array();

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$click_urls[ (int) $creative['id'] ] = (string) $creative['click_url'];
		}

		return array(
			'title'            => $this->campaigns->title( $campaign_id ),
			'advertiser_notes' => $this->campaigns->advertiser_notes( $campaign_id ),
			'start_ts'         => $this->campaigns->start_ts( $campaign_id ),
			'end_ts'           => $this->campaigns->end_ts( $campaign_id ),
			'placement_ids'    => $this->campaigns->placement_ids( $campaign_id ),
			'click_urls'       => $click_urls,
		);
	}

	/**
	 * Writes an approved change set.
	 *
	 * Destinations are written through Creative_Repository because that is
	 * where creative meta lives; everything else goes through the campaign's
	 * own persistence, which already rolls back a partial meta write.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       Validated change set.
	 * @return true|WP_Error
	 */
	private function apply( int $campaign_id, array $edits ) {
		$fields = array();

		foreach ( array( 'title', 'advertiser_notes', 'start_ts', 'end_ts', 'placement_ids' ) as $field ) {
			if ( array_key_exists( $field, $edits ) ) {
				$fields[ $field ] = $edits[ $field ];
			}
		}

		if ( array() !== $fields ) {
			$written = $this->campaigns->update_draft( $campaign_id, $fields );

			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		if ( ! array_key_exists( 'click_urls', $edits ) || ! is_array( $edits['click_urls'] ) ) {
			return true;
		}

		// Scoped to this campaign's own creatives before writing. The ids came
		// from a stored proposal, and a stored proposal is still input.
		$owned = array();

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$owned[ (int) $creative['id'] ] = true;
		}

		foreach ( $edits['click_urls'] as $creative_id => $url ) {
			$id = (int) $creative_id;

			if ( isset( $owned[ $id ] ) && is_string( $url ) ) {
				/*
				 * A frozen creative is revised, not rewritten.
				 *
				 * This used to call `set_click_url()`, which repointed the
				 * destination of an ad a publisher had already approved and
				 * that was already serving — the exact mutation P2's
				 * immutability rule exists to stop. The policy decides which it
				 * is; nothing here re-derives that condition.
				 */
				$this->revisions->apply_text_change( $id, $url );
			}
		}

		return true;
	}

	/**
	 * Capability plus object authorization for the advertiser side.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $capability  Required capability.
	 * @return true|WP_Error
	 */
	private function authorize( int $campaign_id, string $capability ) {
		if ( ! current_user_can( $capability ) || ! current_user_can( 'edit_post', $campaign_id ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to change that campaign.', 'aggressive-ads' ), 403 );
		}

		return true;
	}

	/**
	 * Audit one decision, recording what was actually proposed.
	 *
	 * The field names go into the context and the values do not. A rejected
	 * destination URL is exactly the kind of thing worth not copying into a
	 * second table that outlives the proposal.
	 *
	 * @param string               $event       Audit event name.
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       Change set.
	 * @param string               $message     Human-readable summary.
	 * @param string               $outcome     Audit outcome.
	 * @return void
	 */
	private function log( string $event, int $campaign_id, array $edits, string $message, string $outcome = Audit_Event::OUTCOME_OK ): void {
		$this->audit->insert(
			new Audit_Event(
				event: $event,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: $message,
				outcome: $outcome,
				context: array( 'fields' => array_keys( $edits ) ),
				actor_user_id: get_current_user_id()
			)
		);
	}

	/**
	 * Consistent REST/form workflow error.
	 *
	 * @param string $code    Stable code.
	 * @param string $message Localized message.
	 * @param int    $status  HTTP status.
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
