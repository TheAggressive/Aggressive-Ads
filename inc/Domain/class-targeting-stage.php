<?php
/**
 * Targeting evaluation stage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Throwable;

/**
 * Evaluates candidate declarative targeting rules against request context facts.
 */
final class Targeting_Stage implements Decision_Stage {

	/**
	 * Stage identifier for traces.
	 */
	public function name(): string {
		return 'targeting';
	}

	/**
	 * Applies targeting rule evaluations.
	 *
	 * @param array<int, Decision_Candidate> $candidates Current candidates.
	 * @param Decision_Context               $context    Request facts.
	 * @return array<int, Decision_Candidate>
	 */
	public function evaluate( array $candidates, Decision_Context $context ): array {
		$evaluated = array();

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate->is_eligible() ) {
				$evaluated[] = $candidate;
				continue;
			}

			try {
				$reason = Targeting_Rules::evaluate_candidate( $candidate->row, $context );

				if ( null !== $reason ) {
					$evaluated[] = $candidate->exclude( $this->name(), $reason );
					continue;
				}

				$evaluated[] = $candidate;
			} catch ( Throwable ) {
				$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::TARGETING_STAGE_ERROR );
			}
		}

		return $evaluated;
	}
}
