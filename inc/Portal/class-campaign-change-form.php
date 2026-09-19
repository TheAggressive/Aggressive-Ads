<?php
/**
 * Reading the running-campaign edit form.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use WP_Error;

/**
 * What one step of the edit flow posted, reduced to the fields the site allows.
 *
 * Split out of `Campaign_Actions` when that handler reached the file-length
 * gate, and because the edit flow's forms grew to be creation's: the package
 * grid, the schedule and the Destination card each post here now.
 *
 * A field the site has not enabled is not read at all. The workflow drops it
 * again before validation, but not reading it is what keeps a switched-off
 * field from even being sanitized on the way to being ignored.
 */
final class Campaign_Change_Form {

	/**
	 * Reads the proposal fields this site has enabled.
	 *
	 * Callers verify the action nonce before reaching this.
	 *
	 * @param array<int, string> $allowed Live-edit field names the site allows.
	 * @return array<string, mixed>
	 */
	public static function read( array $allowed ): array {
		$proposed = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every caller verifies the action nonce before reaching this.
		if ( in_array( 'title', $allowed, true ) && isset( $_POST['title'] ) ) {
			$proposed['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
		}

		if ( in_array( 'advertiser_notes', $allowed, true ) && isset( $_POST['advertiser_notes'] ) ) {
			$proposed['advertiser_notes'] = sanitize_textarea_field( wp_unslash( $_POST['advertiser_notes'] ) );
		}

		if ( in_array( 'package_id', $allowed, true ) && isset( $_POST['package_id'] ) ) {
			$proposed['package_id'] = absint( $_POST['package_id'] );
		}

		if ( in_array( 'start_ts', $allowed, true ) && isset( $_POST['start_date'] ) ) {
			$proposed['start_ts'] = self::date( sanitize_text_field( wp_unslash( $_POST['start_date'] ) ), false );
		}

		// Absent for a fixed-length package, whose end field is disabled; the workflow derives it.
		if ( in_array( 'end_ts', $allowed, true ) && isset( $_POST['end_date'] ) ) {
			$proposed['end_ts'] = self::date( sanitize_text_field( wp_unslash( $_POST['end_date'] ) ), true );
		}

		if ( in_array( 'placement_ids', $allowed, true ) && isset( $_POST['placement_ids'] ) && is_array( $_POST['placement_ids'] ) ) {
			$proposed['placement_ids'] = array_map( 'absint', wp_unslash( $_POST['placement_ids'] ) );
		}

		// esc_url_raw rather than sanitize_text_field: the workflow rejects
		// anything that is not http(s), and a mangled scheme would fail that
		// check for the wrong reason and confuse the message.
		if ( in_array( 'default_click_url', $allowed, true ) && isset( $_POST['default_click_url'] ) ) {
			$proposed['default_click_url'] = esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['default_click_url'] ) ) ) );
		}

		if ( in_array( 'click_urls', $allowed, true ) && isset( $_POST['click_urls'] ) && is_array( $_POST['click_urls'] ) ) {
			$urls = array();

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
	 * A refused proposal, named by its first reason.
	 *
	 * The workflow refuses with one code and a list of reasons, and only a
	 * code crosses the redirect. Sent as `aggr_live_edit_invalid`, every
	 * refusal read "The campaign could not be saved" — including a downgrade
	 * refused because the end its package implies has already passed, which
	 * the advertiser can only fix if somebody tells them.
	 *
	 * @param WP_Error $error What staging returned.
	 * @return WP_Error
	 */
	public static function refusal( WP_Error $error ): WP_Error {
		$data     = $error->get_error_data();
		$problems = is_array( $data ) && isset( $data['problems'] ) && is_array( $data['problems'] ) ? $data['problems'] : array();
		$first    = is_array( $problems[0] ?? null ) ? (string) ( $problems[0]['code'] ?? '' ) : '';

		return '' === self::problem_message( $first ) ? $error : new WP_Error( $first, self::problem_message( $first ) );
	}

	/**
	 * The sentence for one of `Live_Edit_Rules`' reasons, or empty.
	 *
	 * @param string $code A `Live_Edit_Rules::ERROR_*` code.
	 * @return string
	 */
	public static function problem_message( string $code ): string {
		return match ( $code ) {
			'live_edit_nothing_changed'       => __( 'Nothing has changed yet. Change something before you submit.', 'aggressive-ads' ),
			'live_edit_title_empty'           => __( 'Enter a campaign name.', 'aggressive-ads' ),
			'live_edit_title_too_long'        => __( 'Use 200 characters or fewer for the campaign name.', 'aggressive-ads' ),
			'live_edit_notes_too_long'        => __( 'Use 2,000 characters or fewer for your notes.', 'aggressive-ads' ),
			'live_edit_start_locked'          => __( 'The campaign has already started, so its start date cannot move.', 'aggressive-ads' ),
			'live_edit_end_missing'           => __( 'Choose an end date.', 'aggressive-ads' ),
			'live_edit_end_before_start'      => __( 'The end date must be after the start date.', 'aggressive-ads' ),
			'live_edit_end_in_past'           => __( 'The campaign would end on a day that has already passed. Choose a later end date, or a package that runs longer.', 'aggressive-ads' ),
			'live_edit_click_url_invalid'     => __( 'Enter a valid http or https link, such as https://example.com.', 'aggressive-ads' ),
			'live_edit_no_placements'         => __( 'Keep at least one placement.', 'aggressive-ads' ),
			'live_edit_placement_not_offered' => __( 'Choose placements your package includes.', 'aggressive-ads' ),
			'live_edit_package_not_offered'   => __( 'That package is no longer available. Choose another package.', 'aggressive-ads' ),
			default                           => '',
		};
	}

	/**
	 * Whether the portal's own script posted this, and wants JSON back.
	 *
	 * The same marker `Creative_Feedback` answers to, so a staged save and a
	 * creative save are asked for the same way.
	 *
	 * @return bool
	 */
	public static function is_async(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers have already run check_admin_referer(); this only picks the response format.
		return isset( $_POST[ Creative_Feedback::ASYNC_FIELD ] ) && '1' === $_POST[ Creative_Feedback::ASYNC_FIELD ];
	}

	/**
	 * Answers a scripted save and stops.
	 *
	 * The link check stages the Destination card this way before asking about
	 * it, because the check reads what is stored and never takes a URL. A
	 * refusal says why in the words the page would have shown.
	 *
	 * @param array<string, mixed>|WP_Error $result   What staging returned.
	 * @param string                        $redirect Where a form post would have landed.
	 * @return never
	 */
	public static function answer( array|WP_Error $result, string $redirect ): never {
		$refused = is_wp_error( $result );

		wp_send_json(
			array(
				'ok'       => ! $refused,
				'notice'   => $refused ? $result->get_error_message() : '',
				'redirect' => $redirect,
				'patch'    => (object) array(),
			),
			200
		);
	}

	/**
	 * A posted date, or -1 for one that does not parse.
	 *
	 * -1 rather than zero so an unreadable date is refused by the rules
	 * instead of read as "no date".
	 *
	 * @param string $value      YYYY-MM-DD or empty.
	 * @param bool   $end_of_day Whether to use 23:59:59.
	 * @return int
	 */
	private static function date( string $value, bool $end_of_day ): int {
		$parsed = Date_Input::parse( $value, $end_of_day );

		return is_wp_error( $parsed ) ? -1 : $parsed;
	}
}
