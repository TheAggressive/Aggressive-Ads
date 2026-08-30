<?php
/**
 * The staff campaign-review interface.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Security\Capabilities;

/**
 * Registers and serves the review queue without exposing the private CPT UI.
 *
 * A purpose-built screen keeps staff on the workflow: there is no generic
 * post-status dropdown that can bypass the state machine and no custom-fields
 * box that can leak or overwrite protected metadata.
 *
 * There are no admin-post handlers any more. Every decision goes to
 * `REST\Review_Controller` or, for a status change, to `Transitions_Controller`
 * — one authenticated path per decision rather than two that have to be kept in
 * agreement. What is left here is the menu, the assets and the mount point.
 *
 * Unlike the other converted admin screens this one keeps the plugin's own
 * design system rather than moving to core's component set. `src/styles/admin.css`
 * exists for these two views and is contrast-gated; replacing it would be a
 * decision about the product's visual direction, not part of moving the writes.
 */
final class Review_Screen implements Service {

	public const MENU_SLUG = 'aggr-review';

	/**
	 * This screen's hook suffix, assigned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Review_Data $data Screen data.
	 */
	public function __construct( private readonly Review_Data $data ) {
	}

	/**
	 * Attaches the menu and assets.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds one constrained workflow screen rather than enabling generic CPT UI.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Campaign review', 'aggressive-ads' ),
			$this->menu_title(),
			Capabilities::REVIEW_CAMPAIGNS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * The menu label, carrying a count of what is waiting.
	 *
	 * Core's own bubble markup, so it inherits the styling and the screen-reader
	 * treatment WordPress already applies to Comments and Updates rather than
	 * inventing a second convention.
	 *
	 * The badge exists because a request that nobody is told about is a request
	 * that does not get answered. Advertisers could submit a change to a running
	 * campaign and staff had no tab, no count and no notification carrying it —
	 * the work reached the database and stopped there.
	 *
	 * Counted only for a user who could act on it: rendering "3" to somebody
	 * whose queue would show nothing is worse than rendering nothing.
	 *
	 * @return string
	 */
	private function menu_title(): string {
		$label = __( 'Review', 'aggressive-ads' );

		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return $label;
		}

		$waiting = $this->data->pending_decision_count();

		if ( $waiting < 1 ) {
			return $label;
		}

