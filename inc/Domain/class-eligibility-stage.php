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

				/*
				 * **The creative has to fit the slot actually being served.**
				 *
				 * Nothing checked this before, and nothing needed to: a
				 * placement had exactly one size and creative upload enforced
				 * it, so every candidate matched by construction. A responsive
				 * placement breaks that guarantee — it serves several sizes and
				 * may hold creatives for only one — and without this the engine
				 * would put a 728x90 into a 320x50 slot. That is a broken page
				 * and an advertiser billed for an impression their artwork did
				 * not fit.
				 *
				 * The requested size is a fact of the request rather than of
				 * the placement, because which size a responsive placement is
				 * serving depends on the viewport that asked. An absent fact
				 * means a caller that has not resolved one, and the gate stays
				 * shut rather than guessing: refusing to fill is recoverable,
				 * serving the wrong size is not.
				 */
				$requested = (string) ( $context->facts['size'] ?? '' );

				if ( '' !== $requested && $requested !== $this->candidate_size( $row ) ) {
					$evaluated[] = $candidate->exclude( $this->name(), Exclusion_Reason::ELIGIBILITY_SIZE_MISMATCH );
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

	/**
	 * The size a candidate's artwork actually is.
	 *
	 * Read from the assignment row rather than from the creative record,
	 * because the row is what delivery serves — the denormalization exists so a
	 * fill is one indexed read. A candidate whose dimensions never projected is
	 * sized `0x0`, which matches no request and is excluded rather than served
	 * at whatever size the slot happened to reserve.
	 *
	 * @param array<string, mixed> $row Assignment row.
	 */
	private function candidate_size( array $row ): string {
		return (int) ( $row['width'] ?? 0 ) . 'x' . (int) ( $row['height'] ?? 0 );
	}
}
