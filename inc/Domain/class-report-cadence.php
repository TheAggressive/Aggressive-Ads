<?php
/**
 * Calendar-based report release times and completed reporting periods.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/** Site-local releases stay at 09:00 across daylight-saving transitions. */
final class Report_Cadence {

	public const WEEKLY  = 'weekly';
	public const MONTHLY = 'monthly';

	/**
	 * Next future release; zero for an unsupported cadence.
	 *
	 * @param string $cadence Weekly or monthly.
	 * @param int    $now     Current Unix timestamp.
	 * @param string $zone    Site timezone.
	 */
	public static function next_due( string $cadence, int $now, string $zone ): int {
		$release = self::release( $cadence, $now, $zone );
		if ( null === $release ) {
			return 0;
		}
		if ( $release->getTimestamp() <= $now ) {
			$release = $release->modify( self::WEEKLY === $cadence ? '+1 week' : '+1 month' );
		}

		return $release->getTimestamp();
	}

	/**
	 * Most recent released period, never the open week/month or a backlog list.
	 *
	 * @param string $cadence Weekly or monthly.
	 * @param int    $now     Current Unix timestamp.
	 * @param string $zone    Site timezone.
	 */
	public static function period( string $cadence, int $now, string $zone ): ?Report_Period {
		$release = self::release( $cadence, $now, $zone );
		if ( null === $release ) {
			return null;
		}
		if ( $release->getTimestamp() > $now ) {
			$release = $release->modify( self::WEEKLY === $cadence ? '-1 week' : '-1 month' );
		}
		$end   = $release->modify( '-1 day' );
		$start = self::WEEKLY === $cadence ? $release->modify( '-1 week' ) : $release->modify( '-1 month' );

		return Report_Period::between( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), $zone );
	}

	/**
	 * This week's/month's release, which may still be in the future.
	 *
	 * @param string $cadence Weekly or monthly.
	 * @param int    $now     Current Unix timestamp.
	 * @param string $zone    Site timezone.
	 */
	private static function release( string $cadence, int $now, string $zone ): ?\DateTimeImmutable {
		if ( ! in_array( $cadence, array( self::WEEKLY, self::MONTHLY ), true ) ) {
			return null;
		}
		$local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( new \DateTimeZone( Report_Zone::normalize( $zone ) ) );

		return $local->modify( self::WEEKLY === $cadence ? 'monday this week' : 'first day of this month' )->setTime( 9, 0 );
	}
}
