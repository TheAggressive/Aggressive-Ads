<?php
/**
 * Facts known about one request.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * P3 carries only placement and clock. Later phases add request facts here
 * without changing the pipeline shape.
 */
final class Decision_Context {

	/**
	 * Facts supplied to every stage for one fill.
	 *
	 * @param int                  $placement_id Placement post id.
	 * @param int                  $now          Evaluation time, UTC seconds.
	 * @param array<string, mixed> $facts        Request and environment targeting facts.
	 */
	public function __construct(
		public readonly int $placement_id,
		public readonly int $now,
		public readonly array $facts = array()
	) {
	}
}
