<?php
/**
 * Progressive campaign form delivery.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use DateTimeImmutable;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Campaign_Copier;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use WP_Error;

/**
 * Handles the no-JavaScript campaign forms.
 *
 * The same Campaign_Editor workflow backs REST autosave, so progressive
 * enhancement cannot acquire a second authorization or validation policy.
 */
final class Campaign_Actions implements Service {

	public const CREATE_ACTION        = 'aggr_create_campaign';
	public const COPY_ACTION          = 'aggr_copy_campaign';
	public const SAVE_ACTION          = 'aggr_save_campaign';
	public const SAVE_PACKAGE_ACTION  = 'aggr_save_campaign_package';
	public const SAVE_SCHEDULE_ACTION = 'aggr_save_campaign_schedule';
	public const SUBMIT_ACTION        = 'aggr_submit_campaign';
	public const WITHDRAW_ACTION      = 'aggr_withdraw_campaign';
	public const CHANGES_ACTION       = 'aggr_request_campaign_changes';
	public const CHANGES_CANCEL       = 'aggr_cancel_campaign_changes';
	public const CHANGES_SUBMIT       = 'aggr_submit_campaign_changes';
	public const CANCEL_ACTION        = 'aggr_cancel_campaign';
	public const REQUEST_ACTION       = 'aggr_request_campaign_action';
	public const REQUEST_WITHDRAW     = 'aggr_withdraw_campaign_action';

	/**
	 * Constructor.
	 *
	 * @param Campaign_Editor         $editor  Draft workflow.
	 * @param Campaign_Copier         $copier  Campaign copy into a new draft.
	 * @param Campaign_State_Machine  $machine Campaign lifecycle.
	 * @param Rate_Limiter            $limiter Transition abuse bounding.
	 * @param Campaign_Change_Manager $changes Running-campaign change proposals.
	 */
	public function __construct(
		private readonly Campaign_Editor $editor,
		private readonly Campaign_Copier $copier,
		private readonly Campaign_State_Machine $machine,
		private readonly Rate_Limiter $limiter,
		private readonly Campaign_Change_Manager $changes
	) {
	}

