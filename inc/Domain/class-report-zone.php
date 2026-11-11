<?php
/**
 * Turning a calendar day in a named timezone into the UTC instants it covers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The timezone arithmetic reporting needs, with no WordPress underneath it.
 *
 * Daily rollups stay keyed by UTC day because pacing's "today" is a serving
 * question and must not move with a site owner's timezone setting. Reports are
 * a reading question: a publisher in `America/Los_Angeles` asking for Tuesday
 * means Tuesday local, including the hours that are still Monday in UTC.
 *
 * Fifteen-minute UTC buckets preserve midnight boundaries in whole-hour,
 * half-hour and 45-minute timezones, including DST. Grouping whole UTC hours
 * would put events either side of Kolkata's midnight on the same local day.
 */
final class Report_Zone {

	public const UTC = 'UTC';

	/**
	 * A timezone PHP will accept, or UTC.
	 *
	 * Invalid names become UTC rather than throwing: a report that fatals
	 * because a site option was edited by hand is worse than one that falls
	 * back to the zone every stored hour is already in.
	 *
	 * @param string $timezone Candidate identifier.
	 */
	public static function normalize( string $timezone ): string {
		$timezone = trim( $timezone );

		if ( '' === $timezone || in_array( $timezone, array( self::UTC, 'Etc/UTC', 'GMT', 'Etc/GMT', 'Z', '+00:00', '+0000' ), true ) ) {
			return self::UTC;
		}

		try {
			new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			unset( $e );

			return self::UTC;
		}

		return $timezone;
	}

	/**
	 * The calendar day `$at` falls on in `$timezone`.
	 *
	 * @param string                  $timezone Named zone, or UTC.
	 * @param \DateTimeImmutable|null $at       Instant; now in UTC when omitted.
	 */
	public static function today( string $timezone, ?\DateTimeImmutable $at = null ): string {
		$zone = new \DateTimeZone( self::normalize( $timezone ) );
		$at   = $at ?? new \DateTimeImmutable( 'now', new \DateTimeZone( self::UTC ) );

		return $at->setTimezone( $zone )->format( 'Y-m-d' );
	}

	/**
	 * The local calendar day that contains the start of a stored UTC bucket.
	 *
	 * @param string $bucket_utc  `Y-m-d H:i:s` in UTC.
	 * @param string $timezone  Named zone, or UTC.
	 */
	public static function local_day( string $bucket_utc, string $timezone ): string {
		$hour = self::parse_instant( $bucket_utc );

		return null === $hour ? '' : $hour->setTimezone( new \DateTimeZone( self::normalize( $timezone ) ) )->format( 'Y-m-d' );
	}

	/**
	 * Inclusive start and exclusive end of a local day range, as UTC instants.
	 *
	 * The end is exclusive so a query can use `bucket_utc >= start AND bucket_utc < end`
	 * without caring whether the zone's offset is a whole hour. For
	 * `Asia/Kolkata` the bounds land on the half-hour; quarter-hour bucket
	 * boundaries preserve that midnight exactly.
	 *
	 * @param string $start_day Inclusive first local day, `Y-m-d`.
	 * @param string $end_day   Inclusive last local day, `Y-m-d`.
	 * @param string $timezone  Named zone, or UTC.
	 * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null
	 */
	public static function utc_bounds( string $start_day, string $end_day, string $timezone ): ?array {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_day ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_day ) ) {
			return null;
		}

		$zone = new \DateTimeZone( self::normalize( $timezone ) );

		try {
			$start = new \DateTimeImmutable( $start_day . ' 00:00:00', $zone );
			$end   = ( new \DateTimeImmutable( $end_day . ' 00:00:00', $zone ) )->modify( '+1 day' );
		} catch ( \Exception $e ) {
			unset( $e );

			return null;
		}

		if ( $start->format( 'Y-m-d' ) !== $start_day || $end <= $start ) {
			return null;
		}

		$utc = new \DateTimeZone( self::UTC );

		return array(
			'start' => $start->setTimezone( $utc ),
			'end'   => $end->setTimezone( $utc ),
		);
	}

	/**
	 * A UTC instant as `Y-m-d H:i:s`, or null.
	 *
	 * @param string $value Candidate timestamp.
	 */
	private static function parse_instant( string $value ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $value ) ) {
			return null;
		}

		$normalized = str_replace( 'T', ' ', $value );

		if ( 10 === strlen( $normalized ) ) {
			$normalized .= ' 00:00:00';
		}

		try {
			return new \DateTimeImmutable( $normalized, new \DateTimeZone( self::UTC ) );
		} catch ( \Exception $e ) {
			unset( $e );

			return null;
		}
	}
}