		return sprintf(
			/* translators: 1: menu label, 2: number of items awaiting a decision. */
			__( '%1$s %2$s', 'aggressive-ads' ),
			$label,
			sprintf(
				'<span class="awaiting-mod"><span class="pending-count">%s</span></span>',
				esc_html( number_format_i18n( $waiting ) )
			)
		);
	}

	/**
	 * Loads the shared design tokens, the admin layout and the screen's bundle.
	 *
	 * Enqueuing belongs on this hook rather than inside the render callback: a
	 * callback runs after the document head has been sent, so a stylesheet asked
	 * for there survives only because core prints late styles in the footer, and
	 * flashes an unstyled screen while it does.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$this->enqueue_style( Assets::HANDLE, Assets::STYLE_PORTAL );
		$this->enqueue_style( 'aggr-review', Assets::STYLE_ADMIN, array( Assets::HANDLE ) );

		$asset = AGGR_PLUGIN_DIR . 'dist/admin/review.asset.php';

		if ( ! is_file( $asset ) ) {
			return;
		}

		$meta = require $asset;

		wp_enqueue_script(
			'aggr-review-screen',
			AGGR_PLUGIN_URL . 'dist/admin/review.js',
			is_array( $meta['dependencies'] ?? null ) ? $meta['dependencies'] : array(),
			is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION,
			true
		);
	}

	/**
	 * Renders the authorized review interface.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		$this->render_screen();
	}

	/**
	 * Prints the mount point and the state it opens on.
	 *
	 * The first view is server-rendered *state*, not markup: the queue or the
	 * campaign named in the URL travels in the payload so the screen draws
	 * without a round trip, and a bookmarked campaign opens on that campaign.
	 *
	 * @return void
	 */
	private function render_screen(): void {
		if ( ! is_file( AGGR_PLUGIN_DIR . 'dist/admin/review.asset.php' ) ) {
			printf(
				'<div class="wrap"><h1>%1$s</h1><div class="notice notice-error"><p>%2$s</p></div></div>',
				esc_html__( 'Campaign review', 'aggressive-ads' ),
				esc_html__( 'The review screen has not been built. Run “pnpm build” and reload.', 'aggressive-ads' )
			);

			return;
		}

		$filter      = $this->request_filter();
		$page        = $this->request_page();
		$campaign_id = $this->request_campaign_id();

		$payload = array(
			'filter'      => $filter,
			'paged'       => $page,
			'campaignId'  => $campaign_id,
			'queueUrl'    => self::queue_url(),
			'restPath'    => '/' . Creative_File_Controller::NAMESPACE . '/review',
			'tabs'        => $this->data->tabs(),
			'queue'       => $campaign_id > 0 ? self::empty_queue( $page ) : $this->data->queue( $filter, $page ),
			'campaign'    => $campaign_id > 0 ? $this->data->campaign( $campaign_id ) : null,
			// Only the queue offers creation, and the detail view is opened
			// straight from a link — listing every advertiser there would put a
			// query and a payload behind a control that is not on the screen.
			'advertisers' => $campaign_id > 0 ? array() : $this->data->advertisers(),
			'portalBase'  => Routes::url( 'campaigns' ),
			'i18n'        => self::strings(),
		);

		printf(
			'<div class="wrap aggr-portal aggr-admin"><noscript><div class="notice notice-error"><p>%1$s</p></div></noscript><div id="aggr-review-root" data-aggr-review="%2$s"></div></div>',
			esc_html__( 'The campaign review screen needs JavaScript enabled.', 'aggressive-ads' ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);
	}

	/**
	 * A queue placeholder for the campaign view.
	 *
	 * Opening straight onto one campaign should not also run the queue's paged
	 * query for a list nobody is about to look at.
	 *
	 * @param int $page Current page.
	 * @return array{rows: array<int, mixed>, total: int, pages: int, page: int}
	 */
	private static function empty_queue( int $page ): array {
		return array(
			'rows'  => array(),
			'total' => 0,
			'pages' => 1,
			'page'  => max( 1, $page ),
		);
	}

	/**
	 * Every string the screen renders.
	 *
	 * They live here rather than in the .tsx because `wp i18n make-pot` does not
	 * parse TypeScript: an `__()` call over there would compile, run, and
	 * produce no catalog entry at all.
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return array(
			'queueTitle'               => __( 'Campaign review', 'aggressive-ads' ),
			'queueLede'                => __( 'Review advertiser submissions, provide clear feedback, and approve campaigns into the live set.', 'aggressive-ads' ),
			'tabsLabel'                => __( 'Review queue filters', 'aggressive-ads' ),
			'pagesLabel'               => __( 'Campaign review pages', 'aggressive-ads' ),
			'previous'                 => __( 'Previous', 'aggressive-ads' ),
			'next'                     => __( 'Next', 'aggressive-ads' ),
			/* translators: 1: current page number. 2: total number of pages. */
			'pageOf'                   => __( 'Page %1$s of %2$s', 'aggressive-ads' ),
			/* translators: %s: number of campaigns in the selected queue. */
			'campaignsCount'           => __( 'Campaigns (%s)', 'aggressive-ads' ),
			'queueEmptyTitle'          => __( 'Nothing is waiting here.', 'aggressive-ads' ),
			'queueEmptyBody'           => __( 'Campaigns will appear in this view as their status changes.', 'aggressive-ads' ),
			'queueTableLabel'          => __( 'Campaign review queue table', 'aggressive-ads' ),
			'colCampaign'              => __( 'Campaign', 'aggressive-ads' ),
			'colAdvertiser'            => __( 'Advertiser', 'aggressive-ads' ),
			'colPlacement'             => __( 'Placement', 'aggressive-ads' ),
			'colStatus'                => __( 'Status', 'aggressive-ads' ),
			'colSubmitted'             => __( 'Submitted', 'aggressive-ads' ),
			'colReviewer'              => __( 'Reviewer', 'aggressive-ads' ),
			'colUpdates'               => __( 'Ad updates', 'aggressive-ads' ),
			'unassigned'               => __( 'Unassigned', 'aggressive-ads' ),
			'backToQueue'              => __( 'Back to campaign review', 'aggressive-ads' ),
			'createCampaign'           => __( 'Create campaign', 'aggressive-ads' ),
			'createForAdvertiser'      => __( 'Create a campaign for an advertiser', 'aggressive-ads' ),
			'advertiserLabel'          => __( 'Advertiser', 'aggressive-ads' ),
			'advertiserChoose'         => __( 'Choose an advertiser', 'aggressive-ads' ),
			'campaignNameLabel'        => __( 'Campaign name', 'aggressive-ads' ),
			'campaignNameHint'         => __( 'Optional. You can change this in the campaign.', 'aggressive-ads' ),
			'createAndOpen'            => __( 'Create and open', 'aggressive-ads' ),
			'noAdvertisers'            => __( 'No active advertisers yet. Add one from Organizations first.', 'aggressive-ads' ),
			'editCampaign'             => __( 'Edit', 'aggressive-ads' ),
			'campaignSummary'          => __( 'Campaign summary', 'aggressive-ads' ),
			'deliveryStrategy'         => __( 'Delivery strategy', 'aggressive-ads' ),
			'lineItem'                 => __( 'Line item', 'aggressive-ads' ),
			'status'                   => __( 'Status', 'aggressive-ads' ),
			'pricing'                  => __( 'Pricing', 'aggressive-ads' ),
			'goal'                     => __( 'Goal', 'aggressive-ads' ),
			'pacing'                   => __( 'Pacing', 'aggressive-ads' ),
			'organization'             => __( 'Organization', 'aggressive-ads' ),
			'placements'               => __( 'Placements', 'aggressive-ads' ),
			'schedule'                 => __( 'Schedule', 'aggressive-ads' ),
			'reviewer'                 => __( 'Reviewer', 'aggressive-ads' ),
			'submission'               => __( 'Submission', 'aggressive-ads' ),
			'notSubmitted'             => __( 'Not submitted', 'aggressive-ads' ),
			'revision'                 => __( 'Revision', 'aggressive-ads' ),
			'advertiserFacingFeedback' => __( 'Advertiser-facing feedback', 'aggressive-ads' ),
			'creativeReview'           => __( 'Creative review', 'aggressive-ads' ),
			'noCreativeTitle'          => __( 'No creative uploaded', 'aggressive-ads' ),
			'noCreativeBody'           => __( 'This campaign cannot be approved until every placement has creative.', 'aggressive-ads' ),
			'requiredSize'             => __( 'Required size', 'aggressive-ads' ),
			'uploadedSize'             => __( 'Uploaded size', 'aggressive-ads' ),
			'artworkUnchanged'         => __( 'Artwork unchanged — only the text differs', 'aggressive-ads' ),
			'altText'                  => __( 'Alt text', 'aggressive-ads' ),
			'destination'              => __( 'Destination', 'aggressive-ads' ),
			'currentDestination'       => __( 'Current destination', 'aggressive-ads' ),
			'proposedDestination'      => __( 'Proposed destination', 'aggressive-ads' ),
			'currentAlt'               => __( 'Current alt text', 'aggressive-ads' ),
			'proposedAlt'              => __( 'Proposed alt text', 'aggressive-ads' ),
			'advertiserAsked'          => __( 'The advertiser has asked for something', 'aggressive-ads' ),
			/* translators: %s: the requested action, already translated. */
			'requested'                => __( 'Requested: %s', 'aggressive-ads' ),
			'requestHint'              => __( 'Use the review actions below to carry this out. The request clears itself once the campaign moves, or you can decline it with an explanation.', 'aggressive-ads' ),
			'declineExplanation'       => __( 'Explanation for the advertiser', 'aggressive-ads' ),
			'declineRequest'           => __( 'Decline request', 'aggressive-ads' ),
			'requestedChanges'         => __( 'Requested campaign changes', 'aggressive-ads' ),
			'requestedChangesLede'     => __( 'The campaign keeps running exactly as approved until you decide. Approving writes these values and refreshes delivery immediately.', 'aggressive-ads' ),
			'field'                    => __( 'Field', 'aggressive-ads' ),
			'currently'                => __( 'Currently', 'aggressive-ads' ),
			'requestedCol'             => __( 'Requested', 'aggressive-ads' ),
			'placementChangeWarn'      => __( 'This change alters the placements.', 'aggressive-ads' ),
			'placementChangeBody'      => __( 'The existing creative will no longer match the required size, and the campaign will not serve until a new one is uploaded and reviewed.', 'aggressive-ads' ),
			'approveChanges'           => __( 'Approve changes', 'aggressive-ads' ),
			'rejectChanges'            => __( 'Reject changes', 'aggressive-ads' ),
			'rejectionFeedback'        => __( 'Feedback required when rejecting', 'aggressive-ads' ),
			'pendingUpdates'           => __( 'Pending ad updates', 'aggressive-ads' ),
			'pendingUpdatesLede'       => __( 'The current ads remain in rotation until an update below is approved.', 'aggressive-ads' ),
			'approveReplace'           => __( 'Approve and replace', 'aggressive-ads' ),
			'rejectUpdate'             => __( 'Reject update', 'aggressive-ads' ),
			'reviewActions'            => __( 'Review actions', 'aggressive-ads' ),
			'noActions'                => __( 'No staff action is available from this status.', 'aggressive-ads' ),
			'advertiserFeedback'       => __( 'Feedback the advertiser will see', 'aggressive-ads' ),
			'cancel'                   => __( 'Cancel', 'aggressive-ads' ),
			'close'                    => __( 'Close', 'aggressive-ads' ),
			'deliveryPolicy'           => __( 'Delivery policy', 'aggressive-ads' ),
			'priority'                 => __( 'Priority (lower wins)', 'aggressive-ads' ),
			'pacingMode'               => __( 'Pacing', 'aggressive-ads' ),
			'pacingEven'               => __( 'Even', 'aggressive-ads' ),
			'pacingAsap'               => __( 'As fast as possible', 'aggressive-ads' ),
			'dailyCap'                 => __( 'Daily impression cap (0 for none)', 'aggressive-ads' ),
			'lifetimeCap'              => __( 'Lifetime impression cap (0 for none)', 'aggressive-ads' ),
			'targetingRules'           => __( 'Targeting rules (JSON)', 'aggressive-ads' ),
			'targetingHelp'            => __( 'Leave empty to target everyone. A rule needs a dimension, an operator and a value.', 'aggressive-ads' ),
			'frequencyPolicy'          => __( 'Frequency capping (JSON)', 'aggressive-ads' ),
			'frequencyHelp'            => __( 'Leave empty for no cap. Set enabled, max_impressions, window and level.', 'aggressive-ads' ),
			'deliverySettings'         => __( 'Dayparts and timezone (JSON)', 'aggressive-ads' ),
			'deliverySettingsHelp'     => __( 'Leave empty to run at any hour. Set dayparts and an optional timezone.', 'aggressive-ads' ),
			'saveDeliveryPolicy'       => __( 'Save delivery policy', 'aggressive-ads' ),
			'deliveryPolicySaved'      => __( 'Delivery policy saved.', 'aggressive-ads' ),
			'deliveryPolicyNotJson'    => __( 'One of the JSON fields is not valid JSON. Fix it and save again.', 'aggressive-ads' ),
			'internalNotes'            => __( 'Internal notes', 'aggressive-ads' ),
			'staffOnly'                => __( 'Visible to staff only', 'aggressive-ads' ),
			'saveInternalNotes'        => __( 'Save internal notes', 'aggressive-ads' ),
			'auditTimeline'            => __( 'Audit timeline', 'aggressive-ads' ),
			'noAudit'                  => __( 'No audit events have been recorded for this campaign.', 'aggressive-ads' ),
			'unknownUser'              => __( 'Unknown user', 'aggressive-ads' ),
			'transitioned'             => __( 'Campaign updated.', 'aggressive-ads' ),
			'notesSaved'               => __( 'Internal notes saved.', 'aggressive-ads' ),
			'changesApproved'          => __( 'Campaign changes approved.', 'aggressive-ads' ),
			'changesRejected'          => __( 'Campaign changes rejected.', 'aggressive-ads' ),
			'requestDeclined'          => __( 'The advertiser request was declined.', 'aggressive-ads' ),
			'updateApproved'           => __( 'Ad update approved.', 'aggressive-ads' ),
			'publishCreative'          => __( 'Publish this advertisement', 'aggressive-ads' ),
			'publishCreativeHint'      => __( 'Added after this campaign went live, so it has not been published yet and is not being served.', 'aggressive-ads' ),
			'creativePublished'        => __( 'Advertisement published.', 'aggressive-ads' ),
			'rejectCreative'           => __( 'Do not publish', 'aggressive-ads' ),
			'rejectCreativeReason'     => __( 'Why not? The advertiser will read this.', 'aggressive-ads' ),
			'creativeRejected'         => __( 'Advertisement turned down.', 'aggressive-ads' ),
			'updateRejected'           => __( 'Ad update rejected.', 'aggressive-ads' ),
			'saveFailed'               => __( 'That change could not be saved.', 'aggressive-ads' ),
			'retry'                    => __( 'Try again', 'aggressive-ads' ),
		);
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
	 * Still a real URL, and still what the notification emails link to. The
	 * screen reads these parameters on load and keeps them in step as the
	 * reviewer moves, so a link pasted to a colleague opens the campaign it
	 * names rather than the queue.
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
	 * Enqueues one local stylesheet with cache-safe versioning.
	 *
	 * @param string             $handle       Style handle.
	 * @param string             $relative     Plugin-relative path.
	 * @param array<int, string> $dependencies Style dependencies.
	 * @return void
	 */
	private function enqueue_style( string $handle, string $relative, array $dependencies = array() ): void {
		$path = AGGR_PLUGIN_DIR . $relative;

		if ( ! is_file( $path ) ) {
			return;
		}

		$mtime = filemtime( $path );

		wp_enqueue_style(
			$handle,
			AGGR_PLUGIN_URL . $relative,
			$dependencies,
			false === $mtime ? AGGR_VERSION : (string) $mtime
		);
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
}
