<?php
/**
 * Priority tier rules and evaluation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure priority calculation and tier grouping.
 */
final class Priority_Rules {

	/**
	 * Default standard priority tier.
	 */
	public const DEFAULT_PRIORITY = 100;

	/**
	 * Extracts priority tier from candidate row. Lower number = higher priority.
	 *
	 * @param array<string, mixed> $row Candidate data.
	 */
	public static function extract_priority( array $row ): int {
		if ( isset( $row['priority'] ) && is_numeric( $row['priority'] ) ) {
			$priority = (int) $row['priority'];
			return $priority > 0 ? $priority : self::DEFAULT_PRIORITY;
		}

		return self::DEFAULT_PRIORITY;
	}

	/**
	 * Determines the highest priority tier among eligible candidates.
	 *
	 * @param array<int, Decision_Candidate> $candidates Candidates list.
	 * @return int|null Minimum priority value, or null if no eligible candidate.
	 */
	public static function highest_priority( array $candidates ): ?int {
		$highest = null;

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate->is_eligible() ) {
				continue;
			}

			$priority = self::extract_priority( $candidate->row );
			if ( null === $highest || $priority < $highest ) {
				$highest = $priority;
			}
		}

		return $highest;
	}
}
