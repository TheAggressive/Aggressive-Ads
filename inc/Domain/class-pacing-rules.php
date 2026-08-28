<?php
/**
 * Delivery goals, caps, and pacing velocity evaluation rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure pacing logic for serve-time goal, cap, and velocity evaluation.
 */
final class Pacing_Rules {

	public const MODE_EVEN = 'even';
	public const MODE_ASAP = 'asap';

	/**
	 * Tolerance buffer (10%) allowed ahead of schedule before EVEN pacing throttles.
	 */
	public const EVEN_PACING_AHEAD_TOLERANCE = 0.10;

	/**
	 * Evaluates whether an assignment candidate meets pacing and cap criteria at $now.
	 *
	 * @param array<string, mixed> $row Candidate data.
	 * @param int                  $now Current time in UTC seconds.
	 * @return string|null Null when eligible, or an Exclusion_Reason code.
	 */
	public static function evaluate_candidate( array $row, int $now ): ?string {
		$lifetime_cap    = self::extract_lifetime_cap( $row );
		$daily_cap       = self::extract_daily_cap( $row );
		$delivered_life  = self::extract_delivered_lifetime( $row );
		$delivered_today = self::extract_delivered_today( $row );
		$pacing_mode     = self::extract_pacing_mode( $row );
		$start_at_ts     = (int) ( $row['start_at_ts'] ?? 0 );
		$end_at_ts       = (int) ( $row['end_at_ts'] ?? 0 );

		// 1. Lifetime cap / total goal enforcement.
		if ( $lifetime_cap > 0 && $delivered_life >= $lifetime_cap ) {
			return Exclusion_Reason::PACING_LIFETIME_CAP_REACHED;
		}

		// 2. Daily cap enforcement.
		if ( $daily_cap > 0 && $delivered_today >= $daily_cap ) {
			return Exclusion_Reason::PACING_DAILY_CAP_REACHED;
		}

		// 3. ASAP mode has no velocity throttling.
		if ( self::MODE_ASAP === $pacing_mode ) {
			return null;
		}

		// 4. EVEN mode flight schedule velocity evaluation.
		if ( self::MODE_EVEN === $pacing_mode && $lifetime_cap > 0 && $start_at_ts > 0 && $end_at_ts > $start_at_ts ) {
			$duration = $end_at_ts - $start_at_ts;
			$elapsed  = max( 0, min( $duration, $now - $start_at_ts ) );

			$time_ratio      = (float) $elapsed / (float) $duration;
			$delivered_ratio = (float) $delivered_life / (float) $lifetime_cap;

			// If delivered percentage exceeds expected time elapsed plus tolerance, throttle.
			if ( $delivered_ratio > ( $time_ratio + self::EVEN_PACING_AHEAD_TOLERANCE ) ) {
				return Exclusion_Reason::PACING_THROTTLED;
			}
		}

		// 5. EVEN mode daily velocity evaluation (if daily cap is configured).
		if ( self::MODE_EVEN === $pacing_mode && $daily_cap > 0 ) {
			$seconds_in_day  = 86400;
			$day_elapsed     = $now % $seconds_in_day;
			$day_time_ratio  = (float) $day_elapsed / (float) $seconds_in_day;
			$day_deliv_ratio = (float) $delivered_today / (float) $daily_cap;

			if ( $day_deliv_ratio > ( $day_time_ratio + self::EVEN_PACING_AHEAD_TOLERANCE ) ) {
				return Exclusion_Reason::PACING_THROTTLED;
			}
		}

		return null;
	}

	/**
	 * Extracts effective lifetime cap or goal amount.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 */
	public static function extract_lifetime_cap( array $row ): int {
		if ( isset( $row['lifetime_cap'] ) && is_numeric( $row['lifetime_cap'] ) && (int) $row['lifetime_cap'] > 0 ) {
			return (int) $row['lifetime_cap'];
		}

		if ( isset( $row['goal_amount'] ) && is_numeric( $row['goal_amount'] ) && (int) $row['goal_amount'] > 0 ) {
			return (int) $row['goal_amount'];
		}

		$settings = self::extract_settings( $row );
		if ( isset( $settings['lifetime_cap'] ) && is_numeric( $settings['lifetime_cap'] ) ) {
			return max( 0, (int) $settings['lifetime_cap'] );
		}

		if ( isset( $settings['goal_amount'] ) && is_numeric( $settings['goal_amount'] ) ) {
			return max( 0, (int) $settings['goal_amount'] );
		}

		return 0;
	}

	/**
	 * Extracts daily cap.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 */
	public static function extract_daily_cap( array $row ): int {
		if ( isset( $row['daily_cap'] ) && is_numeric( $row['daily_cap'] ) && (int) $row['daily_cap'] > 0 ) {
			return (int) $row['daily_cap'];
		}

		$settings = self::extract_settings( $row );
		if ( isset( $settings['daily_cap'] ) && is_numeric( $settings['daily_cap'] ) ) {
			return max( 0, (int) $settings['daily_cap'] );
		}

		return 0;
	}

	/**
	 * Extracts total delivered lifetime units.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 */
	public static function extract_delivered_lifetime( array $row ): int {
		if ( isset( $row['delivered_lifetime'] ) && is_numeric( $row['delivered_lifetime'] ) ) {
			return max( 0, (int) $row['delivered_lifetime'] );
		}

		return 0;
	}

	/**
	 * Extracts delivered today units.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 */
	public static function extract_delivered_today( array $row ): int {
		if ( isset( $row['delivered_today'] ) && is_numeric( $row['delivered_today'] ) ) {
			return max( 0, (int) $row['delivered_today'] );
		}

		return 0;
	}

	/**
	 * Extracts pacing mode ('even' or 'asap').
	 *
	 * @param array<string, mixed> $row Candidate row.
	 */
	public static function extract_pacing_mode( array $row ): string {
		if ( isset( $row['pacing_mode'] ) && is_string( $row['pacing_mode'] ) ) {
			$mode = strtolower( trim( $row['pacing_mode'] ) );
			if ( in_array( $mode, array( self::MODE_EVEN, self::MODE_ASAP ), true ) ) {
				return $mode;
			}
		}

		$settings = self::extract_settings( $row );
		if ( isset( $settings['pacing_mode'] ) && is_string( $settings['pacing_mode'] ) ) {
			$mode = strtolower( trim( $settings['pacing_mode'] ) );
			if ( in_array( $mode, array( self::MODE_EVEN, self::MODE_ASAP ), true ) ) {
				return $mode;
			}
		}

		return self::MODE_EVEN;
	}

	/**
	 * Extracts delivery settings array from candidate row.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 * @return array<string, mixed>
	 */
	private static function extract_settings( array $row ): array {
		if ( isset( $row['delivery_settings'] ) ) {
			if ( is_array( $row['delivery_settings'] ) ) {
				return $row['delivery_settings'];
			}
			if ( is_string( $row['delivery_settings'] ) ) {
				$decoded = json_decode( $row['delivery_settings'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return array();
	}
}
