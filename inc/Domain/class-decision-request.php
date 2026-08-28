<?php
/**
 * One fill's inputs.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The clock and seed are supplied by the caller so a decision can be replayed.
 */
final class Decision_Request {

	/**
	 * Immutable inputs for one fill decision.
	 *
	 * @param int $placement_id Placement post id.
	 * @param int $now          Evaluation time, UTC seconds.
	 * @param int $seed         Non-negative draw for weighted selection.
	 */
	public function __construct(
		public readonly int $placement_id,
		public readonly int $now,
		public readonly int $seed
	) {
	}
}
