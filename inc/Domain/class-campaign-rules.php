<?php
/**
 * The campaign rules, as pure functions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Every rule that can be decided from values alone.
 *
 * No WordPress, no database, no clock of its own — `now` is always passed in,
 * so "is this start date in the future?" is a function rather than something
 * that behaves differently depending on when the suite runs.
 */
final class Campaign_Rules {

	public const ERROR_NO_CREATIVES        = 'no_creatives';
	public const ERROR_NO_PLACEMENTS       = 'no_placements';
	public const ERROR_PLACEMENT_INACTIVE  = 'placement_inactive';
	public const ERROR_PLACEMENT_UNCOVERED = 'placement_uncovered';
	public const ERROR_CREATIVE_KIND       = 'creative_kind_not_allowed';
	public const ERROR_CREATIVE_PLACEMENT  = 'creative_placement_not_selected';
	public const ERROR_CREATIVE_SIZE       = 'creative_size_mismatch';
	public const ERROR_CLICK_URL_MISSING   = 'click_url_missing';
	public const ERROR_CLICK_URL_INVALID   = 'click_url_invalid';
	public const ERROR_START_MISSING       = 'start_date_missing';
	public const ERROR_START_IN_PAST       = 'start_date_in_past';
	public const ERROR_START_NOT_MIDNIGHT  = 'start_date_not_midnight';
	public const ERROR_END_BEFORE_START    = 'end_date_before_start';
	public const ERROR_END_NOT_DAY_END     = 'end_date_not_day_end';
	public const ERROR_ORG_NOT_ACTIVE      = 'organization_not_active';
	public const ERROR_ORG_MISSING         = 'organization_missing';
	public const ERROR_PACKAGE_MISSING     = 'package_missing';
	public const ERROR_PACKAGE_UNAVAILABLE = 'package_unavailable';
	public const ERROR_PRICE_MISSING       = 'price_missing';

	/**
	 * The only creative kind an advertiser may submit.
	 *
	 * `code` and `html5` are arbitrary markup on a public page, so they require
	 * a reviewer. See docs/threat-model.md.
	 */
	public const ADVERTISER_CREATIVE_KIND = 'image';

	/**
	 * Schemes a destination URL may use.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_URL_SCHEMES = array( 'http', 'https' );

	/**
	 * Whether a destination URL is acceptable.
	 *
	 * The scheme allowlist is what blocks `javascript:` and `data:`. Credential
	 * -bearing URLs are refused because the credential ends up in a public
	 * href, in analytics, and in every referrer header that follows it.
	 *
	 * This is the value-level check; the workflow layer additionally runs
	 * wp_http_validate_url(), which knows about the site's own restrictions.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_valid_click_url( string $url ): bool {
		$url = trim( $url );

		if ( '' === $url ) {
			return false;
		}

		// A control character can split a header or truncate a comparison, and
		// no legitimate URL contains one.
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return false;
		}

		$parts = self::parse( $url );

		if ( null === $parts ) {
			return false;
		}

		if ( ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), self::ALLOWED_URL_SCHEMES, true ) ) {
			return false;
		}

		if ( ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			return false;
		}

		return ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	/**
	 * Splits a stored `{width}x{height}` size into integers.
	 *
	 * Only the ASCII `x` form is accepted. U+00D7 MULTIPLICATION SIGN looks
	 * right in a label and would never match an uploaded file.
	 *
	 * @param string $size Size string, e.g. `728x90`.
	 * @return array{0: int, 1: int}|null
	 */
	public static function parse_size( string $size ): ?array {
		if ( 1 !== preg_match( '/^(\d{1,5})x(\d{1,5})$/', trim( $size ), $matches ) ) {
			return null;
		}

		$width  = (int) $matches[1];
		$height = (int) $matches[2];

		if ( $width < 1 || $height < 1 ) {
			return null;
		}

		return array( $width, $height );
	}

	/**
	 * Whether an image's real dimensions match a declared size.
	 *
	 * @param int    $width  Detected width.
	 * @param int    $height Detected height.
	 * @param string $size   Declared size, e.g. `728x90`.
	 * @return bool
	 */
	public static function size_matches( int $width, int $height, string $size ): bool {
		$parsed = self::parse_size( $size );

		if ( null === $parsed ) {
			return false;
		}

		return $parsed[0] === $width && $parsed[1] === $height;
	}

	/**
	 * Checks a campaign's date window.
	 *
	 * @param int $start_ts Start time, UTC Unix seconds.
	 * @param int $end_ts   End time, UTC Unix seconds. Zero means open-ended.
	 * @param int $now      Current time, UTC Unix seconds.
	 * @return Validation_Result
	 */
	public static function validate_window( int $start_ts, int $end_ts, int $now ): Validation_Result {
		$result = new Validation_Result();

		if ( $start_ts <= 0 ) {
			$result->add( self::ERROR_START_MISSING, 'start_ts' );

			return $result;
		}

		if ( $start_ts <= $now ) {
			$result->add(
				self::ERROR_START_IN_PAST,
				'start_ts',
				array(
					'start_ts' => $start_ts,
					'now'      => $now,
				)
			);
		}

		// Zero is open-ended and therefore never before the start.
		if ( 0 !== $end_ts && $end_ts <= $start_ts ) {
			$result->add(
				self::ERROR_END_BEFORE_START,
				'end_ts',
				array(
					'start_ts' => $start_ts,
					'end_ts'   => $end_ts,
				)
			);
		}

		return $result;
	}

	/**
	 * Requires a schedule to cover whole local calendar days.
	 *
	 * The canonical representation is 00:00:00 at the beginning and 23:59:59
	 * at the end. Converting in the site timezone (rather than adding 86,400
	 * seconds) keeps those boundaries correct across DST transitions.
	 *
	 * @param int    $start_ts Start time, UTC Unix seconds.
	 * @param int    $end_ts   End time, UTC Unix seconds. Zero means open-ended.
	 * @param string $timezone IANA timezone or fixed UTC offset.
	 * @return Validation_Result
	 */
	public static function validate_day_boundaries( int $start_ts, int $end_ts, string $timezone ): Validation_Result {
		$result = new Validation_Result();
		$zone   = new \DateTimeZone( $timezone );

		if ( $start_ts > 0 && '00:00:00' !== ( new \DateTimeImmutable( '@' . $start_ts ) )->setTimezone( $zone )->format( 'H:i:s' ) ) {
			$result->add( self::ERROR_START_NOT_MIDNIGHT, 'start_ts' );
		}

		if ( $end_ts > 0 && '23:59:59' !== ( new \DateTimeImmutable( '@' . $end_ts ) )->setTimezone( $zone )->format( 'H:i:s' ) ) {
			$result->add( self::ERROR_END_NOT_DAY_END, 'end_ts' );
		}

		return $result;
	}

	/**
	 * Parses a URL without depending on WordPress.
	 *
	 * The WordPress helper this layer would otherwise reach for is a WordPress
	 * function, and this layer calls none. PHP's own parser returns false on
	 * failure and emits no warning for the cases that matter here.
	 *
	 * @param string $url Candidate URL.
	 * @return array<string, mixed>|null
	 */
	private static function parse( string $url ): ?array {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is a WordPress function, and this layer must not call one; see docs/architecture.md.

		return is_array( $parts ) ? $parts : null;
	}
}
