<?php
/**
 * Progressive campaign form delivery.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

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

	public const CREATE_ACTION            = 'aggr_create_campaign';
	public const COPY_ACTION              = 'aggr_copy_campaign';
	public const SAVE_ACTION              = 'aggr_save_campaign';
	public const COMPLETE_CREATIVE_ACTION = 'aggr_complete_campaign_creative';
	public const RENAME_ACTION            = 'aggr_rename_campaign';
	public const SUBMIT_ACTION            = 'aggr_submit_campaign';
	public const WITHDRAW_ACTION          = 'aggr_withdraw_campaign';
	public const CHANGES_ACTION           = 'aggr_request_campaign_changes';
	public const CHANGES_CANCEL           = 'aggr_cancel_campaign_changes';
	public const CHANGES_SUBMIT           = 'aggr_submit_campaign_changes';
	public const CANCEL_ACTION            = 'aggr_cancel_campaign';
	public const REQUEST_ACTION           = 'aggr_request_campaign_action';
	public const REQUEST_WITHDRAW         = 'aggr_withdraw_campaign_action';

	/**
	 * Error codes that belong to the schedule on details.
	 *
	 * @var array<int, string>
	 */
	private const DATE_ERRORS = array( 'aggr_start_date_required', 'aggr_start_date_past', 'aggr_start_date_not_midnight', 'aggr_start_date_invalid', 'aggr_end_date_required', 'aggr_end_before_start', 'aggr_end_date_not_day_end', 'aggr_end_date_invalid' );

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
		add_action( 'admin_post_' . self::COMPLETE_CREATIVE_ACTION, array( $this, 'handle_complete_creative' ) );
		add_action( 'admin_post_' . self::RENAME_ACTION, array( $this, 'handle_rename' ) );
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
	 * The dashboard's package cards post here with a `package_id`, so choosing
	 * what to buy and starting the campaign are one click rather than two.
	 * A package that cannot be applied still leaves the draft behind and says
	 * why on it, rather than discarding a campaign the advertiser just created.
	 *
	 * @return void
	 */
	public function handle_create(): void {
		$this->assert_portal_access();
		check_admin_referer( self::CREATE_ACTION );

		$package_id = isset( $_POST['package_id'] ) ? absint( wp_unslash( $_POST['package_id'] ) ) : 0;
		$result     = $this->process_create();

		if ( is_wp_error( $result ) ) {
			$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS ), 'error', $result );
		}

		$url = add_query_arg( 'step', 'details', Routes::url( Request::ROUTE_CAMPAIGNS, $result ) );

		if ( $package_id > 0 ) {
			$chosen = $this->process_choose_package( $result, $package_id );

			if ( is_wp_error( $chosen ) ) {
				$this->redirect( $url, 'error', $chosen );
			}
		}

		$this->redirect( $url, 'created' );
	}

	/**
	 * Copies a campaign into a new draft and opens it.
	 *
	 * @return void
	 */
	public function handle_copy(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- copy_nonce_action() uses this id immediately below.

		check_admin_referer( Campaign_Nonces::copy_nonce_action( $campaign_id ) );

		$result = $this->process_copy( $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ), 'error', $result );
		}

		$this->redirect( Routes::url( Request::ROUTE_CAMPAIGNS, $result ), 'copied' );
	}

	/**
	 * Saves the first wizard step — package and schedule — and moves on.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( Campaign_Nonces::save_nonce_action( $campaign_id ) );

		/*
		 * **Absent, not empty, when the form did not render the field.**
		 *
		 * The name used to be on this step, so an unconditional `title => ''`
		 * was harmless. It is edited from the page heading now; posting an
		 * empty one here would refuse every save of this step as unnamed. The
		 * same holds for the package — an empty `placement_ids` once cleared
		 * the placements the package had just written — and for the end date,
		 * which a fixed package renders disabled so the server derives it.
		 */
		$fields = array();

		if ( isset( $_POST['title'] ) ) {
			$fields['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
		}

		if ( isset( $_POST['package_id'] ) ) {
			$fields['package_id'] = absint( wp_unslash( $_POST['package_id'] ) );
		}

		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$fields[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		if ( isset( $_POST['default_click_url'] ) ) {
			$fields['default_click_url'] = sanitize_text_field( wp_unslash( $_POST['default_click_url'] ) );
		}

		$revision = isset( $_POST['autosave_rev'] ) ? absint( $_POST['autosave_rev'] ) : -1;
		$result   = $this->process_save( $campaign_id, $fields, $revision );
		$url      = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			// Back to the step the refused field is on, not always the first one.
			$step = 'aggr_default_click_url_invalid' === $result->get_error_code() ? 'creative' : 'details';

			$this->redirect( add_query_arg( 'step', $step, $url ), 'error', $result );
		}

		$this->redirect( add_query_arg( 'step', 'creative', $url ), 'saved' );
	}

	/**
	 * Leaves the creative step for review.
	 *
	 * A refused date sends the advertiser to details, where the date is, and
	 * anything else back to the uploads — returning them to the step they
	 * pressed the button on would show the error beside nothing it is about.
	 *
	 * @return void
	 */
	public function handle_complete_creative(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- creative_nonce_action() uses this id immediately below.

		check_admin_referer( Campaign_Nonces::creative_nonce_action( $campaign_id ) );

		$revision = isset( $_POST['autosave_rev'] ) ? absint( $_POST['autosave_rev'] ) : -1;
		$result   = $this->process_complete_creative( $campaign_id, $revision );
		$url      = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$step = in_array( $result->get_error_code(), self::DATE_ERRORS, true ) ? 'details' : 'creative';

			$this->redirect( add_query_arg( 'step', $step, $url ), 'error', $result );
		}

		$this->redirect( add_query_arg( 'step', 'review', $url ), 'reviewing' );
	}

	/**
	 * Renames a campaign from the review step, for a browser without script.
	 *
	 * With script the page heading is the control and saves through REST
	 * autosave; this is the same write for everyone else, and it returns to
	 * the step the form was on.
	 *
	 * @return void
	 */
	public function handle_rename(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- rename_nonce_action() uses this id immediately below.

		check_admin_referer( Campaign_Nonces::rename_nonce_action( $campaign_id ) );

		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$revision = isset( $_POST['autosave_rev'] ) ? absint( $_POST['autosave_rev'] ) : -1;
		$step     = isset( $_POST['return_step'] ) ? sanitize_key( wp_unslash( $_POST['return_step'] ) ) : 'review';
		$step     = in_array( $step, Campaign_Editor::DISPLAY_STEPS, true ) ? $step : 'review';
		$result   = $this->process_rename( $campaign_id, $title, $revision );
		$url      = add_query_arg( 'step', $step, Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'error', $result );
		}

		$this->redirect( $url, 'renamed' );
	}

	/**
	 * Submits a reviewed campaign through the canonical state machine.
	 *
	 * @return void
	 */
	public function handle_submit(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( Campaign_Nonces::submit_nonce_action( $campaign_id ) );

		$notes    = isset( $_POST['advertiser_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['advertiser_notes'] ) ) : null;
		$revision = isset( $_POST['autosave_rev'] ) ? absint( $_POST['autosave_rev'] ) : -1;
		$result   = $this->process_submit( $campaign_id, $notes, $revision );
		$url      = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( add_query_arg( 'step', 'review', $url ), 'error', $result );
		}

		$this->redirect( $url, 'submitted' );
	}

	/**
	 * Pulls a submitted campaign back to draft and reopens the wizard.
	 *
	 * Lands on the first step rather than the persisted one. The persisted step
	 * after a submission is `review`, and returning somebody to a summary with
	 * a submit button after they asked to edit would be answering a different
	 * question than the one the button asks.
	 *
	 * @return void
	 */
	public function handle_withdraw(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- withdraw_nonce_action() uses this id immediately below.

		check_admin_referer( Campaign_Nonces::withdraw_nonce_action( $campaign_id ) );

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

		check_admin_referer( Campaign_Nonces::changes_nonce_action( $campaign_id ) );

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

		check_admin_referer( Campaign_Nonces::submit_changes_nonce_action( $campaign_id ) );

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

		check_admin_referer( Campaign_Nonces::cancel_changes_nonce_action( $campaign_id ) );

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

		check_admin_referer( Campaign_Nonces::action_nonce_action( $campaign_id ) );

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

		check_admin_referer( Campaign_Nonces::withdraw_action_nonce_action( $campaign_id ) );

		$result = $this->changes->withdraw_action( $campaign_id );
		$url    = Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $url, 'error', $result );
		}

		$this->redirect( $url, 'action_withdrawn' );
	}

	/**
	 * Ends a campaign at the advertiser's request.
	 *
	 * @return void
	 */
	public function handle_cancel(): void {
		$this->assert_portal_access();

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- cancel_nonce_action() uses this id immediately below.

		check_admin_referer( Campaign_Nonces::cancel_nonce_action( $campaign_id ) );

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
	 * Applies the package a draft was started from.
	 *
	 * Revision zero, because this runs straight after creation and nothing
	 * else can have saved the draft yet; a draft that has moved on is refused
	 * as a conflict rather than overwritten.
	 *
	 * @param int $campaign_id Newly created draft.
	 * @param int $package_id  Package chosen on the dashboard.
	 * @return int|WP_Error
	 */
	public function process_choose_package( int $campaign_id, int $package_id ): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to edit that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		return $this->editor->save( $campaign_id, array( 'package_id' => $package_id ), 0 );
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

		/*
		 * Only the keys the caller actually supplied. Campaign_Editor::save()
		 * tests each with array_key_exists, so an omitted key leaves the stored
		 * value alone and a present-but-empty one overwrites it. Passing a
		 * field the form did not render is how a save silently erases work done
		 * on another step.
		 */
		$clean = array( 'wizard_step' => 'creative' );

		foreach ( array( 'title', 'placement_ids', 'package_id', 'advertiser_notes', 'default_click_url' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$clean[ $key ] = $fields[ $key ];
			}
		}

		/*
		 * Local date strings, resolved in the site timezone here rather than
		 * trusted as timestamps: a date input carries no zone, and the
		 * campaign runs in the site's.
		 */
		foreach ( array(
			'start_date' => 'start_ts',
			'end_date'   => 'end_ts',
		) as $key => $field ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}

			$parsed = $this->parse_date( (string) $fields[ $key ], 'end_ts' === $field );

			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			$clean[ $field ] = $parsed;
		}

		return $this->editor->save( $campaign_id, $clean, $revision );
	}

	/**
	 * Delivery-level entry point for leaving the creative step.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $revision    Last-seen revision.
	 * @return int|WP_Error
	 */
	public function process_complete_creative( int $campaign_id, int $revision ): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to edit that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		return $this->editor->complete_creative( $campaign_id, $revision );
	}

	/**
	 * Delivery-level rename entry point for forms and integration tests.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $title       New name.
	 * @param int    $revision    Last-seen revision.
	 * @return int|WP_Error
	 */
	public function process_rename( int $campaign_id, string $title, int $revision ): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to edit that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		return $this->editor->save( $campaign_id, array( 'title' => $title ), $revision );
	}

	/**
	 * Delivery-level submission entry point for forms and integration tests.
	 *
	 * The state machine reauthorizes the object and revalidates current stored
	 * data. Readiness rendered in the browser is advisory and is never trusted.
	 *
	 * @param int         $campaign_id Campaign post id.
	 * @param string|null $notes       Advertiser note written on the submit step, or null to leave it alone.
	 * @param int         $revision    Client's last-seen revision, used only when a note is supplied.
	 * @return true|WP_Error
	 */
	public function process_submit( int $campaign_id, ?string $notes = null, int $revision = -1 ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to submit that campaign.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		/*
		 * Written before the transition, because the transition is what makes
		 * the campaign uneditable — saving afterwards would be refused by the
		 * edit window, and the note would be gone with no error the advertiser
		 * could see. A save that fails stops the submission rather than
		 * submitting without the note the advertiser just wrote.
		 */
		if ( null !== $notes ) {
			$saved = $this->editor->save( $campaign_id, array( 'advertiser_notes' => $notes ), $revision );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
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

		return in_array( $value, array( 'created', 'copied', 'saved', 'reviewing', 'renamed', 'submitted', 'withdrawn', 'changes_requested', 'changes_cancelled', 'changes_saved', 'cancelled', 'action_requested', 'action_withdrawn', 'error' ), true ) ? $value : '';
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
			'aggr_end_date_required'       => __( 'Choose an end date.', 'aggressive-ads' ),
			'aggr_end_before_start'        => __( 'The end date must be after the start date.', 'aggressive-ads' ),
			'aggr_start_date_required'     => __( 'Choose a start date.', 'aggressive-ads' ),
			'aggr_start_date_past'         => __( 'The start date has already passed. Choose a later one.', 'aggressive-ads' ),
			'aggr_start_date_not_midnight' => __( 'The start date must begin at midnight in the site timezone.', 'aggressive-ads' ),
			'aggr_end_date_not_day_end'    => __( 'The end date must include the full selected day in the site timezone.', 'aggressive-ads' ),
			'aggr_start_date_invalid',
			'aggr_end_date_invalid'        => __( 'Enter a valid date in the required format.', 'aggressive-ads' ),
			'aggr_placement_unavailable'   => __( 'One of the selected placements is no longer available.', 'aggressive-ads' ),
			'aggr_package_required'        => __( 'Choose a package.', 'aggressive-ads' ),
			'aggr_package_unavailable'     => __( 'That package is no longer available. Choose another package.', 'aggressive-ads' ),
			'aggr_package_misconfigured'   => __( 'That package is not configured completely. Choose another package or get in touch.', 'aggressive-ads' ),
			'aggr_creatives_incomplete'    => __( 'Add an ad for every size in the package before continuing.', 'aggressive-ads' ),
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
			'aggr_end_date_required',
			'aggr_end_before_start',
			'aggr_end_date_not_day_end',
			'aggr_end_date_invalid'        => 'aggr-end-date',
			'aggr_start_date_invalid',
			'aggr_start_date_required',
			'aggr_start_date_not_midnight',
			'aggr_start_date_past'         => 'aggr-start-date',
			'aggr_placement_unavailable'   => 'aggr-placements',
			'aggr_package_required',
			'aggr_package_unavailable',
			'aggr_package_misconfigured'   => 'aggr-packages',
			'aggr_creatives_incomplete'    => 'aggr-uploads',
			'aggr_default_click_url_invalid' => 'aggr-campaign-link',
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
		return Date_Input::parse( $value, $end_of_day );
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
