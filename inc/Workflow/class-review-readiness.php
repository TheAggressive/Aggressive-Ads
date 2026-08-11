<?php
/**
 * Advertiser-safe campaign readiness presentation.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Domain\Campaign_Rules;

/**
 * Converts canonical validator results into safe review-screen guidance.
 */
final class Review_Readiness {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Validator $validator Canonical submission validator.
	 */
	public function __construct( private readonly Campaign_Validator $validator ) {
	}

	/**
	 * Every current readiness issue, without raw validator context.
	 *
	 * Context can include URLs and internal identifiers. The browser needs an
	 * actionable sentence and edit destination, not the source data used to
	 * compose it.
	 *
	 * @param int $campaign_id Campaign post id already authorized by the caller.
	 * @return array{ready: bool, problems: array<int, array{code: string, message: string, step: string, target: string}>}
	 */
	public function for_campaign( int $campaign_id ): array {
		$result   = $this->validator->validate( $campaign_id );
		$problems = array();

		foreach ( $result->problems() as $problem ) {
			$location   = $this->edit_location( $problem['code'] );
			$problems[] = array(
				'code'    => $problem['code'],
				'message' => Campaign_Validator::message_for( $problem['code'], $problem['context'] ),
				'step'    => $location['step'],
				'target'  => $location['target'],
			);
		}

		return array(
			'ready'    => $result->is_valid(),
			'problems' => $problems,
		);
	}

	/**
	 * Wizard location that can resolve one validator problem.
	 *
	 * @param string $code Campaign_Rules problem code.
	 * @return array{step: string, target: string}
	 */
	private function edit_location( string $code ): array {
		return match ( $code ) {
			Campaign_Rules::ERROR_START_MISSING,
			Campaign_Rules::ERROR_START_IN_PAST => array(
				'step'   => 'destination',
				'target' => 'laao-ads-start-date',
			),
			Campaign_Rules::ERROR_END_BEFORE_START => array(
				'step'   => 'destination',
				'target' => 'laao-ads-end-date',
			),
			Campaign_Rules::ERROR_NO_PLACEMENTS,
			Campaign_Rules::ERROR_PLACEMENT_INACTIVE,
			Campaign_Rules::ERROR_PACKAGE_MISSING,
			Campaign_Rules::ERROR_PACKAGE_UNAVAILABLE,
			Campaign_Rules::ERROR_PRICE_MISSING      => array(
				'step'   => 'package',
				'target' => 'laao-ads-packages',
			),
			Campaign_Rules::ERROR_NO_CREATIVES,
			Campaign_Rules::ERROR_PLACEMENT_UNCOVERED,
			Campaign_Rules::ERROR_CREATIVE_KIND,
			Campaign_Rules::ERROR_CREATIVE_PLACEMENT,
			Campaign_Rules::ERROR_CREATIVE_SIZE,
			Campaign_Rules::ERROR_CLICK_URL_MISSING,
			Campaign_Rules::ERROR_CLICK_URL_INVALID => array(
				'step'   => 'creative',
				'target' => 'laao-ads-details-heading',
			),
			default => array(
				'step'   => 'details',
				'target' => 'laao-ads-details-heading',
			),
		};
	}
}
