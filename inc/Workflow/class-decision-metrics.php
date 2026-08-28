<?php
/**
 * Exclusion counters for the decision engine.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

/**
 * Aggregate exclusion reasons so "nothing is serving" has a diagnosable cause.
 */
final class Decision_Metrics {

	public const OPTION_EXCLUSIONS = 'aggr_decision_exclusion_counts';

	/**
	 * Increments one exclusion reason, aggregate and per placement.
	 *
	 * @param int    $placement_id Placement post id.
	 * @param string $reason       One of Domain\Exclusion_Reason.
	 */
	public function record_exclusion( int $placement_id, string $reason ): void {
		if ( $placement_id <= 0 || '' === $reason ) {
			return;
		}

		$counts = get_option( self::OPTION_EXCLUSIONS, array() );

		if ( ! is_array( $counts ) ) {
			$counts = array();
		}

		if ( ! isset( $counts['aggregate'] ) || ! is_array( $counts['aggregate'] ) ) {
			$counts['aggregate'] = array();
		}

		if ( ! isset( $counts['placements'] ) || ! is_array( $counts['placements'] ) ) {
			$counts['placements'] = array();
		}

		$placement_key = (string) $placement_id;

		if ( ! isset( $counts['placements'][ $placement_key ] ) || ! is_array( $counts['placements'][ $placement_key ] ) ) {
			$counts['placements'][ $placement_key ] = array();
		}

		$counts['aggregate'][ $reason ]                    = (int) ( $counts['aggregate'][ $reason ] ?? 0 ) + 1;
		$counts['placements'][ $placement_key ][ $reason ] = (int) ( $counts['placements'][ $placement_key ][ $reason ] ?? 0 ) + 1;

		update_option( self::OPTION_EXCLUSIONS, $counts, false );
	}

	/**
	 * Aggregate exclusion counts keyed by reason code.
	 *
	 * @return array<string, int>
	 */
	public function exclusion_counts(): array {
		$counts = get_option( self::OPTION_EXCLUSIONS, array() );

		if ( ! is_array( $counts ) || ! isset( $counts['aggregate'] ) || ! is_array( $counts['aggregate'] ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $counts['aggregate'] as $reason => $count ) {
			if ( is_string( $reason ) ) {
				$normalized[ $reason ] = max( 0, (int) $count );
			}
		}

		return $normalized;
	}
}
