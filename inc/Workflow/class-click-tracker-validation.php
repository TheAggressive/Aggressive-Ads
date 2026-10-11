<?php
/**
 * Accepting an advertiser's click tracker.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Click_Tracker_Rules;
use WP_Error;

/**
 * Turns what was typed into a tracker that may be stored, or says why not.
 *
 * The one place the workflow layer decides this, so the draft editor and any
 * later path that can change a tracker apply the same rules and word them the
 * same way. The value-level rules are {@see Click_Tracker_Rules}; this adds
 * the scheme people leave off, and the human wording.
 *
 * A tracker is never fetched. It is only redirected to, by the visitor's
 * browser, so validation here is about what a reader can be sent to, not about
 * what the server will request.
 */
final class Click_Tracker_Validation {

	/**
	 * The error code every refusal carries, with the reason in `data`.
	 */
	public const ERROR_CODE = 'aggr_click_tracker_invalid';

	/**
	 * Cleans a typed tracker for storage.
	 *
	 * @param string $typed Tracker URL as entered; empty removes it.
	 * @return string|WP_Error The tracker to store (possibly empty), or why not.
	 */
	public static function clean( string $typed ): string|WP_Error {
		$tracker = trim( $typed );

		if ( '' === $tracker ) {
			return '';
		}

		// `tracker.example/c?u={lpurl}` is how people paste one; it means https.
		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*:#i', $tracker ) ) {
			$tracker = 'https://' . $tracker;
		}

		$reason = Click_Tracker_Rules::error_for( $tracker );

		if ( null !== $reason ) {
			return new WP_Error(
				self::ERROR_CODE,
				self::message_for( $reason ),
				array(
					'status' => 422,
					'field'  => 'click_tracker',
					'reason' => $reason,
				)
			);
		}

		return $tracker;
	}

	/**
	 * What to tell the advertiser, by reason.
	 *
	 * Each says what to do, not just what is wrong: the person holding a
	 * tracker URL from their agency knows it as "the tracking link", not as a
	 * macro, so the fix is named in their words and with the exact token.
	 *
	 * @param string $reason One of the `Click_Tracker_Rules::ERROR_*` codes.
	 * @return string
	 */
	public static function message_for( string $reason ): string {
		return match ( $reason ) {
			Click_Tracker_Rules::ERROR_NO_LANDING_MACRO => __( 'Add {lpurl} where the tracking link should send visitors on to your landing page.', 'aggressive-ads' ),
			Click_Tracker_Rules::ERROR_REPEATED_LANDING_MACRO => __( 'Use {lpurl} once. The tracking link can only send visitors on to one landing page.', 'aggressive-ads' ),
			Click_Tracker_Rules::ERROR_UNENCODED_LANDING => __( 'Use {lpurl} for the landing page. Other forms are not supported, because the page address has to be encoded to survive inside the tracking link.', 'aggressive-ads' ),
			Click_Tracker_Rules::ERROR_TOO_LONG => __( 'That tracking link is too long. Ask your agency for a shorter one.', 'aggressive-ads' ),
			default => __( 'Enter a valid http or https tracking link without a username or password.', 'aggressive-ads' ),
		};
	}
}
