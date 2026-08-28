<?php
/**
 * Row-level eligibility without WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Everything here is on the denormalized assignment row.
 */
final class Eligibility_Stage implements Decision_Stage {

	/**
	 * Stage identifier for traces.
	 */
	public function name(): string {
		return 'eligibility';
	}

	/**
	 * Applies row-level eligibility checks.
	 *
	 * @param array<int, Decision_Candidate> $candidates Current candidates.
	 * @param Decision_Context               $context    Request facts.
	 * @return array<int, Decision_Candidate>
	 */
	public function evaluate( array $candidates, Decision_Context $context ): array {
		unset( $context );

		$evaluated = array();

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate->is_eligible() ) {
				$evaluated[] = $candidate;
				continue;
			}

			try {
				$row = $candidate->row;

				if ( ! Assignment_Rules::is_weight( (int) ( $row['weight'] ?? 0 ) ) ) {
					$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::ELIGIBILITY_INVALID_WEIGHT );
					continue;
				}

				if ( (int) ( $row['attachment_id'] ?? 0 ) <= 0 ) {
					$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::ELIGIBILITY_MISSING_ATTACHMENT );
					continue;
				}

				$click = (string) ( $row['click_url'] ?? '' );

				if ( ! Campaign_Rules::is_valid_click_url( $click ) ) {
					$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::ELIGIBILITY_INVALID_CLICK_URL );
					continue;
				}

				$evaluated[] = $candidate;
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
				$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::ELIGIBILITY_STAGE_ERROR );
			}
		}

		return $evaluated;
	}
}
