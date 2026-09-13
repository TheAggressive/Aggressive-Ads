<?php
/**
 * How one creative write reports its outcome.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Domain\Upload_Rules;
use WP_Error;

/**
 * Reads a write's outcome back off the redirect, and writes it out again.
 *
 * Split from `Creative_Actions`, which had grown to hold two jobs: what a
 * write *does* — nonce, capability, workflow call — and how its result is
 * told to whoever asked. The second job is the larger half and the one with
 * no dependencies: every method here is static and reads only the request,
 * which is why it could leave without any of the handlers changing shape.
 *
 * The seam matters beyond line count. A handler is reviewed for whether it
 * checks the right nonce before it touches anything; this file is reviewed
 * for whether a refusal names the right field, reopens the right dialog and
 * says something an advertiser can act on. Different questions, and they were
 * being asked of one 960-line file.
 */
final class Creative_Feedback {

	/**
	 * The field a scripted write sets to ask for JSON back.
	 *
	 * A form field rather than a header, so the marker survives the
	 * `FormData` the client already has to build for a file upload.
	 */
	public const ASYNC_FIELD = 'aggr_async';

	/**
	 * Reads an allowlisted creative notice.
	 *
	 * @return string
	 */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post/redirect/get display state.
		$value = isset( $_GET['aggr_notice'] ) ? sanitize_key( wp_unslash( $_GET['aggr_notice'] ) ) : '';

