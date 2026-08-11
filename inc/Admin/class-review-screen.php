<?php
/**
 * The staff campaign-review interface.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Admin;

use LAAO_Advertiser_Portal\Assets\Assets;
use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Workflow\Review_Actions;
use WP_Error;

/**
 * Registers and serves the review queue without exposing the private CPT UI.
 *
 * A purpose-built screen keeps staff on the workflow: there is no generic
 * post-status dropdown that can bypass the state machine and no custom-fields
 * box that can leak or overwrite protected metadata.
 */
final class Review_Screen implements Service {

	public const MENU_SLUG         = 'laao-ads-review';
	public const TRANSITION_ACTION = 'laao_ads_review_transition';
	public const NOTES_ACTION      = 'laao_ads_review_notes';

	/**
	 * This screen's hook suffix, assigned by add_menu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Review_Data    $data    Screen data.
	 * @param Review_Actions $actions Review writes.
	 */
	public function __construct(
		private readonly Review_Data $data,
		private readonly Review_Actions $actions
	) {
	}

	/**
	 * Attaches the menu, assets and form handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::TRANSITION_ACTION, array( $this, 'handle_transition' ) );
		add_action( 'admin_post_' . self::NOTES_ACTION, array( $this, 'handle_notes' ) );
	}

	/**
	 * Adds one constrained workflow screen rather than enabling generic CPT UI.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$hook = add_menu_page(
			__( 'Ad Review', 'laao-advertiser-portal' ),
			__( 'Ad Review', 'laao-advertiser-portal' ),
			Capabilities::REVIEW_CAMPAIGNS,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-megaphone',
			26
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the shared design tokens and the admin layout only on this screen.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$this->enqueue_style( Assets::HANDLE, 'assets/portal.css' );
		$this->enqueue_style( 'laao-ads-review', 'assets/admin.css', array( Assets::HANDLE ) );
	}

	/**
	 * Renders either the queue or one campaign.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'laao-advertiser-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		$laao_ads_filter = $this->request_filter();
		$laao_ads_page   = $this->request_page();
		$campaign_id     = $this->request_campaign_id();
		$laao_ads_notice = $this->request_notice();

		if ( $campaign_id > 0 ) {
			$laao_ads_campaign = $this->data->campaign( $campaign_id );
			require LAAO_ADS_PLUGIN_DIR . 'templates/admin/review-campaign.php';

			return;
		}

		$laao_ads_tabs  = $this->data->tabs();
		$laao_ads_queue = $this->data->queue( $laao_ads_filter, $laao_ads_page );

		require LAAO_ADS_PLUGIN_DIR . 'templates/admin/review-queue.php';
	}

	/**
	 * Handles one lifecycle action.
	 *
	 * @return void
	 */
	public function handle_transition(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'laao-advertiser-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		$campaign_id = $this->posted_campaign_id();

		check_admin_referer( self::nonce_action( $campaign_id ) );

		$to     = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';
		$notes  = isset( $_POST['review_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_notes'] ) ) : '';
		$result = $this->process_transition( $campaign_id, $to, $notes );

