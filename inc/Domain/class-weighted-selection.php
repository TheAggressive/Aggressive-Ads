<?php
/**
 * Weighted pick among decision candidates.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Deterministic given the same seed, candidates and weights.
 */
final class Weighted_Selection {

	/**
	 * Chooses one eligible candidate by weight.
	 *
	 * Candidates must already be in stable order (ascending assignment id).
	 *
	 * @param array<int, Decision_Candidate> $candidates Eligible survivors.
	 * @param int                            $seed       Non-negative draw.
	 * @return array{winner: Decision_Candidate|null, losers: list<Decision_Candidate>}
	 */
	public static function choose( array $candidates, int $seed ): array {
		if ( array() === $candidates ) {
			return array(
				'winner' => null,
				'losers' => array(),
			);
		}

		$total = 0;

		foreach ( $candidates as $candidate ) {
			$total += $candidate->weight();
		}

		if ( $total <= 0 ) {
			return array(
				'winner' => null,
				'losers' => array_values( $candidates ),
			);
		}

		$draw   = self::normalize_seed( $seed, $total );
		$winner = null;
		$index  = 0;

		foreach ( $candidates as $candidate ) {
			$weight = $candidate->weight();

			if ( $draw < $weight ) {
				$winner = $candidate;
				break;
			}

			$draw -= $weight;
			++$index;
		}

		if ( null === $winner ) {
			$winner = $candidates[ array_key_last( $candidates ) ];
			$index  = array_key_last( $candidates );
		}

		$losers = array();

		foreach ( $candidates as $key => $candidate ) {
			if ( $key !== $index ) {
				$losers[] = $candidate;
			}
		}

		return array(
			'winner' => $winner,
			'losers' => $losers,
		);
	}

	/**
	 * Maps any integer seed into [0, total).
	 *
	 * @param int $seed  Caller-supplied draw.
	 * @param int $total Sum of eligible weights.
	 */
	private static function normalize_seed( int $seed, int $total ): int {
		if ( $total <= 0 ) {
			return 0;
		}

		$mod = $seed % $total;

		return $mod >= 0 ? $mod : $mod + $total;
	}
}
