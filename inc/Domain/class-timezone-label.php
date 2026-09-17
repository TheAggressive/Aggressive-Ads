<?php
/**
 * A site timezone as an advertiser would name it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * "Site time" asked the advertiser to know which zone the site keeps. This
 * names it: the city of an IANA identifier, or UTC and its offset.
 *
 * A place, not an abbreviation. "PDT" is only true for summer dates and a
 * campaign picked in September may start in December, when it is "PST"; the
 * city is right all year.
 */
final class Timezone_Label {

	/**
	 * The zone's name for a sentence such as "Los Angeles time".
	 *
	 * @param string $identifier What `wp_timezone_string()` returns: an IANA
	 *                           identifier such as `America/Los_Angeles`,
	 *                           `UTC`, or a manual offset such as `+05:30`.
	 * @return string `Los Angeles`, `UTC` or `UTC+05:30`; `UTC` for anything unreadable.
	 */
	public static function name( string $identifier ): string {
		$identifier = trim( $identifier );

		if ( 1 === preg_match( '/^[+-]\d{2}:\d{2}$/', $identifier ) ) {
			return '+00:00' === $identifier || '-00:00' === $identifier ? 'UTC' : 'UTC' . $identifier;
		}

		if ( '' === $identifier || 'UTC' === $identifier || 'Etc/UTC' === $identifier ) {
			return 'UTC';
		}

		$slash = strrpos( $identifier, '/' );
		$place = false === $slash ? $identifier : substr( $identifier, $slash + 1 );

		return '' === $place ? 'UTC' : str_replace( '_', ' ', $place );
	}
}
