<?php
/**
 * Progressive creative form delivery.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Assignment_Editor;
use Aggressive\Ads\Workflow\Creative_Change_Manager;
use Aggressive\Ads\Workflow\Creative_Copies;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Share_Editor;
use WP_Error;

/**
 * Handles creative forms without creating a second workflow policy.
 */
final class Creative_Actions implements Service {

	public const UPLOAD_ACTION      = 'aggr_upload_creative';
	public const REMOVE_ACTION      = 'aggr_remove_creative';
	public const REPLACE_ACTION     = 'aggr_request_creative_replacement';
	public const WITHDRAW_ACTION    = 'aggr_withdraw_creative_replacement';
	public const WEIGHT_ACTION      = 'aggr_set_creative_weight';
	public const STATUS_ACTION      = 'aggr_set_creative_status';
	public const WINDOW_ACTION      = 'aggr_set_creative_window';
	public const DESTINATION_ACTION = 'aggr_set_creative_destination';
	public const ARTWORK_ACTION     = 'aggr_replace_creative_artwork';
	public const COPY_ACTION        = 'aggr_copy_creative';

	/**
	 * Constructor.
	 *
	 * @param Creative_Manager        $manager     Shared draft creative workflow.
	 * @param Creative_Change_Manager $changes     Reviewed published-ad changes.
	 * @param Assignment_Editor       $assignments Delivery settings on one assignment.
	 * @param Creative_View_Data      $creatives   Render-ready creative rows, for restating shares.
	 * @param Creative_Copies         $copies      One file on several placements of the same size.
	 * @param Share_Editor            $shares      How much of a placement each creative takes.
	 */
	public function __construct(
		private readonly Creative_Manager $manager,
		private readonly Creative_Change_Manager $changes,
		private readonly Assignment_Editor $assignments,
		private readonly Creative_View_Data $creatives,
		private readonly Creative_Copies $copies,
		private readonly Share_Editor $shares
	) {
	}

	/**
	 * Attaches authenticated form handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::UPLOAD_ACTION, array( $this, 'handle_upload' ) );
		add_action( 'admin_post_' . self::REMOVE_ACTION, array( $this, 'handle_remove' ) );
		add_action( 'admin_post_' . self::REPLACE_ACTION, array( $this, 'handle_replace' ) );
		add_action( 'admin_post_' . self::WITHDRAW_ACTION, array( $this, 'handle_withdraw' ) );
		add_action( 'admin_post_' . self::WEIGHT_ACTION, array( $this, 'handle_weight' ) );
		add_action( 'admin_post_' . self::STATUS_ACTION, array( $this, 'handle_status' ) );
		add_action( 'admin_post_' . self::WINDOW_ACTION, array( $this, 'handle_window' ) );
		add_action( 'admin_post_' . self::DESTINATION_ACTION, array( $this, 'handle_destination' ) );
		add_action( 'admin_post_' . self::ARTWORK_ACTION, array( $this, 'handle_artwork' ) );
		add_action( 'admin_post_' . self::COPY_ACTION, array( $this, 'handle_copy' ) );
	}

	/**
	 * Requests review of a published-ad replacement.
	 *
	 * @return void
	 */
	public function handle_replace(): void {
		$this->assert_portal_access();

		$creative_id = isset( $_POST['creative_id'] ) ? absint( $_POST['creative_id'] ) : 0;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::replace_nonce_action( $creative_id ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Hostile bytes are inspected by Creative_Uploader; sanitizing the temporary path corrupts it.
		$file      = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();
		$click_url = isset( $_POST['click_url'] ) ? sanitize_text_field( wp_unslash( $_POST['click_url'] ) ) : '';
		$alt_text  = isset( $_POST['alt_text'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) : '';
		$result    = $this->process_replace( $creative_id, $file, $click_url, $alt_text );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result );
		}

		Creative_Feedback::after( $campaign_id, 'creative_update_requested' );
	}

