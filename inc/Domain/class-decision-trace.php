<?php
/**
 * Staff-only evidence of one decision.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Computed per request, never stored or returned to visitors.
 */
final class Decision_Trace {

	/**
	 * Holds every candidate verdict and the final result.
	 *
	 * @param list<array<string, mixed>> $entries Candidate verdicts.
	 * @param Decision_Result            $result  Final outcome.
	 */
	public function __construct(
		public readonly array $entries,
		public readonly Decision_Result $result
	) {
	}

	/**
	 * Builds a trace from evaluated candidates and the final result.
	 *
	 * @param array<int, Decision_Candidate> $candidates Every candidate considered.
	 * @param Decision_Result                $result     Final outcome.
	 */
	public static function from( array $candidates, Decision_Result $result ): self {
		$entries = array();

		foreach ( $candidates as $candidate ) {
			$row       = $candidate->row;
			$entries[] = array(
				'assignment_id'   => (int) ( $row['id'] ?? 0 ),
				'campaign_id'     => (int) ( $row['campaign_id'] ?? 0 ),
				'line_item_id'    => (int) ( $row['line_item_id'] ?? 0 ),
				'organization_id' => (int) ( $row['organization_id'] ?? 0 ),
				'weight'          => (int) ( $row['weight'] ?? 0 ),
				'excluded'        => ! $candidate->is_eligible(),
				'reason'          => $candidate->exclusion_reason,
				'stage'           => $candidate->exclusion_stage,
			);
		}

		return new self( $entries, $result );
	}
}
