<?php
/**
 * Exact schedule and daypart evaluation rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Pure scheduling logic for serve-time timestamp and daypart evaluation.
 */
final class Schedule_Rules {

	/**
	 * Minutes in a standard 24-hour day.
	 */
	public const MINUTES_PER_DAY = 1440;

	/**
	 * Whether delivery settings carry a schedule this engine can evaluate.
	 *
	 * Only the keys this phase owns — dayparts and timezone. Other stages
	 * validate their own corner of the same blob, so nothing here rejects a key
	 * it does not recognise.
	 *
	 * @param mixed $settings Decoded delivery settings.
	 * @return array<int, string> Human-readable problems; empty when valid.
	 */
	public static function validate_delivery_settings( mixed $settings ): array {
		if ( ! is_array( $settings ) ) {
			return array( 'Delivery settings must be an object.' );
		}

		$errors = array();

		if ( isset( $settings['timezone'] ) ) {
			$timezone = $settings['timezone'];

			if ( ! is_string( $timezone ) || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
				$errors[] = 'Timezone must be a recognised identifier, such as "America/New_York".';
			}
		}

		if ( ! isset( $settings['dayparts'] ) ) {
			return $errors;
		}

		if ( ! is_array( $settings['dayparts'] ) ) {
			return array_merge( $errors, array( '"dayparts" must be a list of rules.' ) );
		}

		foreach ( $settings['dayparts'] as $index => $rule ) {
			$errors = array_merge( $errors, self::validate_daypart( $rule, (int) $index ) );
		}

		return $errors;
	}

	/**
	 * Validates one daypart rule.
	 *
	 * @param mixed $rule  Daypart rule.
	 * @param int   $index Position, for a message a person can act on.
	 * @return array<int, string>
	 */
	private static function validate_daypart( mixed $rule, int $index ): array {
		if ( ! is_array( $rule ) ) {
			return array( sprintf( 'Daypart %d must be an object.', $index + 1 ) );
		}

		$errors = array();
		$days   = $rule['days'] ?? null;

		if ( null !== $days ) {
			if ( ! is_array( $days ) ) {
				$errors[] = sprintf( 'Daypart %d: "days" must be a list.', $index + 1 );
			} else {
				foreach ( $days as $day ) {
					if ( ! is_int( $day ) || $day < 0 || $day > 7 ) {
						$errors[] = sprintf( 'Daypart %d: days are 0-7.', $index + 1 );
						break;
					}
				}
			}
		}

		foreach ( array( 'start_minute', 'end_minute' ) as $key ) {
			if ( ! isset( $rule[ $key ] ) ) {
				continue;
			}

			$minute = $rule[ $key ];

			if ( ! is_int( $minute ) || $minute < 0 || $minute > self::MINUTES_PER_DAY ) {
				$errors[] = sprintf( 'Daypart %d: "%s" is a minute of the day, 0-%d.', $index + 1, $key, self::MINUTES_PER_DAY );
			}
		}

		return $errors;
	}

	/**
	 * Evaluates whether an assignment or line-item row is eligible at $now.
	 *
	 * @param array<string, mixed> $row Candidate data.
	 * @param int                  $now Evaluation time, UTC seconds.
	 * @return string|null Null when eligible, or an Exclusion_Reason code.
	 */
	public static function evaluate_candidate( array $row, int $now ): ?string {
		$start_at_ts = (int) ( $row['start_at_ts'] ?? 0 );
		$end_at_ts   = (int) ( $row['end_at_ts'] ?? 0 );

		$window_verdict = self::evaluate_window( $now, $start_at_ts, $end_at_ts );
		if ( null !== $window_verdict ) {
			return $window_verdict;
		}

		$dayparts = self::extract_dayparts( $row );
		if ( null === $dayparts || array() === $dayparts ) {
			return null;
		}

		$timezone = self::extract_timezone( $row );

		return self::evaluate_dayparts( $now, $dayparts, $timezone );
	}

	/**
	 * Evaluates UTC boundary timestamps.
	 *
	 * @param int $now         Evaluation time in UTC seconds.
	 * @param int $start_at_ts Start timestamp (0 if unbounded).
	 * @param int $end_at_ts   End timestamp (0 if unbounded).
	 * @return string|null Null when within window, or exclusion reason.
	 */
	public static function evaluate_window( int $now, int $start_at_ts, int $end_at_ts ): ?string {
		if ( $start_at_ts > 0 && $now < $start_at_ts ) {
			return Exclusion_Reason::SCHEDULE_NOT_STARTED;
		}

		if ( $end_at_ts > 0 && $now >= $end_at_ts ) {
			return Exclusion_Reason::SCHEDULE_EXPIRED;
		}

		return null;
	}

	/**
	 * Evaluates daypart rules for a given instant in a specified timezone.
	 *
	 * @param int                              $now      Evaluation time in UTC seconds.
	 * @param array<int, array<string, mixed>> $dayparts Configured daypart rules.
	 * @param string                           $timezone Timezone string (e.g., 'UTC', 'America/New_York').
	 * @return string|null Null when matched, or exclusion reason.
	 */
	public static function evaluate_dayparts( int $now, array $dayparts, string $timezone = 'UTC' ): ?string {
		if ( array() === $dayparts ) {
			return null;
		}

		try {
			$tz = new DateTimeZone( '' !== $timezone ? $timezone : 'UTC' );
			$dt = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		} catch ( Throwable ) {
			return Exclusion_Reason::SCHEDULE_INVALID_TIMEZONE;
		}

		$iso_day       = (int) $dt->format( 'N' ); // 1 (Monday) to 7 (Sunday).
		$w_day         = (int) $dt->format( 'w' ); // 0 (Sunday) to 6 (Saturday).
		$minute_of_day = ( (int) $dt->format( 'G' ) * 60 ) + (int) $dt->format( 'i' );

		foreach ( $dayparts as $rule ) {
			if ( self::matches_daypart_rule( $rule, $iso_day, $w_day, $minute_of_day ) ) {
				return null;
			}
		}

		return Exclusion_Reason::SCHEDULE_DAYPART_EXCLUDED;
	}

