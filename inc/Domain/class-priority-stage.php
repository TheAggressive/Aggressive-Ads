<?php
/**
 * Priority tier selection stage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Throwable;

/**
 * Retains only candidates belonging to the highest eligible priority tier.
 */
final class Priority_Stage implements Decision_Stage {

	/**
	 * Stage identifier for traces.
	 */
	public function name(): string {
		return 'priority';
	}

	/**
	 * Filters candidates to the highest eligible priority tier.
	 *
	 * @param array<int, Decision_Candidate> $candidates Current candidates.
	 * @param Decision_Context               $context    Request facts.
	 * @return array<int, Decision_Candidate>
	 */
	public function evaluate( array $candidates, Decision_Context $context ): array {
		unset( $context );

		$highest = Priority_Rules::highest_priority( $candidates );

		if ( null === $highest ) {
			return $candidates;
		}

		$evaluated = array();

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate->is_eligible() ) {
				$evaluated[] = $candidate;
				continue;
			}

			try {
				$priority = Priority_Rules::extract_priority( $candidate->row );

				if ( $priority > $highest ) {
					$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::PRIORITY_LOWER );
					continue;
				}

				$evaluated[] = $candidate;
			} catch ( Throwable ) {
				$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::PRIORITY_STAGE_ERROR );
			}
		}

		return $evaluated;
	}
}
