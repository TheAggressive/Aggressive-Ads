<?php
/**
 * Frequency capping pipeline stage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Throwable;

/**
 * Evaluates candidate frequency limits against ephemeral visitor counts.
 */
final class Frequency_Stage implements Decision_Stage {

	/**
	 * Constructor.
	 *
	 * @param Frequency_Store $store Frequency storage provider.
	 */
	public function __construct( private readonly Frequency_Store $store ) {
	}

	/**
	 * Stage identifier for traces.
	 */
	public function name(): string {
		return 'frequency';
	}

	/**
	 * Applies frequency capping evaluations.
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
				$reason = Frequency_Rules::evaluate_candidate( $candidate->row, $context, $this->store );

				if ( null !== $reason ) {
					$evaluated[] = $candidate->exclude( $this->name(), $reason );
					continue;
				}

				$evaluated[] = $candidate;
			} catch ( Throwable ) {
				$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::FREQUENCY_STAGE_ERROR );
			}
		}

		return $evaluated;
	}
}