	/**
	 * Attaches authenticated form handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		add_action( 'admin_post_' . self::COPY_ACTION, array( $this, 'handle_copy' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::SAVE_PACKAGE_ACTION, array( $this, 'handle_save_package' ) );
		add_action( 'admin_post_' . self::SAVE_SCHEDULE_ACTION, array( $this, 'handle_save_schedule' ) );
		add_action( 'admin_post_' . self::SUBMIT_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_' . self::WITHDRAW_ACTION, array( $this, 'handle_withdraw' ) );
		add_action( 'admin_post_' . self::CHANGES_ACTION, array( $this, 'handle_request_changes' ) );
		add_action( 'admin_post_' . self::CHANGES_CANCEL, array( $this, 'handle_cancel_changes' ) );
		add_action( 'admin_post_' . self::CHANGES_SUBMIT, array( $this, 'handle_submit_changes' ) );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::REQUEST_ACTION, array( $this, 'handle_request_action' ) );
		add_action( 'admin_post_' . self::REQUEST_WITHDRAW, array( $this, 'handle_withdraw_action' ) );
	}

	/**
	 * Creates a draft and opens its details screen.
	 *
	 * @return void
	 */
	public function handle_create(): void {
		$this->assert_portal_access();
		check_admin_referer( self::CREATE_ACTION );

		$result = $this->process_create();

		if ( is_wp_error( $result ) ) {
			$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS ), 'error', $result );
		}

		$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS, $result ), 'created' );
	}

	/**
	 * Copies a campaign into a new draft and opens it.
	 *
	 * @return void
	 */
	public function handle_copy(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- copy_nonce_action() uses this id immediately below.

		check_admin_referer( self::copy_nonce_action( $campaign_id ) );

		$result = $this->process_copy( $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ), 'error', $result );
		}

		$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS, $result ), 'copied' );
	}

	/**
	 * Saves the first wizard step and returns to the campaign.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::save_nonce_action( $campaign_id ) );

		$fields = array(
			'title'            => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'placement_ids'    => isset( $_POST['placement_ids'] ) && is_array( $_POST['placement_ids'] )
				? array_map( 'absint', wp_unslash( $_POST['placement_ids'] ) )
				: array(),
			'advertiser_notes' => isset( $_POST['advertiser_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['advertiser_notes'] ) ) : '',
		);

		$revision = isset( $_POST['autosave_rev'] ) ? absint( $_POST['autosave_rev'] ) : -1;
		$result   = $this->process_save( $campaign_id, $fields, $revision );
		$url      = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( add_query_arg( 'step', 'details', $url ), 'error', $result );
		}

		$this->redirect( add_query_arg( 'step', 'package', $url ), 'saved' );
	}

	/**
	 * Saves the selected catalogue package and returns to that wizard step.
	 *
	 * @return void
	 */
	public function handle_save_package(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::package_nonce_action( $campaign_id ) );

		$package_id = isset( $_POST['package_id'] ) ? absint( $_POST['package_id'] ) : 0;
		$revision   = isset( $_POST['autosave_rev'] ) ? absint( $_POST['autosave_rev'] ) : -1;
		$result     = $this->process_save_package( $campaign_id, $package_id, $revision );
		$url        = add_query_arg( 'step', 'creative', Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'error', $result );
		}

		$this->redirect( $url, 'package_saved' );
	}

	/**
	 * Saves the destination confirmation and campaign schedule.
	 *
	 * @return void
	 */
	public function handle_save_schedule(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::schedule_nonce_action( $campaign_id ) );

		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$revision   = isset( $_POST['autosave_rev'] ) ? absint( $_POST['autosave_rev'] ) : -1;
		$result     = $this->process_save_schedule( $campaign_id, $start_date, $end_date, $revision );
		$url        = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( add_query_arg( 'step', 'destination', $url ), 'error', $result );
		}

		$this->redirect( add_query_arg( 'step', 'review', $url ), 'schedule_saved' );
	}

	/**
	 * Submits a reviewed campaign through the canonical state machine.
	 *
	 * @return void
	 */
	public function handle_submit(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::submit_nonce_action( $campaign_id ) );

		$result = $this->process_submit( $campaign_id );
		$url    = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$step = 'aggr_campaign_invalid' === $result->get_error_code() ? 'review' : 'submit';
			$this->redirect( add_query_arg( 'step', $step, $url ), 'error', $result );
		}

		$this->redirect( $url, 'submitted' );
	}

	/**
	 * Pulls a submitted campaign back to draft and reopens the wizard.
	 *
	 * Lands on the first step rather than the persisted one. The persisted step
	 * after a submission is `submit`, and returning somebody there — to the one
	 * screen with no fields on it — after they asked to edit would be answering
	 * a different question than the one the button asks.
	 *
	 * @return void
	 */
	public function handle_withdraw(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- withdraw_nonce_action() uses this id immediately below.

		check_admin_referer( self::withdraw_nonce_action( $campaign_id ) );

		$result = $this->process_withdraw( $campaign_id );
		$url    = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'error', $result );
		}

		$this->redirect( add_query_arg( 'step', 'details', $url ), 'withdrawn' );
	}

	/**
	 * Proposes changes to a campaign that is already running.
	 *
	 * Only the fields the site allows are read out of the request at all. A
	 * field name that is not enabled is never looked for, so an extra input in
	 * a hand-built POST has nothing to reach.
	 *
	 * @return void
	 */
	public function handle_request_changes(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- changes_nonce_action() uses this id immediately below.

		check_admin_referer( self::changes_nonce_action( $campaign_id ) );

		$result = $this->changes->stage( $campaign_id, $this->posted_changes() );
		$url    = add_query_arg( 'edit', '1', Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ) );
		$next   = isset( $_POST['next_step'] ) ? sanitize_key( wp_unslash( $_POST['next_step'] ) ) : 'review'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above; only ever an allowlisted step name.
		$next   = in_array( $next, self::CHANGE_STEPS, true ) ? $next : 'review';

		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'error', $result );
		}

		$this->redirect( add_query_arg( 'step', $next, $url ), 'changes_saved' );
	}

	/**
	 * Sends the accumulated proposal to the review team.
	 *
	 * @return void
	 */
	public function handle_submit_changes(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- submit_changes_nonce_action() uses this id immediately below.

		check_admin_referer( self::submit_changes_nonce_action( $campaign_id ) );

		$result = $this->changes->submit( $campaign_id );
		$url    = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				add_query_arg(
					array(
						'edit' => '1',
						'step' => 'review',
					),
					$url
				),
				'error',
				$result
			);
		}

		$this->redirect( $url, 'changes_requested' );
	}

	/**
	 * Takes back a pending proposal.
	 *
	 * @return void
	 */
	public function handle_cancel_changes(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- cancel_changes_nonce_action() uses this id immediately below.

		check_admin_referer( self::cancel_changes_nonce_action( $campaign_id ) );

		$result = $this->changes->withdraw( $campaign_id );
		$url    = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'error', $result );
		}

		$this->redirect( $url, 'changes_cancelled' );
	}

	/**
	 * Reads only the proposal fields this site has enabled.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_changes(): array {
		$allowed  = $this->changes->allowed_fields();
		$proposed = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every caller verifies the action nonce before reaching this.
		if ( in_array( 'title', $allowed, true ) && isset( $_POST['title'] ) ) {
			$proposed['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
		}

		if ( in_array( 'advertiser_notes', $allowed, true ) && isset( $_POST['advertiser_notes'] ) ) {
			$proposed['advertiser_notes'] = sanitize_textarea_field( wp_unslash( $_POST['advertiser_notes'] ) );
		}

		if ( in_array( 'start_ts', $allowed, true ) && isset( $_POST['start_date'] ) ) {
			$proposed['start_ts'] = $this->proposed_date( sanitize_text_field( wp_unslash( $_POST['start_date'] ) ), false );
		}

		if ( in_array( 'end_ts', $allowed, true ) && isset( $_POST['end_date'] ) ) {
			$proposed['end_ts'] = $this->proposed_date( sanitize_text_field( wp_unslash( $_POST['end_date'] ) ), true );
		}

		if ( in_array( 'placement_ids', $allowed, true ) && isset( $_POST['placement_ids'] ) && is_array( $_POST['placement_ids'] ) ) {
			$proposed['placement_ids'] = array_map( 'absint', wp_unslash( $_POST['placement_ids'] ) );
		}

		if ( in_array( 'click_urls', $allowed, true ) && isset( $_POST['click_urls'] ) && is_array( $_POST['click_urls'] ) ) {
			$urls = array();

			// esc_url_raw rather than sanitize_text_field: the workflow rejects
			// anything that is not http(s), and a mangled scheme would fail
			// that check for the wrong reason and confuse the message.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is esc_url_raw()'d in the loop body; the array itself carries no value.
			foreach ( wp_unslash( $_POST['click_urls'] ) as $creative_id => $url ) {
				$urls[ absint( $creative_id ) ] = is_string( $url ) ? esc_url_raw( trim( $url ) ) : '';
			}

			$proposed['click_urls'] = $urls;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $proposed;
	}

	/**
	 * Asks staff to pause, restart or cancel a running campaign.
	 *
	 * @return void
	 */
	public function handle_request_action(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- action_nonce_action() uses this id immediately below.

		check_admin_referer( self::action_nonce_action( $campaign_id ) );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$action = isset( $_POST['requested_action'] ) ? sanitize_key( wp_unslash( $_POST['requested_action'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->changes->request_action( $campaign_id, $action, $reason );
		$url    = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'error', $result );
		}

		$this->redirect( $url, 'action_requested' );
	}

	/**
	 * Takes back a request staff have not acted on.
	 *
	 * @return void
	 */
	public function handle_withdraw_action(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- withdraw_action_nonce_action() uses this id immediately below.

		check_admin_referer( self::withdraw_action_nonce_action( $campaign_id ) );

		$result = $this->changes->withdraw_action( $campaign_id );
		$url    = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'error', $result );
		}

		$this->redirect( $url, 'action_withdrawn' );
	}

	/**
	 * Nonce action for one campaign's staff-action request.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function action_nonce_action( int $campaign_id ): string {
		return self::REQUEST_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for withdrawing one campaign's staff-action request.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function withdraw_action_nonce_action( int $campaign_id ): string {
		return self::REQUEST_WITHDRAW . '_' . max( 0, $campaign_id );
	}

	/**
	 * Ends a campaign at the advertiser's request.
	 *
	 * @return void
	 */
	public function handle_cancel(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- cancel_nonce_action() uses this id immediately below.

		check_admin_referer( self::cancel_nonce_action( $campaign_id ) );

		$result = $this->process_cancel( $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ), 'error', $result );
		}

		// Back to the list, not to the campaign: the thing they were looking at
		// is finished, and leaving them on it invites a second attempt.
		$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS ), 'cancelled' );
	}

	/**
	 * Delivery-level cancellation entry point for forms and integration tests.
	 *
	 * Cancellation, not deletion. `aggr_cancelled` is terminal, so the campaign
	 * stops serving and can never restart — but the row, its audit trail and
	 * its delivery figures survive. Hard-deleting instead would orphan audit
	 * and rollup rows that reference the id, and would strand the private
	 * creative bytes on disk: `Creative_Retention` frees those by walking
	 * *terminal campaigns*, and a row that no longer exists is never walked.
	 *
	 * Which statuses this is offered from is Transition_Table's business, not
	 * this method's — it just asks the state machine, which refuses an edge the
	 * advertiser does not have.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return bool|WP_Error
	 */
	public function process_cancel( int $campaign_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to end that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->machine->apply( $campaign_id, Post_Statuses::CANCELLED );
	}

	/**
	 * Nonce action for one campaign's cancellation.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function cancel_nonce_action( int $campaign_id ): string {
		return self::CANCEL_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Delivery-level create entry point, kept public for integration tests.
	 *
	 * @return int|WP_Error
	 */
	public function process_create(): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to create a campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		return $this->editor->create();
	}

	/**
	 * Delivery-level copy entry point for forms and integration tests.
	 *
	 * @param int $campaign_id Source campaign post id.
	 * @return int|WP_Error
	 */
	public function process_copy( int $campaign_id ): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to copy that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_COPY, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->copier->copy( $campaign_id );
	}

	/**
	 * Saves campaign identity and review context through the shared workflow.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $fields      Form values.
	 * @param int                  $revision    Last-seen revision.
	 * @return int|WP_Error
	 */
	public function process_save( int $campaign_id, array $fields, int $revision ): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to edit that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		return $this->editor->save(
			$campaign_id,
			array(
				'title'            => (string) ( $fields['title'] ?? '' ),
				'placement_ids'    => is_array( $fields['placement_ids'] ?? null ) ? $fields['placement_ids'] : array(),
				'advertiser_notes' => (string) ( $fields['advertiser_notes'] ?? '' ),
				'wizard_step'      => 'package',
			),
			$revision
		);
	}

	/**
	 * Delivery-level package selection entry point for forms and tests.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $package_id  Selected package post id.
	 * @param int $revision    Last-seen revision.
	 * @return int|WP_Error
	 */
	public function process_save_package( int $campaign_id, int $package_id, int $revision ): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to edit that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		if ( $package_id <= 0 ) {
			return new WP_Error( 'aggr_package_required', __( 'Choose a package.', 'aggressive-ads' ), array( 'status' => 422 ) );
		}

		return $this->editor->save(
			$campaign_id,
			array(
				'package_id'  => $package_id,
				'wizard_step' => 'creative',
			),
			$revision
		);
	}

	/**
	 * Parses local dates and completes the shared schedule workflow.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $start_date  Local YYYY-MM-DD start date.
	 * @param string $end_date    Local YYYY-MM-DD end date, or empty.
	 * @param int    $revision    Last-seen revision.
	 * @return int|WP_Error
	 */
	public function process_save_schedule( int $campaign_id, string $start_date, string $end_date, int $revision ): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to edit that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		$start = $this->parse_date( $start_date, false );

		if ( is_wp_error( $start ) ) {
			return $start;
		}

		$end = $this->parse_date( $end_date, true );

		if ( is_wp_error( $end ) ) {
			return $end;
		}

		return $this->editor->save_schedule( $campaign_id, $start, $end, $revision );
	}

	/**
	 * Delivery-level submission entry point for forms and integration tests.
	 *
	 * The state machine reauthorizes the object and revalidates current stored
	 * data. Readiness rendered in the browser is advisory and is never trusted.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function process_submit( int $campaign_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to submit that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->machine->apply( $campaign_id, Post_Statuses::SUBMITTED );
	}

	/**
	 * Delivery-level withdrawal entry point for forms and integration tests.
	 *
	 * There is no status check here on purpose. `Transition_Table` already
	 * says withdrawal runs from `submitted` only, and its `unclaimed` guard
	 * already refuses once a reviewer has the campaign open — re-testing either
	 * here would be a second copy of the rule, free to drift from the first and
	 * certain to be the one somebody forgets to update.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return bool|WP_Error
	 */
	public function process_withdraw( int $campaign_id ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to withdraw that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->machine->apply( $campaign_id, Post_Statuses::DRAFT );
	}

	/**
	 * Nonce action bound to one campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function save_nonce_action( int $campaign_id ): string {
		return self::SAVE_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action bound to the campaign being copied.
	 *
	 * @param int $campaign_id Source campaign post id.
	 * @return string
	 */
	public static function copy_nonce_action( int $campaign_id ): string {
		return self::COPY_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's package selection.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function package_nonce_action( int $campaign_id ): string {
		return self::SAVE_PACKAGE_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's destination-and-schedule step.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function schedule_nonce_action( int $campaign_id ): string {
		return self::SAVE_SCHEDULE_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's final submission.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function submit_nonce_action( int $campaign_id ): string {
		return self::SUBMIT_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's withdrawal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function withdraw_nonce_action( int $campaign_id ): string {
		return self::WITHDRAW_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's change proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function changes_nonce_action( int $campaign_id ): string {
		return self::CHANGES_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for cancelling one campaign's change proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function cancel_changes_nonce_action( int $campaign_id ): string {
		return self::CHANGES_CANCEL . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for submitting one campaign's change proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function submit_changes_nonce_action( int $campaign_id ): string {
		return self::CHANGES_SUBMIT . '_' . max( 0, $campaign_id );
	}

	/**
	 * The steps of the running-campaign edit flow, in order.
	 *
	 * Deliberately fewer than the creation wizard: package and creative upload
	 * are not proposal fields, and a step with nothing in it is a step that
	 * teaches an advertiser the flow is broken.
	 */
	public const CHANGE_STEPS = array( 'details', 'schedule', 'destination', 'review' );

	/**
	 * The requested edit step, allowlisted.
	 *
	 * @return string
	 */
	public static function request_change_step(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display preference; authorization does not depend on it.
		$requested = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';

		return in_array( $requested, self::CHANGE_STEPS, true ) ? $requested : 'details';
	}

	/**
	 * Whether the request asked to confirm ending a campaign.
	 *
	 * A GET flag rather than a stored state: it selects which screen to draw
	 * and changes nothing, so a reload or a shared link is harmless. The write
	 * behind it is a nonce-checked POST.
	 *
	 * @return bool
	 */
	public static function wants_cancel_confirmation(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses a screen; the cancellation itself is a nonce-checked POST.
		return isset( $_GET['confirm'] ) && 'cancel' === sanitize_key( wp_unslash( $_GET['confirm'] ) );
	}

	/**
	 * Whether the request asked to edit a running campaign.
	 *
	 * @return bool
	 */
	public static function wants_change_editor(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only mode flag; the workflow re-authorizes every write.
		return isset( $_GET['edit'] ) && '1' === sanitize_key( wp_unslash( $_GET['edit'] ) );
	}

	/**
	 * Reads a known post/redirect/get notice.
	 *
	 * @return string
	 */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post/redirect/get display state; never authorizes or mutates anything.
		$value = isset( $_GET['aggr_notice'] ) ? sanitize_key( wp_unslash( $_GET['aggr_notice'] ) ) : '';

		return in_array( $value, array( 'created', 'copied', 'saved', 'package_saved', 'schedule_saved', 'submitted', 'withdrawn', 'changes_requested', 'changes_cancelled', 'changes_saved', 'cancelled', 'action_requested', 'action_withdrawn', 'error' ), true ) ? $value : '';
	}

	/**
	 * Reads an allowlisted display-only wizard step.
	 *
	 * @param string $fallback Persisted resume step.
	 * @return string
	 */
	public static function request_step( string $fallback ): string {
		$fallback = in_array( $fallback, Campaign_Editor::WIZARD_STEPS, true ) ? $fallback : 'details';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display preference; authorization and writes do not depend on it.
		$requested = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';

		return in_array( $requested, Campaign_Editor::DISPLAY_STEPS, true ) ? $requested : $fallback;
	}

	/**
	 * Reads the safe error code passed through a redirect.
	 *
	 * @return string
	 */
	public static function request_error_code(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted display state; never authorizes or mutates anything.
		return isset( $_GET['aggr_error'] ) ? sanitize_key( wp_unslash( $_GET['aggr_error'] ) ) : '';
	}

	/**
	 * Maps a redirect error code to a stable, translated sentence.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		return match ( $code ) {
			'aggr_title_required'          => __( 'Enter a campaign name.', 'aggressive-ads' ),
			'aggr_title_too_long'          => __( 'Use 160 characters or fewer for the campaign name.', 'aggressive-ads' ),
			'aggr_end_before_start'        => __( 'The end date must be after the start date.', 'aggressive-ads' ),
			'aggr_start_date_required'     => __( 'Choose a start date.', 'aggressive-ads' ),
			'aggr_start_date_past'         => __( 'The start date has already passed. Choose a later one.', 'aggressive-ads' ),
			'aggr_start_date_invalid',
			'aggr_end_date_invalid'        => __( 'Enter a valid date in the required format.', 'aggressive-ads' ),
			'aggr_placement_unavailable'   => __( 'One of the selected placements is no longer available.', 'aggressive-ads' ),
			'aggr_package_required'        => __( 'Choose a package.', 'aggressive-ads' ),
			'aggr_package_unavailable'     => __( 'That package is no longer available. Choose another package.', 'aggressive-ads' ),
			'aggr_package_misconfigured'   => __( 'That package is not configured completely. Choose another package or get in touch.', 'aggressive-ads' ),
			'aggr_creatives_incomplete'    => __( 'Upload one creative for every package placement before scheduling.', 'aggressive-ads' ),
			'aggr_edit_conflict'           => __( 'This campaign changed in another window. Review the current values and save again.', 'aggressive-ads' ),
			'aggr_organization_missing'    => __( 'Your account is not connected to an organization.', 'aggressive-ads' ),
			'aggr_organization_inactive'   => __( 'This organization cannot create campaigns. Please get in touch.', 'aggressive-ads' ),
			'aggr_campaign_invalid'        => __( 'The campaign changed and is no longer ready to submit. Resolve every review item and try again.', 'aggressive-ads' ),
			'aggr_illegal_transition'      => __( 'This campaign has already been submitted or cannot be submitted right now.', 'aggressive-ads' ),
			'aggr_campaign_not_copied'     => __( 'The campaign could not be copied. Please try again.', 'aggressive-ads' ),
			'aggr_rate_limited'            => __( 'There have been too many attempts. Wait a moment and try again.', 'aggressive-ads' ),
			'aggr_forbidden'               => __( 'You do not have permission to submit that campaign.', 'aggressive-ads' ),
			'aggr_status_write_failed'     => __( 'The campaign could not be submitted. Please try again.', 'aggressive-ads' ),
			default                            => __( 'The campaign could not be saved. Please try again.', 'aggressive-ads' ),
		};
	}

	/**
	 * Field targeted by a known validation error.
	 *
	 * @param string $code Error code.
	 * @return string Empty when the error is not field-specific.
	 */
	public static function error_field( string $code ): string {
		return match ( $code ) {
			'aggr_title_required',
			'aggr_title_too_long'          => 'aggr-title',
			'aggr_end_before_start',
			'aggr_end_date_invalid'        => 'aggr-end-date',
			'aggr_start_date_invalid',
			'aggr_start_date_required',
			'aggr_start_date_past'         => 'aggr-start-date',
			'aggr_placement_unavailable'   => 'aggr-placements',
			'aggr_package_required',
			'aggr_package_unavailable',
			'aggr_package_misconfigured'   => 'aggr-packages',
			'aggr_creatives_incomplete'    => 'aggr-destinations',
			'aggr_campaign_invalid'        => 'aggr-readiness-heading',
			default                            => '',
		};
	}

	/**
	 * A proposed date as a timestamp, or -1 when it will not parse.
	 *
	 * -1 rather than 0 or a WP_Error: zero is the model's legitimate
	 * open-ended value, so returning it for garbage would silently clear an end
	 * date the advertiser meant to change. -1 can never equal a stored value,
	 * so it always reaches the validator as a change and is refused there,
	 * where the message belongs.
	 *
	 * @param string $value      YYYY-MM-DD or empty.
	 * @param bool   $end_of_day Whether to use 23:59:59.
	 * @return int
	 */
	private function proposed_date( string $value, bool $end_of_day ): int {
		$parsed = $this->parse_date( $value, $end_of_day );

		return is_wp_error( $parsed ) ? -1 : $parsed;
	}

	/**
	 * Parses an HTML date in the WordPress timezone into a UTC Unix integer.
	 *
	 * End dates use the last second of the local day; start dates use the first.
	 * An empty input is the model's open/unset value, zero.
	 *
	 * @param string $value      YYYY-MM-DD or empty.
	 * @param bool   $end_of_day Whether to use 23:59:59.
	 * @return int|WP_Error
	 */
	private function parse_date( string $value, bool $end_of_day ): int|WP_Error {
		if ( '' === $value ) {
			return 0;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error(
				$end_of_day ? 'aggr_end_date_invalid' : 'aggr_start_date_invalid',
				__( 'Enter a valid date in the required format.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		if ( $end_of_day ) {
			$date = $date->setTime( 23, 59, 59 );
		}

		return $date->getTimestamp();
	}

	/**
	 * Refuses direct handler calls without portal access.
	 *
	 * @return void
	 */
	private function assert_portal_access(): void {
		if ( is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Redirects after a write. Never carries a user-provided message.
	 *
	 * @param string        $url    Destination.
	 * @param string        $notice Notice key.
	 * @param WP_Error|null $error  Optional workflow error.
	 * @return never
	 */
	private function redirect( string $url, string $notice, ?WP_Error $error = null ): never {
		$args = array( 'aggr_notice' => $notice );

		if ( null !== $error ) {
			$args['aggr_error'] = sanitize_key( (string) $error->get_error_code() );
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}
}
