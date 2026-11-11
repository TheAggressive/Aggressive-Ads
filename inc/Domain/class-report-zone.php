<?php
/**
 * Turning a calendar day in a named timezone into the UTC hours it covers.
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
 * **Hours, not days, are what can be re-bucketed.** A UTC day cannot be split
 * after the fact. An hour can be assigned to whichever local day contains its
 * start, which is exact for whole-hour offsets and the documented approximation
 * for a zone such as `Asia/Kolkata` whose offset is thirty minutes: the UTC
 * hour that straddles local midnight is counted on the day that contains the
 * hour's start, never invented as two half-hours.
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
	 * The local calendar day that contains the start of a stored UTC hour.
	 *
	 * @param string $hour_utc  `Y-m-d H:00:00` (or a more precise UTC instant).
	 * @param string $timezone  Named zone, or UTC.
	 */
	public static function local_day( string $hour_utc, string $timezone ): string {
		$hour = self::parse_instant( $hour_utc );

		return null === $hour ? '' : $hour->setTimezone( new \DateTimeZone( self::normalize( $timezone ) ) )->format( 'Y-m-d' );
	}

	/**
	 * Inclusive start and exclusive end of a local day range, as UTC instants.
	 *
	 * The end is exclusive so a query can use `hour_utc >= start AND hour_utc < end`
	 * without caring whether the zone's offset is a whole hour. For
	 * `Asia/Kolkata` the bounds land on the half-hour; stored hours are still
	 * clock hours, and SQL's datetime comparison includes the ones whose start
	 * falls inside the range.
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

		$utc = new \DateTimeZone( self::UTC );

		return array(
			'start' => $start->setTimezone( $utc ),
			'end'   => $end->setTimezone( $utc ),
		);
	}

	/**
	 * Every stored UTC hour whose start falls inside a local day range.
	 *
	 * Spring-forward days yield 23 hours; fall-back days yield 25. That is
	 * the test that the bounds came from the zone and not from `+ 24 hours`.
	 *
	 * @param string $start_day Inclusive first local day, `Y-m-d`.
	 * @param string $end_day   Inclusive last local day, `Y-m-d`.
	 * @param string $timezone  Named zone, or UTC.
	 * @return list<string> `Y-m-d H:00:00` UTC, oldest first.
	 */
	public static function hours_in( string $start_day, string $end_day, string $timezone ): array {
		$bounds = self::utc_bounds( $start_day, $end_day, $timezone );

		if ( null === $bounds ) {
			return array();
		}

		$hours  = array();
		$cursor = $bounds['start'];

		if ( (int) $cursor->format( 'i' ) > 0 || (int) $cursor->format( 's' ) > 0 ) {
			$cursor = $cursor->setTime( (int) $cursor->format( 'H' ), 0, 0 )->modify( '+1 hour' );
		}

		while ( $cursor < $bounds['end'] ) {
			$hours[] = $cursor->format( 'Y-m-d H:00:00' );
			$cursor  = $cursor->modify( '+1 hour' );
		}

		return $hours;
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
