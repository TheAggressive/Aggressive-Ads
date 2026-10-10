<?php
/**
 * What a third-party click tracker has to look like to be accepted.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Rules for the optional tracker an agency puts in front of a landing page.
 *
 * A tracker (CM360, Flashtalking, Innovid, a DSP) is a URL the visitor is sent
 * to *first*; it counts the click for the agency's own reporting and redirects
 * to the landing page. That makes it a third party the publisher is agreeing to
 * send readers through, so the rules here are stricter than for a destination:
 * it must say where the visitor goes next, and in the one form that cannot be
 * mistaken for part of the tracker's own URL.
 *
 * Value-level only, like {@see Campaign_Rules::is_valid_click_url()}. There is
 * deliberately no `wp_http_validate_url()` on top: it resolves the host over
 * DNS to refuse private addresses, which protects a server that is about to
 * *fetch* a URL. Nothing here fetches the tracker — the visitor's browser is
 * redirected to it — so the check would guard nothing, cost a lookup on every
 * save, and refuse a tracker whenever the editor's own resolver could not see
 * it (a staging site, an offline test run).
 *
 * No WordPress function is called here.
 */
final class Click_Tracker_Rules {

	/** Not a valid http or https address, or it carries credentials. */
	public const ERROR_INVALID = 'click_tracker_invalid';

	/** The tracker never says where to send the visitor next. */
	public const ERROR_NO_LANDING_MACRO = 'click_tracker_no_landing_macro';

	/** The tracker asks for the landing page more than once. */
	public const ERROR_REPEATED_LANDING_MACRO = 'click_tracker_repeated_landing_macro';

	/** The tracker asks for the landing page in a form that is not encoded. */
	public const ERROR_UNENCODED_LANDING = 'click_tracker_unencoded_landing';

	/** Longer than a link can be and still survive a redirect chain. */
	public const ERROR_TOO_LONG = 'click_tracker_too_long';

	/**
	 * The longest tracker accepted, in bytes.
	 *
	 * The landing page is encoded into it on every click, which can triple the
	 * landing page's length, and the result is a `Location` header that browsers
	 * and proxies cut off at around 8 KB. Stored short enough that the composed
	 * address still fits.
	 */
	public const MAX_LENGTH = 1024;

	/**
	 * Why a tracker is not acceptable, or null when it is.
	 *
	 * An empty string is not an error: it means "no tracker", and is how one is
	 * removed. Callers decide whether to store it.
	 *
	 * @param string $tracker Candidate tracker URL, as typed.
	 * @return string|null One of the `ERROR_*` codes, or null.
	 */
	public static function error_for( string $tracker ): ?string {
		$tracker = trim( $tracker );

		if ( '' === $tracker ) {
			return null;
		}

		if ( strlen( $tracker ) > self::MAX_LENGTH ) {
			return self::ERROR_TOO_LONG;
		}

		if ( ! Campaign_Rules::is_valid_click_url( $tracker ) || ! self::has_plain_host( $tracker ) ) {
			return self::ERROR_INVALID;
		}

		$landing = 0;

		foreach ( self::macro_names( $tracker ) as $name ) {
			if ( Click_Macros::LANDING_PAGE === $name ) {
				++$landing;
			} elseif ( 1 === preg_match( '/lpurl|unenc|unescaped|dest/', $name ) ) {
				// `{lpurl_unenc}`, `{unescapedlpurl}`, `{dest_url}`: ad servers'
				// names for the landing page *unencoded*. This plugin only
				// writes it encoded, so one of these would otherwise be
				// silently removed and the tracker sent no destination at all.
				return self::ERROR_UNENCODED_LANDING;
			}
		}

		if ( 0 === $landing ) {
			return self::ERROR_NO_LANDING_MACRO;
		}

		return $landing > 1 ? self::ERROR_REPEATED_LANDING_MACRO : null;
	}

	/**
	 * Whether the address's host is an ordinary hostname or IPv4 address.
	 *
	 * `is_valid_click_url()` only asks that there be a host, and `parse_url()`
	 * will hand back anything as one — spaces, braces. A macro there would be
	 * filled with an encoded URL and name a host nobody chose, so the host is
	 * held to letters, digits, hyphens and dots, which every real tracker uses.
	 * An internationalised host is written in its punycode form.
	 *
	 * @param string $url Tracker URL, already known to have a host.
	 * @return bool
	 */
	private static function has_plain_host( string $url ): bool {
		$host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is a WordPress function, and this layer must not call one; see docs/architecture.md.

		return is_string( $host ) && 1 === preg_match( '/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', $host );
	}

	/**
	 * Every macro name a URL writes, lowercased, in order, with repeats.
	 *
	 * @param string $url A tracker URL.
	 * @return array<int, string>
	 */
	private static function macro_names( string $url ): array {
		$found = array();

		if ( false === preg_match_all( '/\{([a-z_]{1,32})\}/i', $url, $found ) ) {
			return array();
		}

		return array_map( 'strtolower', $found[1] );
	}
}
