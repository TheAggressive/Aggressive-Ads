<?php
/**
 * Progressive campaign form delivery.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

use DateTimeImmutable;
use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Security\Rate_Limiter;
use LAAO_Advertiser_Portal\Workflow\Campaign_Editor;
use LAAO_Advertiser_Portal\Workflow\Campaign_State_Machine;
use WP_Error;

/**
 * Handles the no-JavaScript campaign forms.
 *
 * The same Campaign_Editor workflow backs REST autosave, so progressive
 * enhancement cannot acquire a second authorization or validation policy.
 */
final class Campaign_Actions implements Service {

	public const CREATE_ACTION        = 'laao_ads_create_campaign';
	public const SAVE_ACTION          = 'laao_ads_save_campaign';
	public const SAVE_PACKAGE_ACTION  = 'laao_ads_save_campaign_package';
	public const SAVE_SCHEDULE_ACTION = 'laao_ads_save_campaign_schedule';
	public const SUBMIT_ACTION        = 'laao_ads_submit_campaign';

	/**
	 * Constructor.
	 *
	 * @param Campaign_Editor        $editor  Draft workflow.
	 * @param Campaign_State_Machine $machine Campaign lifecycle.
	 * @param Rate_Limiter           $limiter Transition abuse bounding.
	 */
	public function __construct(
		private readonly Campaign_Editor $editor,
		private readonly Campaign_State_Machine $machine,
		private readonly Rate_Limiter $limiter
	) {
	}

