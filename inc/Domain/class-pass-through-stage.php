<?php
/**
 * A stage that passes every candidate until a later phase gives it meaning.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * P3 installs the seam; P4–P9 own the semantics.
 */
final class Pass_Through_Stage implements Decision_Stage {

	/**
	 * Builds a pass-through stage for a later policy phase.
	 *
	 * @param string $stage_name Stable stage identifier.
	 */
	public function __construct(
		private readonly string $stage_name
	) {
	}

	/**
	 * Stage identifier for traces.
	 */
	public function name(): string {
		return $this->stage_name;
	}

	/**
	 * Leaves every candidate eligible.
	 *
	 * @param array<int, Decision_Candidate> $candidates Current candidates.
	 * @param Decision_Context               $context    Request facts.
	 * @return array<int, Decision_Candidate>
	 */
	public function evaluate( array $candidates, Decision_Context $context ): array {
		unset( $context );

		return $candidates;
	}
}