	/**
	 * Testable replacement entry point, artwork optional.
	 *
	 * **A destination-only correction must not require re-uploading artwork
	 * that has not changed.** `request_text_change()` exists for exactly that
	 * and the REST route has always branched to it; the portal did not, so the
	 * one screen an advertiser actually uses made a live campaign's typo
	 * unfixable without a file they had no reason to touch. Both paths stage a
	 * reviewed revision and both leave the current ad serving.
	 *
	 * @param int                  $creative_id Creative post id.
	 * @param array<string, mixed> $file        One $_FILES entry, empty when none.
	 * @param string               $click_url   Destination.
	 * @param string               $alt_text    Alternative text.
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_replace( int $creative_id, array $file, string $click_url, string $alt_text ): array|WP_Error {
		return self::has_upload( $file )
			? $this->changes->request( $creative_id, $file, $click_url, $alt_text )
			: $this->changes->request_text_change( $creative_id, $click_url, $alt_text );
	}

	/**
	 * Whether a `$_FILES` entry actually carries a file.
	 *
	 * A form that submits an untouched file input still produces an entry —
	 * PHP fills it with `UPLOAD_ERR_NO_FILE` and an empty name — so an
	 * emptiness test on the array itself is always false here. REST sees no
	 * entry at all, which is why its own check is simpler and why copying it
	 * would have sent every text-only edit down the artwork path.
	 *
	 * @param array<string, mixed> $file One $_FILES entry.
	 * @return bool
	 */
	private static function has_upload( array $file ): bool {
		if ( array() === $file ) {
			return false;
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		$tmp   = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		return UPLOAD_ERR_NO_FILE !== $error && '' !== $tmp;
	}

	/**
	 * Withdraws a pending published-ad replacement.
	 *
	 * @return void
	 */
	public function handle_withdraw(): void {
		$this->assert_portal_access();

		$replacement_id = isset( $_POST['replacement_id'] ) ? absint( $_POST['replacement_id'] ) : 0;
		$campaign_id    = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::withdraw_nonce_action( $replacement_id ) );

		$result = $this->changes->withdraw( $replacement_id );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result );
		}

		Creative_Feedback::after( $campaign_id, 'creative_update_withdrawn' );
	}

	/**
	 * Uploads one placement creative.
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		$this->assert_portal_access();

		$campaign_id  = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$placement_id = isset( $_POST['placement_id'] ) ? absint( $_POST['placement_id'] ) : 0;

		check_admin_referer( self::upload_nonce_action( $campaign_id, $placement_id ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The shared uploader validates the temporary file and ignores the claimed MIME; sanitizing a filesystem path would corrupt it before validation.
		$file      = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();
		$click_url = isset( $_POST['click_url'] ) ? sanitize_text_field( wp_unslash( $_POST['click_url'] ) ) : '';
		$alt_text  = isset( $_POST['alt_text'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) : '';
		$result    = $this->process_upload( $campaign_id, $placement_id, $file, $click_url, $alt_text );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result, $placement_id );
		}

		Creative_Feedback::after( $campaign_id, 'creative_uploaded' );
	}

	/**
	 * Removes one creative.
	 *
	 * @return void
	 */
	public function handle_remove(): void {
		$this->assert_portal_access();

		$creative_id = isset( $_POST['creative_id'] ) ? absint( $_POST['creative_id'] ) : 0;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::remove_nonce_action( $creative_id ) );

		// The same file on other placements, ticked in the dialog. Only ids the
		// manager itself finds carrying this file are acted on.
		$also   = isset( $_POST['also_remove'] ) && is_array( $_POST['also_remove'] ) ? array_map( 'absint', wp_unslash( $_POST['also_remove'] ) ) : array();
		$result = $this->process_remove( $creative_id, $also );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result );
		}

		Creative_Feedback::after( $campaign_id, 'creative_removed' );
	}

	/**
	 * Puts the file already on one placement onto another of the same size.
	 *
	 * @return void
	 */
	public function handle_copy(): void {
		$this->assert_portal_access();

		$campaign_id  = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$placement_id = isset( $_POST['placement_id'] ) ? absint( $_POST['placement_id'] ) : 0;
		$source_id    = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;

		check_admin_referer( self::copy_nonce_action( $campaign_id, $placement_id ) );

		$result = $this->process_copy( $campaign_id, $source_id, $placement_id );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result, $placement_id );
		}

		Creative_Feedback::after( $campaign_id, 'creative_uploaded' );
	}

	/**
	 * Testable copy entry point.
	 *
	 * The campaign the form was drawn for has to be the source's own. The
	 * nonce is bound to it, and without this check a valid nonce for one
	 * campaign would carry a copy request for a creative on another.
	 *
	 * @param int $campaign_id  Campaign the form belongs to.
	 * @param int $source_id    Creative whose file to reuse.
	 * @param int $placement_id Placement to put it on.
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_copy( int $campaign_id, int $source_id, int $placement_id ): array|WP_Error {
		return $this->copies->copy_to_placement( $source_id, $placement_id, $campaign_id );
	}

	/**
	 * Testable form upload entry point.
	 *
	 * @param int                  $campaign_id  Campaign post id.
	 * @param int                  $placement_id Placement post id.
	 * @param array<string, mixed> $file         One $_FILES entry.
	 * @param string               $click_url    Destination URL.
	 * @param string               $alt_text     Alternative text.
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_upload( int $campaign_id, int $placement_id, array $file, string $click_url, string $alt_text ): array|WP_Error {
		return $this->manager->upload( $campaign_id, $placement_id, $file, $click_url, $alt_text );
	}

	/**
	 * Testable form removal entry point.
	 *
	 * @param int             $creative_id Creative post id.
	 * @param array<int, int> $also        Other placements' copies of the same file to remove with it.
	 * @return true|WP_Error
	 */
	public function process_remove( int $creative_id, array $also = array() ): bool|WP_Error {
		return array() === $also
			? $this->manager->remove( $creative_id )
			: $this->copies->remove_with_copies( $creative_id, $also );
	}

	/**
	 * Nonce scoped to one campaign placement.
	 *
	 * @param int $campaign_id  Campaign post id.
	 * @param int $placement_id Placement post id.
	 * @return string
	 */
	public static function upload_nonce_action( int $campaign_id, int $placement_id ): string {
		return self::UPLOAD_ACTION . '_' . max( 0, $campaign_id ) . '_' . max( 0, $placement_id );
	}

	/**
	 * Nonce scoped to one campaign placement's copy form.
	 *
	 * Its own action rather than the upload's: the two forms post different
	 * fields, and a token for one should not be accepted by the other.
	 *
	 * @param int $campaign_id  Campaign post id.
	 * @param int $placement_id Placement post id.
	 * @return string
	 */
	public static function copy_nonce_action( int $campaign_id, int $placement_id ): string {
		return self::COPY_ACTION . '_' . max( 0, $campaign_id ) . '_' . max( 0, $placement_id );
	}

	/**
	 * Nonce scoped to one creative.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	public static function remove_nonce_action( int $creative_id ): string {
		return self::REMOVE_ACTION . '_' . max( 0, $creative_id );
	}

	/**
	 * Nonce scoped to one creative's artwork.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	public static function artwork_nonce_action( int $creative_id ): string {
		return 'aggr_creative_artwork_' . $creative_id;
	}

	/**
	 * Nonce scoped to one creative's destination.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	public static function destination_nonce_action( int $creative_id ): string {
		return 'aggr_creative_destination_' . $creative_id;
	}

	/**
	 * Nonce scoped to a current creative replacement request.
	 *
	 * @param int $creative_id Current creative id.
	 * @return string
	 */
	public static function weight_nonce_action( int $creative_id ): string {
		return self::WEIGHT_ACTION . '_' . max( 0, $creative_id );
	}

	/**
	 * Sets one creative's share of its placement.
	 *
	 * @return void
	 */
	public function handle_weight(): void {
		$this->assert_portal_access();

		$creative_id = isset( $_POST['creative_id'] ) ? absint( $_POST['creative_id'] ) : 0;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$share       = isset( $_POST['share'] ) ? absint( $_POST['share'] ) : 0;

		check_admin_referer( self::weight_nonce_action( $creative_id ) );

		$result = $this->process_weight( $creative_id, $share );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result );
		}

		Creative_Feedback::after( $campaign_id, 'creative_weight_saved', null, 0, 0, $this->share_patch( $campaign_id ) );
	}

	/**
	 * Testable share entry point.
	 *
	 * @param int $creative_id Creative post id.
	 * @param int $percent     Share of its placement, as a percentage.
	 * @return array<int, int>|WP_Error Percentage by creative id.
	 */
	public function process_weight( int $creative_id, int $percent ): array|WP_Error {
		return $this->shares->set_share( $creative_id, $percent );
	}

	/**
	 * Swaps the artwork on one creative that review has not yet accepted.
	 *
	 * @return void
	 */
	public function handle_artwork(): void {
		$this->assert_portal_access();

		$creative_id = isset( $_POST['creative_id'] ) ? absint( $_POST['creative_id'] ) : 0;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::artwork_nonce_action( $creative_id ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw $_FILES entry; Creative_Uploader validates the bytes, and sanitising the array here would corrupt tmp_name.
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();

		$result = $this->process_artwork( $creative_id, $file );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result, 0, $creative_id );
		}

		Creative_Feedback::after( $campaign_id, 'creative_artwork_replaced' );
	}

	/**
	 * Testable artwork entry point.
	 *
	 * @param int                  $creative_id Creative post id.
	 * @param array<string, mixed> $file        One $_FILES entry.
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_artwork( int $creative_id, array $file ): array|WP_Error {
		return $this->manager->replace_artwork( $creative_id, $file );
	}

	/**
	 * Repoints one creative that review has not yet accepted.
	 *
	 * @return void
	 */
	public function handle_destination(): void {
		$this->assert_portal_access();

		$creative_id = isset( $_POST['creative_id'] ) ? absint( $_POST['creative_id'] ) : 0;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$click_url   = isset( $_POST['click_url'] ) ? sanitize_text_field( wp_unslash( $_POST['click_url'] ) ) : '';

		check_admin_referer( self::destination_nonce_action( $creative_id ) );

		$result = $this->process_destination( $creative_id, $click_url );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result, 0, $creative_id );
		}

		Creative_Feedback::after(
			$campaign_id,
			'creative_destination_saved',
			null,
			0,
			0,
			Creative_Feedback::destination_patch( $creative_id, $click_url )
		);
	}

	/**
	 * Testable destination entry point.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $click_url   New destination.
	 * @return true|WP_Error
	 */
	public function process_destination( int $creative_id, string $click_url ): bool|WP_Error {
		return $this->manager->set_destination( $creative_id, $click_url );
	}

	/**
	 * Pauses or resumes one variant's delivery.
	 *
	 * @return void
	 */
	public function handle_status(): void {
		$this->assert_portal_access();

		$assignment_id = isset( $_POST['assignment_id'] ) ? absint( $_POST['assignment_id'] ) : 0;
		$campaign_id   = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$revision      = isset( $_POST['revision'] ) ? absint( $_POST['revision'] ) : 0;
		$intent        = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '';

		check_admin_referer( self::status_nonce_action( $assignment_id ) );

		$result = $this->process_status( $campaign_id, $assignment_id, $intent, $revision );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result );
		}

		Creative_Feedback::after(
			$campaign_id,
			'pause' === $intent ? 'creative_paused' : 'creative_resumed'
		);
	}

	/**
	 * Testable pause/resume entry point.
	 *
	 * **An intent, not a status.** The form says what the button does, and this
	 * maps it to the one status that button is allowed to write. Accepting a
	 * status string instead would let a hand-made post to the pause action
	 * cancel an assignment — `Assignment_Editor` would permit it, because
	 * `live → cancelled` is a legal edge for the routes that are meant to offer
	 * it. Terminal states are not this control's to hand out.
	 *
	 * @param int    $campaign_id   Campaign id.
	 * @param int    $assignment_id Assignment id.
	 * @param string $intent        `pause` or `resume`.
	 * @param int    $revision      Revision the form was rendered from.
	 * @return int|WP_Error New revision, or why not.
	 */
	public function process_status( int $campaign_id, int $assignment_id, string $intent, int $revision ): int|WP_Error {
		$status = match ( $intent ) {
			'pause'  => Assignment_Rules::PAUSED,
			'resume' => Assignment_Rules::LIVE,
			default  => '',
		};

		if ( '' === $status ) {
			return new WP_Error(
				'aggr_creative_status_intent_invalid',
				__( 'That is not something you can do to a creative.', 'aggressive-ads' ),
				array( 'status' => 400 )
			);
		}

		return $this->assignments->update(
			$campaign_id,
			$assignment_id,
			array( 'status' => $status ),
			$revision
		);
	}

	/**
	 * Sets one variant's own delivery window.
	 *
	 * @return void
	 */
	public function handle_window(): void {
		$this->assert_portal_access();

		$assignment_id = isset( $_POST['assignment_id'] ) ? absint( $_POST['assignment_id'] ) : 0;
		$campaign_id   = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$revision      = isset( $_POST['revision'] ) ? absint( $_POST['revision'] ) : 0;
		$start         = isset( $_POST['starts_on'] ) ? sanitize_text_field( wp_unslash( $_POST['starts_on'] ) ) : '';
		$end           = isset( $_POST['ends_on'] ) ? sanitize_text_field( wp_unslash( $_POST['ends_on'] ) ) : '';

		check_admin_referer( self::window_nonce_action( $assignment_id ) );

		$result = $this->process_window( $campaign_id, $assignment_id, $start, $end, $revision );

		if ( is_wp_error( $result ) ) {
			Creative_Feedback::after( $campaign_id, 'error', $result );
		}

		Creative_Feedback::after(
			$campaign_id,
			'creative_window_saved',
			null,
			0,
			0,
			array( '#aggr-window-label-' . $assignment_id => Creative_Feedback::window_label( $start, $end ) )
		);
	}

	/**
	 * Testable window entry point.
	 *
	 * Both ends are sent every time, because `Assignment_Editor` compares the
	 * pair against the campaign's window and an empty field is a real value
	 * here — it means "inherit this end from the campaign", not "leave it".
	 *
	 * @param int    $campaign_id   Campaign id.
	 * @param int    $assignment_id Assignment id.
	 * @param string $start         YYYY-MM-DD, or empty to inherit.
	 * @param string $end           YYYY-MM-DD, or empty to inherit.
	 * @param int    $revision      Revision the form was rendered from.
	 * @return int|WP_Error New revision, or why not.
	 */
	public function process_window( int $campaign_id, int $assignment_id, string $start, string $end, int $revision ): int|WP_Error {
		$start_ts = Date_Input::parse( $start, false );

		if ( is_wp_error( $start_ts ) ) {
			return $start_ts;
		}

		$end_ts = Date_Input::parse( $end, true );

		if ( is_wp_error( $end_ts ) ) {
			return $end_ts;
		}

		return $this->assignments->update(
			$campaign_id,
			$assignment_id,
			array(
				'start_at_ts' => $start_ts,
				'end_at_ts'   => $end_ts,
			),
			$revision
		);
	}

	/**
	 * Nonce scoped to one assignment's pause control.
	 *
	 * @param int $assignment_id Assignment id.
	 * @return string
	 */
	public static function status_nonce_action( int $assignment_id ): string {
		return self::STATUS_ACTION . '_' . max( 0, $assignment_id );
	}

	/**
	 * Nonce scoped to one assignment's window.
	 *
	 * @param int $assignment_id Assignment id.
	 * @return string
	 */
	public static function window_nonce_action( int $assignment_id ): string {
		return self::WINDOW_ACTION . '_' . max( 0, $assignment_id );
	}

	/**
	 * Nonce scoped to one creative's share.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	public static function replace_nonce_action( int $creative_id ): string {
		return self::REPLACE_ACTION . '_' . max( 0, $creative_id );
	}

	/**
	 * Nonce scoped to one pending replacement withdrawal.
	 *
	 * @param int $replacement_id Replacement id.
	 * @return string
	 */
	public static function withdraw_nonce_action( int $replacement_id ): string {
		return self::WITHDRAW_ACTION . '_' . max( 0, $replacement_id );
	}





	/**
	 * Every share sentence on a campaign, restated.
	 *
	 * A weight is relative, so saving one creative's share changes what every
	 * other creative on that placement is delivering. Patching only the card
	 * that was edited would leave its neighbours showing percentages that no
	 * longer add up — which is worse than not updating at all, because it
	 * looks settled.
	 *
	 * Built from the same view data the page rendered with, so the sentence
	 * that replaces one is the sentence a reload would have produced.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<string, string>
	 */
	private function share_patch( int $campaign_id ): array {
		$patch = array();

		foreach ( $this->creatives->creative_rows( $campaign_id ) as $creative ) {
			$share = $creative['share'] ?? null;

			if ( null === $share ) {
				continue;
			}

			/*
			 * The field as well as the sentence. Setting one share moves every
			 * other on the placement, so a page that updated only the sentence
			 * would leave each field showing the number somebody typed before
			 * the rest were rebalanced around it.
			 */
			$percent = max( Assignment_Rules::MIN_WEIGHT, (int) round( (float) $share * Assignment_Rules::SHARE_TOTAL ) );

			$patch[ '#aggr-share-' . (int) $creative['id'] ]      = (string) $percent;
			$patch[ '#aggr-share-note-' . (int) $creative['id'] ] = sprintf(
				/* translators: 1: this ad's share, e.g. 70. 2: what is left for the others, e.g. 30. */
				__( 'Shown %1$d%% of the time here. The other ads share the remaining %2$d%%.', 'aggressive-ads' ),
				$percent,
				Assignment_Rules::SHARE_TOTAL - $percent
			);
		}

		return $patch;
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

		wp_die( esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ), '', array( 'response' => 403 ) );
	}
}
