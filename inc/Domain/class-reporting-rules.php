<?php
/**
 * Pure reporting arithmetic.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Derived metric rules with no WordPress dependency.
 *
 * CTR is a ratio, not a percentage, so a display layer can format it. Null
 * means "there is nothing to divide by" — rendering that as 0% would claim
 * the ad was seen and ignored.
 */
final class Reporting_Rules {

	/**
	 * Share of delivered impressions that were seen, or null.
	 *
	 * Null covers two different absences, and the caller has to tell them
	 * apart: `$viewables` being null means no day in range was measured, while
	 * zero impressions means there is nothing to take a share of. Reporting
	 * that showed either as `0%` would claim nobody saw the ads.
	 *
	 * @param int      $impressions Delivered impressions.
	 * @param int|null $viewables   Views recorded, or null when unmeasured.
	 * @return float|null Rate between 0 and 1.
	 */
	public static function viewability( int $impressions, ?int $viewables ): ?float {
		if ( null === $viewables || $impressions <= 0 ) {
			return null;
		}

		return max( 0.0, min( 1.0, $viewables / $impressions ) );
	}

	/**
	 * Clicks per impression, or null when impressions are not positive.
	 *
	 * @param int $impressions Counted views.
	 * @param int $clicks      Counted clicks.
	 */
	public static function ctr( int $impressions, int $clicks ): ?float {
		if ( $impressions <= 0 || $clicks < 0 ) {
			return null;
		}

		return $clicks / $impressions;
	}

	/**
	 * Height of one sparkline bar as an integer 0–$track.
	 *
	 * A positive value on a positive max is at least 1 so a single impression
	 * is visible. All-zero series stay flat at 0 — that is "nobody saw an ad",
	 * not a row of equal bars that look like traffic.
	 *
	 * @param int $value Observed count.
	 * @param int $max   Series maximum.
	 * @param int $track Track height, typically 100.
	 */
	public static function bar_height( int $value, int $max, int $track = 100 ): int {
		if ( $value <= 0 || $max <= 0 || $track <= 0 ) {
			return 0;
		}

		$height = (int) round( ( $value / $max ) * $track );

		return max( 1, min( $track, $height ) );
	}
}
