<?php
/**
 * Current goal progress; never a reconstruction of historical settings.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/** Uses native goal units and the serving policy's elapsed-flight calculation. */
final class Report_Goal {

	/**
	 * Evaluate current progress independently of a report's selected period.
	 *
	 * @param array<string, mixed>                                                                  $plan Current stored settings.
	 * @param array{impressions: int, clicks: int, viewables: int|null, conversions: int|null}|null $totals Recorded native delivery, or unavailable history.
	 * @param int                                                                                   $now Snapshot clock.
	 * @return array{metric: string|null, actual: int|null, completion: float|null, elapsed: float|null, state: string}
	 */
	public static function evaluate( array $plan, ?array $totals, int $now ): array {
		$type   = (string) $plan['goal_type'];
		$goal   = (int) $plan['goal_amount'];
		$metric = in_array( $type, array( 'impressions', 'clicks', 'conversions' ), true ) ? $type : null;
		$actual = null !== $metric && null !== $totals ? $totals[ $metric ] : null;
		$base   = array(
			'metric'     => $metric,
			'actual'     => $actual,
			'completion' => null,
			'elapsed'    => null,
			'state'      => 'no_goal',
		);
		if ( 'none' === $type || $goal <= 0 ) {
			return $base;
		}
		if ( null === $actual ) {
			$base['state'] = 'not_measured';
			return $base;
		}
		$base['completion'] = $actual / $goal;
		if ( $actual >= $goal ) {
			$base['state'] = 'met';
			return $base;
		}
		$status = (string) $plan['status'];
		if ( ! in_array( $status, Line_Item_Rules::statuses(), true ) || ! in_array( $plan['pacing_mode'], Line_Item_Rules::PACING_MODES, true ) ) {
			$base['state'] = 'not_measured';
			return $base;
		}
		if ( in_array( $status, array( Line_Item_Rules::DRAFT, Line_Item_Rules::READY, Line_Item_Rules::PAUSED, Line_Item_Rules::COMPLETED, Line_Item_Rules::CANCELLED ), true ) ) {
			$base['state'] = $status;
			return $base;
		}
		$start = (int) $plan['start_at_ts'];
		$end   = (int) $plan['end_at_ts'];
		if ( $start > $now ) {
			$base['state'] = 'not_started';
		} elseif ( 'asap' === $plan['pacing_mode'] ) {
			$base['state'] = 'asap';
		} elseif ( $start <= 0 || $end <= $start ) {
			$base['state'] = 'no_schedule';
		} else {
			$base['elapsed'] = Pacing_Rules::flight_progress( $start, $end, $now );
			// Ignore floating-point noise at the documented ten-percentage-point boundary.
			$delta         = round( $base['completion'] - $base['elapsed'], 12 );
			$base['state'] = $delta > Pacing_Rules::EVEN_PACING_AHEAD_TOLERANCE ? 'ahead' : ( $delta < -Pacing_Rules::EVEN_PACING_AHEAD_TOLERANCE ? 'behind' : 'on_pace' );
		}
		return $base;
	}
}
