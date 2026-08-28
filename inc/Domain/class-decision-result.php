<?php
/**
 * One decision's outcome.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Either a winner assignment row or a stated absence reason.
 */
final class Decision_Result {

	/**
	 * Outcome of one pipeline run.
	 *
	 * @param array<string, mixed>|null $winner Assignment row, when one survived.
	 * @param string|null               $reason Absence reason from Exclusion_Reason.
	 */
	public function __construct(
		public readonly ?array $winner,
		public readonly ?string $reason = null
	) {
	}

	/**
	 * A winning assignment.
	 *
	 * @param array<string, mixed> $winner Assignment row.
	 */
	public static function won( array $winner ): self {
		return new self( $winner );
	}

	/**
	 * No candidate survived.
	 *
	 * @param string $reason One of Exclusion_Reason.
	 */
	public static function empty( string $reason ): self {
		return new self( null, $reason );
	}

	/**
	 * Whether a winner was chosen.
	 */
	public function has_winner(): bool {
		return null !== $this->winner;
	}
}