		$this->redirect_after( $campaign_id, $result, 'transitioned' );
	}

	/**
	 * Handles the staff-only notes form.
	 *
	 * @return void
	 */
	public function handle_notes(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'laao-advertiser-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		$campaign_id = $this->posted_campaign_id();

		check_admin_referer( self::notes_nonce_action( $campaign_id ) );

		$notes  = isset( $_POST['internal_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_notes'] ) ) : '';
		$result = $this->process_notes( $campaign_id, $notes );

		$this->redirect_after( $campaign_id, $result, 'notes_saved' );
	}

	/**
	 * Validates delivery-level transition input before calling the workflow.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $to          Target status.
	 * @param string $notes       Advertiser-facing feedback.
	 * @return true|WP_Error
	 */
	public function process_transition( int $campaign_id, string $to, string $notes = '' ) {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return new WP_Error( 'laao_ads_forbidden', __( 'You do not have permission to do that.', 'laao-advertiser-portal' ) );
		}

		if ( $campaign_id <= 0 || ! Post_Statuses::is_valid( $to ) ) {
			return new WP_Error( 'laao_ads_invalid_request', __( 'That review action is not valid.', 'laao-advertiser-portal' ) );
		}

		return $this->actions->transition( $campaign_id, $to, $notes );
	}

	/**
	 * Validates delivery-level note input before calling the workflow.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Staff-only notes.
	 * @return true|WP_Error
	 */
	public function process_notes( int $campaign_id, string $notes ) {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return new WP_Error( 'laao_ads_forbidden', __( 'You do not have permission to do that.', 'laao-advertiser-portal' ) );
		}

		return $this->actions->save_internal_notes( $campaign_id, $notes );
	}

	/**
	 * URL for the queue, optionally filtered and paged.
	 *
	 * @param string $filter Queue filter.
	 * @param int    $page   Page number.
	 * @return string
	 */
	public static function queue_url( string $filter = Review_Data::DEFAULT_FILTER, int $page = 1 ): string {
		$args = array( 'page' => self::MENU_SLUG );

		if ( Review_Data::is_filter( $filter ) && Review_Data::DEFAULT_FILTER !== $filter ) {
			$args['filter'] = $filter;
		}

		if ( $page > 1 ) {
			$args['paged'] = $page;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * URL for one campaign in the review interface.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $filter      Queue filter to return to.
	 * @param int    $page        Queue page to return to.
	 * @return string
	 */
	public static function campaign_url( int $campaign_id, string $filter = Review_Data::DEFAULT_FILTER, int $page = 1 ): string {
		return add_query_arg(
			array(
				'campaign' => max( 0, $campaign_id ),
				'filter'   => Review_Data::is_filter( $filter ) ? $filter : Review_Data::DEFAULT_FILTER,
				'paged'    => max( 1, $page ),
			),
			self::queue_url()
		);
	}

	/**
	 * Nonce action for a campaign transition.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function nonce_action( int $campaign_id ): string {
		return self::TRANSITION_ACTION . '_' . $campaign_id;
	}

	/**
	 * Nonce action for staff-only notes.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function notes_nonce_action( int $campaign_id ): string {
		return self::NOTES_ACTION . '_' . $campaign_id;
	}

	/**
	 * A result notice safe to show after a redirect.
	 *
	 * @param string $result success or error.
	 * @param string $code   Stable result code.
	 * @param string $detail The workflow's own message, when one survived the redirect.
	 * @return array{type: string, message: string, detail: string, action_url: string, action_label: string}|null
	 */
	public static function notice_for( string $result, string $code, string $detail = '' ): ?array {
		if ( 'success' === $result ) {
			return array(
				'type'         => 'success',
				'message'      => 'notes_saved' === $code
					? __( 'Internal notes saved.', 'laao-advertiser-portal' )
					: __( 'Campaign status updated.', 'laao-advertiser-portal' ),
				'detail'       => '',
				'action_url'   => '',
				'action_label' => '',
			);
		}

		if ( 'error' !== $result ) {
			return null;
		}

		/*
		 * Every code a reviewer can reach, said in terms of what to do next.
		 *
		 * The default used to catch most of these, and the one that mattered
		 * most was the placement mapping: approval fails closed when a
		 * placement has no AdSanity ad group, which is correct, but the
		 * reviewer was told only that "the campaign could not be updated" and
		 * had no way to discover that the fix lives on a different screen.
		 */
		$message = match ( $code ) {
			'laao_ads_forbidden'                => __( 'You do not have permission to perform that review action.', 'laao-advertiser-portal' ),
			'laao_ads_review_notes_required'    => __( 'Add advertiser-facing feedback before requesting changes or rejecting.', 'laao-advertiser-portal' ),
			'laao_ads_publication_incomplete'   => __( 'Some ads could not be published. Successful ads were kept, so retrying will not duplicate them.', 'laao-advertiser-portal' ),
			'laao_ads_campaign_not_found'       => __( 'The campaign could not be found.', 'laao-advertiser-portal' ),
			'laao_ads_placement_unmapped'       => __( 'This campaign cannot be published until every placement is mapped to an AdSanity ad group.', 'laao-advertiser-portal' ),
			'laao_ads_invalid_adgroup'          => __( 'A placement points at an ad group AdSanity no longer has. Re-map it and try again.', 'laao-advertiser-portal' ),
			'laao_ads_provider_unavailable',
			'laao_ads_provider_groups_unavailable' => __( 'AdSanity is not available, so nothing can be published right now. The campaign has not been changed.', 'laao-advertiser-portal' ),
			'laao_ads_campaign_invalid',
			'laao_ads_creatives_incomplete'     => __( 'The campaign no longer passes its own submission checks. Request changes from the advertiser.', 'laao-advertiser-portal' ),
			'laao_ads_campaign_claimed'         => __( 'Another reviewer has claimed this campaign. Reload the queue to see who.', 'laao-advertiser-portal' ),
			'laao_ads_illegal_transition'       => __( 'That is not a move this campaign can make from its current status. Reload the page.', 'laao-advertiser-portal' ),
			'laao_ads_promote_failed'           => __( 'A creative file could not be moved into the media library, so nothing was published.', 'laao-advertiser-portal' ),
			'laao_ads_nothing_to_publish'       => __( 'This campaign has no creative to publish.', 'laao-advertiser-portal' ),
			'laao_ads_organization_inactive'    => __( 'The advertising organization is suspended, so this campaign cannot go live.', 'laao-advertiser-portal' ),
			default                             => __( 'The campaign could not be updated. Review its requirements and try again.', 'laao-advertiser-portal' ),
		};

		/*
		 * The only failures a reviewer fixes themselves are configuration ones,
		 * and the screen that fixes them is not this one. Everything else is
		 * the advertiser's to correct or nobody's, so it gets no link.
		 */
		$mapping_codes = array( 'laao_ads_placement_unmapped', 'laao_ads_invalid_adgroup' );

		return array(
			'type'         => 'error',
			'message'      => $message,
			'detail'       => $detail,
			'action_url'   => in_array( $code, $mapping_codes, true ) ? Placement_Mapping_Screen::url() : '',
			'action_label' => in_array( $code, $mapping_codes, true )
				? __( 'Open placement mapping', 'laao-advertiser-portal' )
				: '',
		);
	}

	/**
	 * Enqueues one local stylesheet with cache-safe versioning.
	 *
	 * @param string             $handle       Style handle.
	 * @param string             $relative     Plugin-relative path.
	 * @param array<int, string> $dependencies Style dependencies.
	 * @return void
	 */
	private function enqueue_style( string $handle, string $relative, array $dependencies = array() ): void {
		$path = LAAO_ADS_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return;
		}

		$mtime = filemtime( $path );

		wp_enqueue_style(
			$handle,
			LAAO_ADS_PLUGIN_URL . $relative,
			$dependencies,
			false === $mtime ? LAAO_ADS_VERSION : (string) $mtime
		);
	}

	/**
	 * Redirects to the campaign with only a stable result code in the URL.
	 *
	 * @param int           $campaign_id Campaign post id.
	 * @param true|WP_Error $result      Workflow result.
	 * @param string        $success     Success code.
	 * @return never
	 */
	private function redirect_after( int $campaign_id, bool|WP_Error $result, string $success ): never {
		$is_error = is_wp_error( $result );
		$code     = $is_error ? (string) $result->get_error_code() : $success;

		if ( $is_error ) {
			$this->stash_detail( $campaign_id, (string) $result->get_error_message() );
		}

		$url = add_query_arg(
			array(
				'laao_ads_result' => $is_error ? 'error' : 'success',
				'laao_ads_code'   => sanitize_key( $code ),
			),
			self::campaign_url( $campaign_id, $this->posted_filter(), $this->posted_page() )
		);

		wp_safe_redirect( $url, 303 );

		exit;
	}

	/**
	 * Notice selected from allowlisted query values.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function request_notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted result state used only to select a fixed message.
		$result = isset( $_GET['laao_ads_result'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_result'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted result state used only to select a fixed message.
		$code = isset( $_GET['laao_ads_code'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_code'] ) ) : '';

		return self::notice_for( $result, $code, $this->take_detail( $this->request_campaign_id() ) );
	}

	/**
	 * Keeps a failure's own words for one redirect.
	 *
	 * Only the stable code travels in the URL, which is right — a message in a
	 * query string is a message an attacker can choose. But the code alone threw
	 * away the useful half: the mapping resolver names every placement that
	 * needs an ad group, and the reviewer saw "The campaign could not be
	 * updated." A transient scoped to this user and this campaign carries the
	 * detail across the redirect without letting anyone else write it.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $detail      The workflow's own message.
	 * @return void
	 */
	private function stash_detail( int $campaign_id, string $detail ): void {
		if ( '' === trim( $detail ) ) {
			return;
		}

		set_transient( self::detail_key( $campaign_id ), $detail, MINUTE_IN_SECONDS );
	}

	/**
	 * Reads and clears the stashed detail.
	 *
	 * Cleared on read so a reload does not repeat a failure the reviewer has
	 * already dealt with.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	private function take_detail( int $campaign_id ): string {
		if ( $campaign_id <= 0 ) {
			return '';
		}

		$key    = self::detail_key( $campaign_id );
		$detail = get_transient( $key );

		if ( ! is_string( $detail ) || '' === $detail ) {
			return '';
		}

		delete_transient( $key );

		return $detail;
	}

	/**
	 * The transient key for one reviewer's view of one campaign.
	 *
	 * Scoped by user as well as campaign: two reviewers acting on the same
	 * campaign must not read each other's failures.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	private static function detail_key( int $campaign_id ): string {
		return 'laao_ads_review_detail_' . get_current_user_id() . '_' . $campaign_id;
	}

	/**
	 * Campaign selected in the read-only admin URL.
	 *
	 * @return int
	 */
	private function request_campaign_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation; object authorization occurs before data is returned.
		return isset( $_GET['campaign'] ) ? absint( wp_unslash( $_GET['campaign'] ) ) : 0;
	}

	/**
	 * Page selected in the read-only admin URL.
	 *
	 * @return int
	 */
	private function request_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination state.
		return isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
	}

	/**
	 * Filter selected in the read-only admin URL.
	 *
	 * @return string
	 */
	private function request_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation constrained to Review_Data's allowlist.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : Review_Data::DEFAULT_FILTER;

		return Review_Data::is_filter( $filter ) ? $filter : Review_Data::DEFAULT_FILTER;
	}

	/**
	 * Campaign id from a write request.
	 *
	 * @return int
	 */
	private function posted_campaign_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The id selects the per-campaign nonce action and is not used until check_admin_referer() passes.
		return isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0;
	}

	/**
	 * Queue page preserved by a verified form.
	 *
	 * @return int
	 */
	private function posted_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Both callers verify their per-campaign nonce before redirecting.
		return isset( $_POST['queue_page'] ) ? max( 1, absint( wp_unslash( $_POST['queue_page'] ) ) ) : 1;
	}

	/**
	 * Queue filter preserved by a verified form.
	 *
	 * @return string
	 */
	private function posted_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Both callers verify their per-campaign nonce before redirecting; the value is also allowlisted.
		$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : Review_Data::DEFAULT_FILTER;

		return Review_Data::is_filter( $filter ) ? $filter : Review_Data::DEFAULT_FILTER;
	}
}