		return in_array( $value, array( 'creative_uploaded', 'creative_removed', 'creative_update_requested', 'creative_update_withdrawn', 'creative_weight_saved', 'creative_paused', 'creative_resumed', 'creative_window_saved', 'creative_destination_saved', 'creative_artwork_replaced', 'error' ), true ) ? $value : '';
	}

	/**
	 * Reads the safe error code passed through the redirect.
	 *
	 * @return string
	 */
	public static function request_error_code(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted display state.
		return isset( $_GET['aggr_error'] ) ? sanitize_key( wp_unslash( $_GET['aggr_error'] ) ) : '';
	}

	/**
	 * What the run-dates trigger says once these dates are saved.
	 *
	 * The same two sentences the template chooses between. A dialog cannot
	 * show a saved window the way the fold it replaced could, so the trigger
	 * carries it — and a trigger left saying "add" over dates somebody just
	 * set is the thing that made the fold open itself in the first place.
	 *
	 * @param string $start Start date, empty to inherit.
	 * @param string $end   End date, empty to inherit.
	 * @return string
	 */
	public static function window_label( string $start, string $end ): string {
		return '' !== trim( $start ) || '' !== trim( $end )
			? __( 'Custom run dates set', 'aggressive-ads' )
			: __( 'Add custom run dates', 'aggressive-ads' );
	}

	/**
	 * What a saved destination changes on the page.
	 *
	 * Its own method so the selector has one author. The card renders the id
	 * and this names it, and the two drifting apart is a save that succeeds
	 * and updates nothing — which reads, from the outside, as a save that did
	 * not work.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $click_url   The stored destination.
	 * @return array<string, string>
	 */
	public static function destination_patch( int $creative_id, string $click_url ): array {
		return array( '#aggr-destination-value-' . $creative_id => $click_url );
	}

	/**
	 * Whether this write was posted by the portal's own script.
	 *
	 * Asked from the request rather than inferred from `wp_doing_ajax()`,
	 * because these are ordinary `admin-post.php` writes: the same URL, the
	 * same nonce, the same handler. The only difference is who is listening.
	 *
	 * @return bool
	 */
	private static function wants_json(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller has already run check_admin_referer(); this only picks the response format.
		return isset( $_POST[ self::ASYNC_FIELD ] ) && '1' === $_POST[ self::ASYNC_FIELD ];
	}

	/**
	 * Answers a scripted write and stops.
	 *
	 * **Success is answered; failure is handed back to the page.** A refused
	 * save already has somewhere that renders it properly — the banner naming
	 * the field, the fragment reopening the dialog that holds it, the message
	 * quoting that placement's own size limit — and reproducing any of that
	 * here would be a second copy to keep in step with the first. The client
	 * follows `redirect` and lands exactly where a form post would have.
	 *
	 * @param string                $notice Notice key.
	 * @param WP_Error|null         $error  Workflow error, when the write was refused.
	 * @param string                $url    Where a browser would have been sent.
	 * @param array<string, string> $patch  Element selector to its new text.
	 * @return never
	 */
	private static function send_json( string $notice, ?WP_Error $error, string $url, array $patch ): never {
		wp_send_json(
			array(
				'ok'       => null === $error,
				'notice'   => null === $error ? $notice : '',
				'redirect' => $url,

				/*
				 * What the page has to change, decided here.
				 *
				 * The client used to read the new value off the form it had
				 * just submitted, which works only while a write changes
				 * nothing but its own field. A share does not: it is a
				 * percentage of the other creatives on the placement, so
				 * saving one restates all of them. The server is the only
				 * side that knows that.
				 *
				 * An empty map on success means the page cannot be brought
				 * up to date from here, and the client falls back to the
				 * redirect rather than showing a toast over stale values.
				 */
				'patch'    => (object) $patch,
			),
			200
		);
	}

	/**
	 * The dialog a refused save has to reopen, as a URL fragment.
	 *
	 * Every edit on the creative card is a dialog now, so a redirect that only
	 * names the failing field points at a control nobody can see. The fragment
	 * is what reopens it, and it is the only mechanism that works on both
	 * halves: `:target` shows the dialog with no JavaScript at all, and the
	 * dialog module's own boot sees the matching hash and opens it properly —
	 * focus trap, `inert` on the shell, the announcement.
	 *
	 * **Seeding the module's `isOpen` state instead does not work**, which is
	 * not obvious: `bootDialog()` only calls `openDialog()` when the state is
	 * still closed, so a dialog pre-marked open gets none of that and never
	 * even receives the class that makes it visible.
	 *
	 * @param string $code         Error code.
	 * @param int    $placement_id Placement the error belongs to, or zero.
	 * @param int    $creative_id  Creative the error belongs to, or zero.
	 * @return string Fragment without the '#', empty when no dialog owns it.
	 */
	public static function error_fragment( string $code, int $placement_id, int $creative_id ): string {
		if ( $creative_id > 0 ) {
			return match ( $code ) {
				'aggr_click_url_required',
				'aggr_click_url_invalid',
				'aggr_destination_frozen',
				'aggr_destination_forbidden' => 'aggr-destination-dialog-' . $creative_id,
				'aggr_artwork_frozen',
				'aggr_artwork_forbidden',
				'aggr_artwork_not_saved',
				'aggr_creative_size_mismatch' => 'aggr-swap-' . $creative_id,
				default                       => '',
			};
		}

		/*
		 * An upload error belongs to the add-a-creative dialog — except on an
		 * empty placement, where the form is on the card and there is no
		 * dialog to open. Naming one that does not exist is harmless: no
		 * element matches, `:target` matches nothing, and the page loads at
		 * the top as it did before.
		 */
		return $placement_id > 0 ? 'aggr-add-' . $placement_id : '';
	}

	/**
	 * Creative related to the destination error, or zero.
	 *
	 * @return int
	 */
	public static function request_error_creative(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only in-page link target.
		return isset( $_GET['aggr_creative'] ) ? absint( $_GET['aggr_creative'] ) : 0;
	}

	/**
	 * Placement related to the upload error, or zero.
	 *
	 * @return int
	 */
	public static function request_error_placement(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only in-page link target.
		return isset( $_GET['aggr_placement'] ) ? absint( $_GET['aggr_placement'] ) : 0;
	}

	/**
	 * Stable message for a redirect error code.
	 *
	 * The size limit is a parameter because it belongs to the placement the
	 * upload was for, and the redirect carries only a code. A caller with no
	 * placement to hand gets the default, which is what an unconfigured
	 * placement enforces anyway.
	 *
	 * @param string $code      Error code.
	 * @param int    $max_bytes The placement's maximum, 0 when unknown.
	 * @return string
	 */
	public static function error_message( string $code, int $max_bytes = 0 ): string {
		return match ( $code ) {
			'aggr_click_url_required'       => __( 'Enter the destination URL for this creative.', 'aggressive-ads' ),
			'aggr_click_url_invalid'        => __( 'Enter a valid http or https destination URL without embedded credentials.', 'aggressive-ads' ),
			'aggr_alt_text_required'        => __( 'Describe the ad creative for people who cannot see it.', 'aggressive-ads' ),
			'aggr_alt_text_too_long'        => __( 'Use 500 characters or fewer for the ad creative description.', 'aggressive-ads' ),
			'aggr_creative_size_mismatch'   => __( 'The uploaded dimensions do not match this placement. Resize the ad creative to the required dimensions and try again.', 'aggressive-ads' ),
			'aggr_destination_forbidden'    => __( 'You do not have permission to change that creative.', 'aggressive-ads' ),
			'aggr_artwork_forbidden'        => __( 'You do not have permission to change that creative.', 'aggressive-ads' ),
			'aggr_artwork_frozen'           => __( 'This ad has already been approved, so new artwork is submitted as an update for review rather than swapped in here.', 'aggressive-ads' ),
			'aggr_artwork_not_saved'        => __( 'The new artwork could not be saved. Please try again.', 'aggressive-ads' ),
			'aggr_destination_frozen'       => __( 'This ad has already been approved, so its destination is changed by requesting an update rather than edited here.', 'aggressive-ads' ),
			'aggr_creative_limit_reached'   => __( 'This placement already has the maximum number of creatives. Remove one before uploading another.', 'aggressive-ads' ),
			'aggr_replacement_pending'       => __( 'This ad already has an update waiting for review.', 'aggressive-ads' ),
			'aggr_replacement_unavailable'   => __( 'Only an ad in a scheduled or live campaign can be updated.', 'aggressive-ads' ),
			'aggr_replacement_busy'          => __( 'Another update is already being saved for this ad. Try again.', 'aggressive-ads' ),
			'aggr_upload_no_file'           => __( 'No file was received. Choose an ad creative and try again.', 'aggressive-ads' ),
			'aggr_upload_too_large'         => sprintf(
				/* translators: %s: this placement's maximum file size, e.g. 150 KB. */
				__( 'That file is larger than %s. Save it at a smaller size and try again.', 'aggressive-ads' ),
				size_format( Upload_Rules::resolve_max_bytes( $max_bytes ) )
			),
			'aggr_upload_too_many_pixels'   => __( 'That ad creative has too many pixels to process. Resize it to the placement dimensions and try again.', 'aggressive-ads' ),
			'aggr_upload_not_an_image'      => __( 'That file is not a readable image. JPEG, PNG, GIF, and WebP are supported.', 'aggressive-ads' ),
			'aggr_upload_type_mismatch'     => __( 'The file contents do not match its filename, so it was not accepted.', 'aggressive-ads' ),
			'aggr_upload_type_not_allowed'  => __( 'That file type is not supported. Use JPEG, PNG, GIF, or WebP.', 'aggressive-ads' ),
			'aggr_upload_failed'            => __( 'The upload did not complete. Try again.', 'aggressive-ads' ),
			'aggr_placement_unavailable',
			'aggr_placement_not_selected'   => __( 'That placement is not available for this campaign.', 'aggressive-ads' ),
			'aggr_campaign_not_editable'    => __( 'This campaign cannot be changed right now.', 'aggressive-ads' ),
			'aggr_rate_limited'             => __( 'There have been too many uploads. Wait a moment and try again.', 'aggressive-ads' ),

			/*
			 * **A refusal is not a retry, and it says which refusal.**
			 *
			 * Every code below once fell through to the default, so being
			 * denied permission, removing a published creative, or hitting a
			 * storage mismatch all read as "could not be saved, please try
			 * again" — inviting the reader to repeat an action that cannot
			 * succeed, and hiding a 403 behind what looks like a glitch.
			 *
			 * The forbidden cases then carry one code each, because the portal
			 * renders from the code alone: the error crosses a redirect as a
			 * string rather than as a `WP_Error`, so a shared code means a
			 * shared sentence whatever the manager wrote. "You do not have
			 * permission" is true of all four and useful for none.
			 */
			'aggr_upload_forbidden'         => __( 'You do not have permission to upload creative for this campaign.', 'aggressive-ads' ),
			'aggr_remove_forbidden'         => __( 'You do not have permission to remove that creative.', 'aggressive-ads' ),
			'aggr_forbidden'                => __( 'You do not have permission to change that creative.', 'aggressive-ads' ),
			'aggr_creative_published'       => __( 'A published creative cannot be removed from this draft workflow.', 'aggressive-ads' ),
			'aggr_creative_not_deleted'     => __( 'The creative could not be removed. Please try again.', 'aggressive-ads' ),
			'aggr_creative_restore_failed'  => __( 'The creative record and file could not be reconciled. Please contact an administrator.', 'aggressive-ads' ),
			'aggr_creative_not_created'     => __( 'The creative could not be saved. Please try again.', 'aggressive-ads' ),
			default                             => __( 'The creative could not be saved. Please try again.', 'aggressive-ads' ),
		};
	}

	/**
	 * In-page field target for a creative error.
	 *
	 * @param string $code         Error code.
	 * @param int    $placement_id Related placement.
	 * @return string
	 */
	public static function error_target( string $code, int $placement_id ): string {
		if ( $placement_id <= 0 ) {
			return '';
		}

		$prefix = match ( $code ) {
			'aggr_click_url_required',
			'aggr_click_url_invalid' => 'aggr-click-',
			'aggr_alt_text_required',
			'aggr_alt_text_too_long' => 'aggr-alt-',
			default                      => 'aggr-file-',
		};

		return $prefix . $placement_id;
	}

	/**
	 * Answers one finished write, and does not return.
	 *
	 * Named for what a handler does with it rather than for the mechanism,
	 * because the mechanism is the thing that varies: a browser is redirected
	 * and a scripted save is answered with JSON, and the caller must not have
	 * to know which. Never carries a user-provided message — the code crosses
	 * the redirect and `error_message()` turns it back into a sentence.
	 *
	 * @param int                   $campaign_id  Campaign post id.
	 * @param string                $notice       Notice key.
	 * @param WP_Error|null         $error        Optional workflow error.
	 * @param int                   $placement_id Optional placement for field focus.
	 * @param int                   $creative_id  Optional creative for field focus.
	 * @param array<string, string> $patch Element selector to its new text.
	 * @return never
	 */
	public static function after( int $campaign_id, string $notice, ?WP_Error $error = null, int $placement_id = 0, int $creative_id = 0, array $patch = array() ): never {
		$args = array(
			'step'        => 'creative',
			'aggr_notice' => $notice,
		);

		if ( null !== $error ) {
			$args['aggr_error'] = sanitize_key( (string) $error->get_error_code() );
		}

		if ( $placement_id > 0 ) {
			$args['aggr_placement'] = $placement_id;
		}

		/*
		 * A destination error belongs to one creative, not to the placement
		 * it sits on: a placement may hold ten, each with its own field, and
		 * pointing at the placement would mark whichever one rendered first.
		 */
		if ( $creative_id > 0 ) {
			$args['aggr_creative'] = $creative_id;
		}

		$url = add_query_arg( $args, Routes::url( Request::ROUTE_CAMPAIGNS, $campaign_id ) );

		/*
		 * The one place a write's outcome is decided, so the one place that
		 * has to learn about answering a script instead of a browser.
		 *
		 * Every handler already funnels through here after its own nonce,
		 * capability and validation checks have run, so an asynchronous save
		 * is refused by exactly the same gates as a form post — there is no
		 * second path to keep in step, which is the failure a parallel
		 * `wp_ajax_` handler would have introduced.
		 */
		if ( self::wants_json() ) {
			self::send_json( $notice, $error, $url, $patch );
		}

		if ( null !== $error ) {
			$fragment = self::error_fragment( (string) $error->get_error_code(), $placement_id, $creative_id );

			if ( '' !== $fragment ) {
				$url .= '#' . $fragment;
			}
		}

		wp_safe_redirect( $url );
		exit;
	}
}
