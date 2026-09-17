<?php
/**
 * A timezone's short name, readable wherever PHP only has an offset.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * PHP's `T` format gives "PDT" for a zone with a common abbreviation and a
 * bare "+0530" or "-03" for one without, which reads as arithmetic. This
 * keeps the first and prefixes the second with UTC.
 *
 * The name belongs to one date: a zone is "PDT" in September and "PST" in
 * December, so callers pass the `T` of the day being described, never today's.
 */
final class Timezone_Label {

	/**
	 * A readable short name for PHP's `T` output.
	 *
	 * @param string $abbreviation `DateTimeInterface::format( 'T' )` for the day described.
	 * @return string `PDT`, `UTC+05:30`, `UTC-03`, or `UTC` for an empty or zero offset.
	 */
	public static function abbreviation( string $abbreviation ): string {
		$abbreviation = trim( $abbreviation );

		// PHP writes a manual offset as "+05:30", "+0530", "-03" or "GMT+0530".
		if ( 1 !== preg_match( '/^(?:GMT|UTC)?([+-])(\d{2}):?(\d{2})?$/', $abbreviation, $offset ) ) {
			return '' === $abbreviation ? 'UTC' : $abbreviation;
		}

		$minutes = $offset[3] ?? '';

		if ( '00' === $offset[2] && in_array( $minutes, array( '', '00' ), true ) ) {
			return 'UTC';
		}

		return 'UTC' . $offset[1] . $offset[2] . ( '' === $minutes || '00' === $minutes ? '' : ':' . $minutes );
	}
}
