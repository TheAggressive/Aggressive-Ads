<?php
/**
 * Exact schedule and daypart evaluation stage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Throwable;

/**
 * Evaluates candidate timestamp bounds, timezones, and daypart restrictions.
 */
final class Schedule_Stage implements Decision_Stage {

	/**
	 * Stage identifier for traces.
	 */
	public function name(): string {
		return 'schedule';
	}

	/**
	 * Applies exact schedule and daypart checks.
	 *
	 * @param array<int, Decision_Candidate> $candidates Current candidates.
	 * @param Decision_Context               $context    Request facts including evaluation time.
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
				$reason = Schedule_Rules::evaluate_candidate( $candidate->row, $context->now );

				if ( null !== $reason ) {
					$evaluated[] = $candidate->exclude( $this->name(), $reason );
					continue;
				}

				$evaluated[] = $candidate;
			} catch ( Throwable ) {
				$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::SCHEDULE_STAGE_ERROR );
			}
		}

		return $evaluated;
	}
}
