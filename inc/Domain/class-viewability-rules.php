<?php
/**
 * What counts as an ad having been seen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure threshold logic, so the definition of "seen" is one testable thing.
 *
 * The numbers are the IAB display standard — half the pixels for one continuous
 * second — and they are server-owned. The browser reports what it observed; it
 * does not decide what qualifies, because a client that chose its own threshold
 * could report every fill as viewable and be technically honest about it.
 */
final class Viewability_Rules {

	/** Half the creative's pixels, the IAB display standard. */
	public const DEFAULT_RATIO_PERCENT = 50;

	/** One continuous second, likewise. */
	public const DEFAULT_DWELL_MS = 1000;

	/**
	 * A threshold below this cannot mean anything: a single pixel scrolling
	 * past would qualify, and the number would measure page traffic.
	 */
	public const MIN_RATIO_PERCENT = 1;
	public const MAX_RATIO_PERCENT = 100;

	/**
	 * Nothing shorter than a rendered frame, and nothing longer than a visit.
	 *
	 * Thirty seconds is already far beyond any standard; the ceiling exists so
	 * a typo cannot configure a threshold no impression will ever reach and
	 * leave viewability silently at zero.
	 */
	public const MIN_DWELL_MS = 100;
	public const MAX_DWELL_MS = 30000;

	/**
	 * Whether an observation qualifies as a view.
	 *
	 * Both halves must hold at once, which is the whole point: an ad fully on
	 * screen for a moment during a fast scroll was not seen, and neither was one
	 * showing a sliver for a minute.
	 *
	 * @param float $ratio_percent Percentage of the creative visible, 0-100.
	 * @param int   $dwell_ms      Continuous milliseconds at or above that ratio.
	 * @param int   $min_ratio     Required percentage.
	 * @param int   $min_dwell_ms  Required milliseconds.
	 * @return bool
	 */
	public static function is_viewable(
		float $ratio_percent,
		int $dwell_ms,
		int $min_ratio = self::DEFAULT_RATIO_PERCENT,
		int $min_dwell_ms = self::DEFAULT_DWELL_MS
	): bool {
		if ( $ratio_percent < 0.0 || $dwell_ms < 0 ) {
			return false;
		}

		return $ratio_percent >= (float) $min_ratio && $dwell_ms >= $min_dwell_ms;
	}

	/**
	 * Clamps a configured percentage into the range the client can honour.
	 *
	 * Clamped rather than refused because this arrives from settings that have
	 * already been saved; a stored value outside the range must still produce a
	 * working threshold rather than disabling measurement.
	 *
	 * @param mixed $value Configured percentage.
	 * @return int
	 */
	public static function ratio_percent( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_RATIO_PERCENT;
		}

		return max( self::MIN_RATIO_PERCENT, min( self::MAX_RATIO_PERCENT, (int) $value ) );
	}

	/**
	 * Clamps a configured dwell time the same way.
	 *
	 * @param mixed $value Configured milliseconds.
	 * @return int
	 */
	public static function dwell_ms( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_DWELL_MS;
		}

		return max( self::MIN_DWELL_MS, min( self::MAX_DWELL_MS, (int) $value ) );
	}

	/**
	 * The threshold as the client receives it.
	 *
	 * `ratio` is the 0-1 fraction `IntersectionObserver` takes, so the browser
	 * never converts a percentage and cannot round it differently than the
	 * server would.
	 *
	 * @param mixed $ratio_setting Configured percentage.
	 * @param mixed $dwell_setting Configured milliseconds.
	 * @return array{ratio: float, dwell_ms: int}
	 */
	public static function for_client( mixed $ratio_setting, mixed $dwell_setting ): array {
		return array(
			// Cast, because `100 / 100` is an int in PHP and the client is
			// promised a float.
			'ratio'    => (float) ( self::ratio_percent( $ratio_setting ) / 100 ),
			'dwell_ms' => self::dwell_ms( $dwell_setting ),
		);
	}
}
