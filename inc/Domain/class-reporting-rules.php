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
	 * Proportional change between two counts, or null when there is no comparison.
	 *
	 * Null covers the two cases a percentage cannot express, and they are
	 * different from each other and from zero:
	 *
	 * - **the previous window is unmeasured** (`null`) — nobody was counting,
	 *   so there is nothing to compare against. A range that starts before a
	 *   metric existed is in this state, and reporting it as `-100%` would
	 *   invent a collapse out of a feature's release date;
	 * - **the previous window was zero** — every change from nothing is
	 *   infinite, and "+∞%" is not a figure anybody can act on. The count
	 *   itself already says the thing worth knowing.
	 *
	 * A ratio, not a percentage, so a display layer decides the formatting —
	 * the same contract as `ctr()`.
	 *
	 * Either side being unmeasured is enough to refuse: the rule is enforced
	 * here rather than at each call site, because a caller that coalesced a
	 * null to 0 on its way in would manufacture exactly the `-100%` this
	 * exists to prevent.
	 *
	 * @param int|null $current  Count in the reported window, or null when unmeasured.
	 * @param int|null $previous Count in the comparison window, or null when unmeasured.
	 * @return float|null Signed ratio, where 0.1 is a 10% increase.
	 */
	public static function change( ?int $current, ?int $previous ): ?float {
		if ( null === $current || null === $previous || $previous <= 0 ) {
			return null;
		}

		return ( $current - $previous ) / $previous;
	}

	/**
	 * Difference between two rates, in points rather than as a proportion.
	 *
	 * **A rate's change is measured in points, and this is not pedantry.** CTR
	 * moving from 1.0% to 1.5% is a 50% increase and half a percentage point,
	 * and "CTR up 50%" is read by nearly everybody as the wrong one of those.
	 * Counts take `change()`; rates take this.
	 *
	 * Null when either side is absent, which for a rate means there was nothing
	 * to divide by — not that the rate was zero.
	 *
	 * @param float|null $current  Rate now, between 0 and 1.
	 * @param float|null $previous Rate in the comparison window.
	 * @return float|null Signed difference, where 0.005 is half a percentage point.
	 */
	public static function point_change( ?float $current, ?float $previous ): ?float {
		if ( null === $current || null === $previous ) {
			return null;
		}

		return $current - $previous;
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
