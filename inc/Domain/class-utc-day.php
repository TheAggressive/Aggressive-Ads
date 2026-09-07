<?php
/**
 * One reading of a `Y-m-d` day, shared by everything that stores one.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses and validates the UTC days this plugin's counters are keyed by.
 *
 * One reading, and the strict one. Three repositories each carried a private
 * `is_day()` matching `/^\d{4}-\d{2}-\d{2}$/`, which accepts `2026-13-45` — so
 * an impossible day produced a query that matched nothing, indistinguishable
 * from a placement with no data.
 *
 * **The timezone is load-bearing.** `DateTimeImmutable` without one reads the
 * ambient default, which WordPress sets from a site setting. Samoa crossed the
 * date line at the end of 2011, so `2011-12-30` does not exist in
 * `Pacific/Apia` — a publisher there would silently lose a day of history.
 */
final class Utc_Day {

	/** The stored format, and the only one accepted. */
	public const FORMAT = 'Y-m-d';

	/**
	 * A day as a UTC midnight, or null when it is not a real day.
	 *
	 * The round-trip comparison is what makes this strict: PHP happily rolls
	 * `2026-02-30` forward to the first of March, so a parse that succeeded
	 * would otherwise be taken as proof the input was a date. Comparing the
	 * reformatted result against the input catches every such rollover.
	 *
	 * @param string $day Candidate `Y-m-d`.
	 */
	public static function parse( string $day ): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( '!' . self::FORMAT, $day, new DateTimeZone( 'UTC' ) );

		if ( false === $date || $date->format( self::FORMAT ) !== $day ) {
			return null;
		}

		return $date;
	}

	/**
	 * Whether a string names a real UTC day.
	 *
	 * @param string $day Candidate `Y-m-d`.
	 */
	public static function is_day( string $day ): bool {
		return null !== self::parse( $day );
	}

	/**
	 * Whether two days are a window, in order.
	 *
	 * Both ends have to be real days *and* the range has to run forwards. The
	 * pair is asked together because every caller needs both answers and a
	 * caller that checked only one would accept a reversed window of two
	 * perfectly valid dates.
	 *
	 * @param string $from First day, inclusive.
	 * @param string $to   Last day, inclusive.
	 */
	public static function is_window( string $from, string $to ): bool {
		return self::is_day( $from ) && self::is_day( $to ) && $to >= $from;
	}
}
