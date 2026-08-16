<?php
/**
 * Staff decisions on advertiser-proposed campaign changes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use WP_Error;

/**
 * The review half of the running-campaign change workflow.
 *
 * No longer a Service, and no longer hooked. The review screen moved to REST
 * and its forms went with it, so the admin-post handlers this class carried
 * would have been write paths with nothing pointing at them. What survives is
 * the pair of decision edges, which `REST\Review_Controller` calls — so there
 * is one implementation of "approve or reject" rather than one per delivery
 * path.
 */
final class Campaign_Change_Actions {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Change_Manager $manager Change workflow.
	 */
	public function __construct( private readonly Campaign_Change_Manager $manager ) {
	}

	/**
	 * Decides one set of proposed campaign changes.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $decision    approve or reject.
	 * @param string $notes       Rejection feedback.
	 * @return true|WP_Error
	 */
	public function process( int $campaign_id, string $decision, string $notes = '' ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to review campaign changes.', 'aggressive-ads' ) );
		}

		return match ( $decision ) {
			'approve' => $this->manager->approve( $campaign_id ),
			'reject'  => $this->manager->reject( $campaign_id, $notes ),
			default   => new WP_Error( 'aggr_change_decision_invalid', __( 'That campaign-change decision is not valid.', 'aggressive-ads' ) ),
		};
	}

	/**
	 * Closes an advertiser's request, with an explanation they will read.
	 *
	 * No capability check of its own, deliberately, unlike `process()` above.
	 * `resolve_action()` carries one and records the denial in the audit trail;
	 * a second gate here would hide whether the first still works.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Advertiser-facing explanation.
	 * @return true|WP_Error
	 */
	public function decline( int $campaign_id, string $notes = '' ): bool|WP_Error {
		return $this->manager->resolve_action( $campaign_id, $notes );
	}
}
