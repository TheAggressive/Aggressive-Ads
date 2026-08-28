<?php
/**
 * One assignment row under evaluation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Wraps the denormalized assignment row the P2 read contract returns.
 */
final class Decision_Candidate {

	/**
	 * Wraps one assignment row under evaluation.
	 *
	 * @param array<string, mixed> $row              Assignment row from the repository.
	 * @param string|null          $exclusion_reason Set when a stage refuses this row.
	 * @param string|null          $exclusion_stage  Stage that refused, when excluded.
	 */
	public function __construct(
		public readonly array $row,
		public ?string $exclusion_reason = null,
		public ?string $exclusion_stage = null
	) {
	}

	/**
	 * Whether the candidate survives every stage so far.
	 */
	public function is_eligible(): bool {
		return null === $this->exclusion_reason;
	}

	/**
	 * Marks this candidate excluded at a stage.
	 *
	 * @param string $stage  Stage name.
	 * @param string $reason One of Exclusion_Reason.
	 */
	public function exclude( string $stage, string $reason ): self {
		return new self( $this->row, $reason, $stage );
	}

	/**
	 * Assignment id.
	 */
	public function id(): int {
		return (int) ( $this->row['id'] ?? 0 );
	}

	/**
	 * Creative revision id (creative post id).
	 */
	public function revision_id(): int {
		return (int) ( $this->row['revision_id'] ?? 0 );
	}

	/**
	 * Campaign id.
	 */
	public function campaign_id(): int {
		return (int) ( $this->row['campaign_id'] ?? 0 );
	}

	/**
	 * Delivery weight.
	 */
	public function weight(): int {
		return (int) ( $this->row['weight'] ?? 0 );
	}
}
