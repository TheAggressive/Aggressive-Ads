<?php
/**
 * Pure decision stages.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Each stage receives survivors and returns the next list. Order is fixed.
 */
interface Decision_Stage {

	/**
	 * Stage identifier for traces.
	 */
	public function name(): string;

	/**
	 * Evaluates every candidate and returns the full list with verdicts.
	 *
	 * @param array<int, Decision_Candidate> $candidates Current candidates.
	 * @param Decision_Context               $context    Request facts.
	 * @return array<int, Decision_Candidate>
	 */
	public function evaluate( array $candidates, Decision_Context $context ): array;
}
