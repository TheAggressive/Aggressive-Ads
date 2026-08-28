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
	 * Default P3 pipeline: eligibility, pass-through seams, weighted selection.
	 */
	public static function standard(): self {
		return new self(
			array(
				new Eligibility_Stage(),
				new Pass_Through_Stage( self::STAGE_TARGETING ),
				new Pass_Through_Stage( self::STAGE_FREQUENCY ),
				new Pass_Through_Stage( self::STAGE_PACING ),
				new Pass_Through_Stage( self::STAGE_PRIORITY ),
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
		$context    = new Decision_Context( $request->placement_id, $request->now );
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
			$result = Decision_Result::empty( Exclusion_Reason::NO_FILL );
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
}
