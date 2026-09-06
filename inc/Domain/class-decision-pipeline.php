<?php
/**
 * Runs the decision pipeline.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Turns assignment rows into a winner, losers and a trace.
 */
final class Decision_Pipeline {

	public const STAGE_SCHEDULE  = 'schedule';
	public const STAGE_TARGETING = 'targeting';
	public const STAGE_FREQUENCY = 'frequency';
	public const STAGE_PACING    = 'pacing';
	public const STAGE_PRIORITY  = 'priority';
	public const STAGE_SELECTION = 'selection';

	/**
	 * Ordered stages before selection.
	 *
	 * @param array<int, Decision_Stage> $stages Pipeline stages.
	 */
	public function __construct(
		private readonly array $stages
	) {
	}

	/**
	 * Standard pipeline: eligibility, exact schedule, targeting, frequency, pacing, priority, weighted selection.
	 *
	 * @param Frequency_Store|null $frequency_store Frequency storage backend.
	 */
	public static function standard( ?Frequency_Store $frequency_store = null ): self {
		return new self(
			array(
				new Eligibility_Stage(),
				new Schedule_Stage(),
				new Targeting_Stage(),
				new Frequency_Stage( $frequency_store ?? new Array_Frequency_Store() ),
				new Pacing_Stage(),
				new Priority_Stage(),
			)
		);
	}

	/**
	 * Executes every stage and selects a winner.
	 *
	 * @param list<array<string, mixed>> $rows    Assignment rows from the repository.
	 * @param Decision_Request           $request Evaluation inputs.
	 * @return array{result: Decision_Result, trace: Decision_Trace, candidates: array<int, Decision_Candidate>}
	 */
	public function decide( array $rows, Decision_Request $request ): array {
		$context    = new Decision_Context( $request->placement_id, $request->now, $request->facts );
		$candidates = array_map(
			static fn ( array $row ): Decision_Candidate => new Decision_Candidate( $row ),
			$rows
		);

		foreach ( $this->stages as $stage ) {
			$candidates = $stage->evaluate( $candidates, $context );
		}

		$survivors = array_values(
			array_filter(
				$candidates,
				static fn ( Decision_Candidate $candidate ): bool => $candidate->is_eligible()
			)
		);

		if ( array() === $survivors ) {
			$result = Decision_Result::empty( self::unanimous_reason( $candidates ) );
			return array(
				'result'     => $result,
				'trace'      => Decision_Trace::from( $candidates, $result ),
				'candidates' => $candidates,
			);
		}

		$selection = Weighted_Selection::choose( $survivors, $request->seed );
		$winner    = $selection['winner'];

		if ( null === $winner ) {
			$result = Decision_Result::empty( Exclusion_Reason::NO_FILL );
			return array(
				'result'     => $result,
				'trace'      => Decision_Trace::from( $candidates, $result ),
				'candidates' => $candidates,
			);
		}

		foreach ( $selection['losers'] as $loser ) {
			foreach ( $candidates as $index => $candidate ) {
				if ( $candidate->id() === $loser->id() ) {
					$candidates[ $index ] = $candidate->exclude( self::STAGE_SELECTION, Exclusion_Reason::COMPETITION_LOST );
				}
			}
		}

		$result = Decision_Result::won( $winner->row );

		return array(
			'result'     => $result,
			'trace'      => Decision_Trace::from( $candidates, $result ),
			'candidates' => $candidates,
		);
	}

	/**
	 * The reason to report when nothing survived.
	 *
	 * **Only when every candidate lost for the same one.** Per-candidate
	 * exclusions are deliberately not counted alongside the slot's outcome —
	 * that is what made the old totals meaningless, since a slot that filled
	 * still incremented every loser's reason. But when the whole field was
	 * refused for one reason, that reason *is* why the slot is empty, and
	 * reporting a generic no-fill throws away the only actionable thing about
	 * it.
	 *
	 * The case this was written for is a responsive placement serving a
	 * breakpoint nobody has supplied artwork for. "No advertisement was the
	 * size this screen asked for" tells a publisher what to do; "no
	 * advertisement was assigned to this slot" sends them to look at campaigns
	 * that are working perfectly.
	 *
	 * A mixed field stays generic, because no single reason explains it and
	 * picking one would be inventing an answer.
	 *
	 * @param array<int, Decision_Candidate> $candidates Every candidate considered.
	 */
	private static function unanimous_reason( array $candidates ): string {
		if ( array() === $candidates ) {
			return Exclusion_Reason::NO_FILL;
		}

		$reasons = array_unique(
			array_map(
				static fn ( Decision_Candidate $candidate ): string => (string) $candidate->exclusion_reason,
				$candidates
			)
		);

		if ( 1 !== count( $reasons ) ) {
			return Exclusion_Reason::NO_FILL;
		}

		$only = (string) reset( $reasons );

		return '' === $only ? Exclusion_Reason::NO_FILL : $only;
	}
}
