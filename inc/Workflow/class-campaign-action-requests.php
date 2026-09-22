<?php
/**
 * Asking staff to pause, restart or cancel a running campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use WP_Error;

/**
 * Requests for a status change an advertiser cannot make themselves.
 *
 * Moved out of `Campaign_Change_Manager`, which was approaching the
 * file-length gate holding two lifecycles: proposed field changes, and these.
 * They share a campaign and a review team and nothing else — different
 * storage, different callers, different decisions — so they are two classes.
 */
final class Campaign_Action_Requests {

	/**
	 * The longest explanation an advertiser may give.
	 */
	public const MAX_REASON_LENGTH = 2000;

	/**
	 * Failures of this form, as redirect codes.
	 *
	 * The sentence the workflow built does not survive `admin-post.php`.
	 * These are the codes this dialog's own handler can redirect with, so
	 * the screen can put the sentence back inside the dialog. A rate limit
	 * raised by a different form shares `aggr_rate_limited`; the dialog
	 * stays shut unless this handler appended its fragment.
	 *
	 * @var array<int, string>
	 */
	public const FORM_ERROR_CODES = array(
		'aggr_action_not_requestable',
		'aggr_action_already_requested',
		'aggr_action_reason_required',
		'aggr_action_reason_long',
		'aggr_action_not_saved',
		'aggr_rate_limited',
		'aggr_forbidden',
	);

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository         $campaigns Campaign status and ownership.
	 * @param Campaign_Request_Repository $requests  Stored requests.
	 * @param Rate_Limiter                $limiter   Abuse bounding.
	 * @param Audit_Repository            $audit     Audit persistence.
	 * @param Request_Notifier            $notifier  Tells the review team.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Campaign_Request_Repository $requests,
		private readonly Rate_Limiter $limiter,
		private readonly Audit_Repository $audit,
		private readonly Request_Notifier $notifier
	) {
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

		if ( ! in_array( $status, Campaign_Change_Manager::editable_statuses(), true ) ) {
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

		if ( array() !== $this->requests->action_request( $campaign_id ) ) {
			return $this->error( 'aggr_action_already_requested', __( 'You have already asked the review team about this campaign.', 'aggressive-ads' ), 409 );
		}

		$reason = trim( $reason );

		if ( '' === $reason ) {
			return $this->error( 'aggr_action_reason_required', __( 'Tell the review team why.', 'aggressive-ads' ), 422 );
		}

		if ( strlen( $reason ) > self::MAX_REASON_LENGTH ) {
			return $this->error( 'aggr_action_reason_long', __( 'That explanation is too long.', 'aggressive-ads' ), 422 );
		}

		if ( ! $this->requests->set_action_request( $campaign_id, $action, $reason, get_current_user_id() ) ) {
			return $this->error( 'aggr_action_not_saved', __( 'The request could not be saved. Please try again.', 'aggressive-ads' ), 500 );
		}

		$this->log( 'campaign.action_requested', $campaign_id, array( $action ), 'Advertiser requested a campaign action.' );
		$this->notifier->send( $campaign_id, $action );

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

		if ( array() === $this->requests->action_request( $campaign_id ) ) {
			return $this->error( 'aggr_no_action_request', __( 'There is no request to withdraw.', 'aggressive-ads' ), 404 );
		}

		$this->requests->clear_action_request( $campaign_id );
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

		if ( array() === $this->requests->action_request( $campaign_id ) ) {
			return $this->error( 'aggr_no_action_request', __( 'This campaign has no request waiting.', 'aggressive-ads' ), 404 );
		}

		$notes = trim( $notes );

		if ( '' !== $notes ) {
			$this->campaigns->set_review_notes( $campaign_id, $notes );
		}

		$this->requests->clear_action_request( $campaign_id );
		$this->log( 'campaign.action_request_resolved', $campaign_id, array(), 'Campaign action request resolved by staff.' );

		return true;
	}

	/**
	 * Element id of the dialog that asks for one of these.
	 *
	 * Stable per campaign, and the fragment a refused send redirects to.
	 * Built only from the id, so it is safe to put on a URL.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public static function dialog_id( int $campaign_id ): string {
		return 'aggr-request-' . max( 0, $campaign_id );
	}

	/**
	 * What the menu and the dialog are called for this set of asks.
	 *
	 * One ask uses that ask's own name. Two are named for what they are, so a
	 * scheduled campaign — where the advertiser can already cancel — does not
	 * offer a second cancel beside the one that ends the campaign now.
	 *
	 * @param array<int, array{action: string, label: string}> $actions Requestable transitions.
	 */
	public static function prompt_label( array $actions ): string {
		$targets = array();

		foreach ( $actions as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$target = (string) ( $option['action'] ?? '' );

			if ( '' !== $target ) {
				$targets[] = $target;
			}
		}

		$targets = array_values( array_unique( $targets ) );

		if ( 1 === count( $targets ) ) {
			return self::request_label( $targets[0] );
		}

		$restart = in_array( Post_Statuses::LIVE, $targets, true );
		$cancel  = in_array( Post_Statuses::CANCELLED, $targets, true );

		if ( $restart && $cancel ) {
			return __( 'Restart or cancel', 'aggressive-ads' );
		}

		return __( 'Pause or cancel', 'aggressive-ads' );
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
	 * Capability plus object authorization for the advertiser side.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $capability  Required capability.
	 * @return true|WP_Error
	 */
	private function authorize( int $campaign_id, string $capability ): bool|WP_Error {
		if ( ! current_user_can( $capability ) || ! current_user_can( 'edit_post', $campaign_id ) ) {
			return $this->error( 'aggr_forbidden', __( 'You do not have permission to change that campaign.', 'aggressive-ads' ), 403 );
		}

		return true;
	}

	/**
	 * Audit one request decision.
	 *
	 * The action's name goes into the context and the advertiser's reason does
	 * not, as with proposed changes: the reason is stored on the request and
	 * goes when it does.
	 *
	 * @param string             $event       Audit event name.
	 * @param int                $campaign_id Campaign post id.
	 * @param array<int, string> $actions     The requested status, if any.
	 * @param string             $message     Human-readable summary.
	 * @return void
	 */
	private function log( string $event, int $campaign_id, array $actions, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: $event,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: $message,
				outcome: Audit_Event::OUTCOME_OK,
				context: array( 'fields' => $actions ),
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