	/**
	 * Tests if current day and minute of day match a single daypart rule.
	 *
	 * @param array<string, mixed> $rule          Daypart rule definition.
	 * @param int                  $iso_day       Current ISO day (1-7).
	 * @param int                  $w_day         Current standard day (0-6).
	 * @param int                  $minute_of_day Current minute of day (0-1439).
	 */
	private static function matches_daypart_rule( array $rule, int $iso_day, int $w_day, int $minute_of_day ): bool {
		$days = $rule['days'] ?? null;

		if ( is_array( $days ) && array() !== $days ) {
			$days_int = array_map( 'intval', $days );
			if ( ! in_array( $iso_day, $days_int, true ) && ! in_array( $w_day, $days_int, true ) ) {
				return false;
			}
		}

		$start_minute = self::parse_start_minute( $rule );
		$end_minute   = self::parse_end_minute( $rule );

		if ( null === $start_minute && null === $end_minute ) {
			return true;
		}

		$start = $start_minute ?? 0;
		$end   = $end_minute ?? self::MINUTES_PER_DAY;

		if ( $start <= $end ) {
			return $minute_of_day >= $start && $minute_of_day < $end;
		}

		// Overnight span (e.g. 22:00 to 06:00).
		return $minute_of_day >= $start || $minute_of_day < $end;
	}

	/**
	 * Parses start minute of day (0-1440) from a rule array.
	 *
	 * @param array<string, mixed> $rule Rule configuration.
	 */
	private static function parse_start_minute( array $rule ): ?int {
		if ( isset( $rule['start_minute'] ) && is_numeric( $rule['start_minute'] ) ) {
			return max( 0, min( self::MINUTES_PER_DAY, (int) $rule['start_minute'] ) );
		}

		if ( isset( $rule['start_hour'] ) && is_numeric( $rule['start_hour'] ) ) {
			return max( 0, min( self::MINUTES_PER_DAY, (int) $rule['start_hour'] * 60 ) );
		}

		if ( isset( $rule['start_time'] ) && is_string( $rule['start_time'] ) ) {
			return self::parse_time_string( $rule['start_time'] );
		}

		return null;
	}

	/**
	 * Parses end minute of day (0-1440) from a rule array.
	 *
	 * @param array<string, mixed> $rule Rule configuration.
	 */
	private static function parse_end_minute( array $rule ): ?int {
		if ( isset( $rule['end_minute'] ) && is_numeric( $rule['end_minute'] ) ) {
			return max( 0, min( self::MINUTES_PER_DAY, (int) $rule['end_minute'] ) );
		}

		if ( isset( $rule['end_hour'] ) && is_numeric( $rule['end_hour'] ) ) {
			return max( 0, min( self::MINUTES_PER_DAY, (int) $rule['end_hour'] * 60 ) );
		}

		if ( isset( $rule['end_time'] ) && is_string( $rule['end_time'] ) ) {
			return self::parse_time_string( $rule['end_time'] );
		}

		return null;
	}

	/**
	 * Parses HH:MM string to minute of day (0-1439).
	 *
	 * @param string $time Time string (e.g., "14:30").
	 */
	private static function parse_time_string( string $time ): ?int {
		$parts = explode( ':', trim( $time ) );
		if ( count( $parts ) >= 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
			$hour   = max( 0, min( 23, (int) $parts[0] ) );
			$minute = max( 0, min( 59, (int) $parts[1] ) );
			return ( $hour * 60 ) + $minute;
		}

		return null;
	}

	/**
	 * Extracts dayparts configuration from row data.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function extract_dayparts( array $row ): ?array {
		if ( isset( $row['dayparts'] ) ) {
			if ( is_array( $row['dayparts'] ) ) {
				return $row['dayparts'];
			}
			if ( is_string( $row['dayparts'] ) ) {
				$decoded = json_decode( $row['dayparts'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		if ( isset( $row['delivery_settings'] ) ) {
			$settings = is_array( $row['delivery_settings'] )
				? $row['delivery_settings']
				: json_decode( (string) $row['delivery_settings'], true );

			if ( is_array( $settings ) && isset( $settings['dayparts'] ) && is_array( $settings['dayparts'] ) ) {
				return $settings['dayparts'];
			}
		}

		return null;
	}

	/**
	 * Extracts timezone identifier from row data.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 */
	private static function extract_timezone( array $row ): string {
		if ( isset( $row['timezone'] ) && is_string( $row['timezone'] ) && '' !== trim( $row['timezone'] ) ) {
			return trim( $row['timezone'] );
		}

		if ( isset( $row['delivery_settings'] ) ) {
			$settings = is_array( $row['delivery_settings'] )
				? $row['delivery_settings']
				: json_decode( (string) $row['delivery_settings'], true );

			if ( is_array( $settings ) && isset( $settings['timezone'] ) && is_string( $settings['timezone'] ) ) {
				return trim( $settings['timezone'] );
			}
		}

		return 'UTC';
	}
}