	/**
	 * Attaches authenticated form handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::SAVE_PACKAGE_ACTION, array( $this, 'handle_save_package' ) );
		add_action( 'admin_post_' . self::SAVE_SCHEDULE_ACTION, array( $this, 'handle_save_schedule' ) );
		add_action( 'admin_post_' . self::SUBMIT_ACTION, array( $this, 'handle_submit' ) );
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
			$step = 'laao_ads_campaign_invalid' === $result->get_error_code() ? 'review' : 'submit';
			$this->redirect( add_query_arg( 'step', $step, $url ), 'error', $result );
		}

		$this->redirect( $url, 'submitted' );
	}

	/**
	 * Delivery-level create entry point, kept public for integration tests.
	 *
	 * @return int|WP_Error
	 */
	public function process_create(): int|WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'laao_ads_forbidden', __( 'You do not have permission to create a campaign.', 'laao-advertiser-portal' ), array( 'status' => 403 ) );
		}

		return $this->editor->create();
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
			return new WP_Error( 'laao_ads_forbidden', __( 'You do not have permission to edit that campaign.', 'laao-advertiser-portal' ), array( 'status' => 403 ) );
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
			return new WP_Error( 'laao_ads_forbidden', __( 'You do not have permission to edit that campaign.', 'laao-advertiser-portal' ), array( 'status' => 403 ) );
		}

		if ( $package_id <= 0 ) {
			return new WP_Error( 'laao_ads_package_required', __( 'Choose a package.', 'laao-advertiser-portal' ), array( 'status' => 422 ) );
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
			return new WP_Error( 'laao_ads_forbidden', __( 'You do not have permission to edit that campaign.', 'laao-advertiser-portal' ), array( 'status' => 403 ) );
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
			return new WP_Error( 'laao_ads_forbidden', __( 'You do not have permission to submit that campaign.', 'laao-advertiser-portal' ), array( 'status' => 403 ) );
		}

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->machine->apply( $campaign_id, Post_Statuses::SUBMITTED );
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
	 * Reads a known post/redirect/get notice.
	 *
	 * @return string
	 */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post/redirect/get display state; never authorizes or mutates anything.
		$value = isset( $_GET['laao_ads_notice'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_notice'] ) ) : '';

		return in_array( $value, array( 'created', 'saved', 'package_saved', 'schedule_saved', 'submitted', 'error' ), true ) ? $value : '';
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
		return isset( $_GET['laao_ads_error'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_error'] ) ) : '';
	}

	/**
	 * Maps a redirect error code to a stable, translated sentence.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		return match ( $code ) {
			'laao_ads_title_required'          => __( 'Enter a campaign name.', 'laao-advertiser-portal' ),
			'laao_ads_title_too_long'          => __( 'Use 160 characters or fewer for the campaign name.', 'laao-advertiser-portal' ),
			'laao_ads_end_before_start'        => __( 'The end date must be after the start date.', 'laao-advertiser-portal' ),
			'laao_ads_start_date_required'     => __( 'Choose a start date.', 'laao-advertiser-portal' ),
			'laao_ads_start_date_past'         => __( 'The start date has already passed. Choose a later one.', 'laao-advertiser-portal' ),
			'laao_ads_start_date_invalid',
			'laao_ads_end_date_invalid'        => __( 'Enter a valid date in the required format.', 'laao-advertiser-portal' ),
			'laao_ads_placement_unavailable'   => __( 'One of the selected placements is no longer available.', 'laao-advertiser-portal' ),
			'laao_ads_package_required'        => __( 'Choose a package.', 'laao-advertiser-portal' ),
			'laao_ads_package_unavailable'     => __( 'That package is no longer available. Choose another package.', 'laao-advertiser-portal' ),
			'laao_ads_package_misconfigured'   => __( 'That package is not configured completely. Choose another package or get in touch.', 'laao-advertiser-portal' ),
			'laao_ads_creatives_incomplete'    => __( 'Upload one creative for every package placement before scheduling.', 'laao-advertiser-portal' ),
			'laao_ads_edit_conflict'           => __( 'This campaign changed in another window. Review the current values and save again.', 'laao-advertiser-portal' ),
			'laao_ads_organization_missing'    => __( 'Your account is not connected to an organization.', 'laao-advertiser-portal' ),
			'laao_ads_organization_inactive'   => __( 'This organization cannot create campaigns. Please get in touch.', 'laao-advertiser-portal' ),
			'laao_ads_campaign_invalid'        => __( 'The campaign changed and is no longer ready to submit. Resolve every review item and try again.', 'laao-advertiser-portal' ),
			'laao_ads_illegal_transition'      => __( 'This campaign has already been submitted or cannot be submitted right now.', 'laao-advertiser-portal' ),
			'laao_ads_rate_limited'            => __( 'There have been too many submission attempts. Wait a moment and try again.', 'laao-advertiser-portal' ),
			'laao_ads_forbidden'               => __( 'You do not have permission to submit that campaign.', 'laao-advertiser-portal' ),
			'laao_ads_status_write_failed'     => __( 'The campaign could not be submitted. Please try again.', 'laao-advertiser-portal' ),
			default                            => __( 'The campaign could not be saved. Please try again.', 'laao-advertiser-portal' ),
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
			'laao_ads_title_required',
			'laao_ads_title_too_long'          => 'laao-ads-title',
			'laao_ads_end_before_start',
			'laao_ads_end_date_invalid'        => 'laao-ads-end-date',
			'laao_ads_start_date_invalid',
			'laao_ads_start_date_required',
			'laao_ads_start_date_past'         => 'laao-ads-start-date',
			'laao_ads_placement_unavailable'   => 'laao-ads-placements',
			'laao_ads_package_required',
			'laao_ads_package_unavailable',
			'laao_ads_package_misconfigured'   => 'laao-ads-packages',
			'laao_ads_creatives_incomplete'    => 'laao-ads-destinations',
			'laao_ads_campaign_invalid'        => 'laao-ads-readiness-heading',
			default                            => '',
		};
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
				$end_of_day ? 'laao_ads_end_date_invalid' : 'laao_ads_start_date_invalid',
				__( 'Enter a valid date in the required format.', 'laao-advertiser-portal' ),
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
			esc_html__( 'You do not have permission to do that.', 'laao-advertiser-portal' ),
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
		$args = array( 'laao_ads_notice' => $notice );

		if ( null !== $error ) {
			$args['laao_ads_error'] = sanitize_key( (string) $error->get_error_code() );
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}
}
