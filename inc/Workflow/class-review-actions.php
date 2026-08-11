<?php
/**
 * Staff-only review writes that are not campaign status persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use WP_Error;

/**
 * Coordinates review actions without giving the delivery layer write access.
 */
final class Review_Actions {

	/**
	 * Constructor.
	 *
	 * @param Campaign_State_Machine $machine   Campaign lifecycle.
	 * @param Campaign_Repository    $campaigns Campaign persistence.
	 * @param Audit_Repository       $audit     Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_State_Machine $machine,
		private readonly Campaign_Repository $campaigns,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Applies a staff-requested lifecycle transition.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $to          Target status.
	 * @param string $notes       Advertiser-visible feedback.
	 * @return true|WP_Error
	 */
	public function transition( int $campaign_id, string $to, string $notes = '' ) {
		$context = '' === trim( $notes ) ? array() : array( 'review_notes' => trim( $notes ) );

		return $this->machine->apply( $campaign_id, $to, $context );
	}

	/**
	 * Saves staff-only notes and records the write.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Staff-only notes.
	 * @return true|WP_Error
	 */
	public function save_internal_notes( int $campaign_id, string $notes ) {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) || ! current_user_can( 'edit_laao_ads_campaign', $campaign_id ) ) {
			return new WP_Error(
				'laao_ads_forbidden',
				__( 'You do not have permission to do that.', 'laao-advertiser-portal' )
			);
		}

		if ( ! $this->campaigns->exists( $campaign_id ) ) {
			return new WP_Error(
				'laao_ads_campaign_not_found',
				__( 'Campaign not found.', 'laao-advertiser-portal' )
			);
		}

		$clean = trim( $notes );

		$this->campaigns->set_internal_notes( $campaign_id, $clean );
		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.internal_notes_updated',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: '' === $clean ? 'Internal review notes cleared.' : 'Internal review notes updated.',
				actor_user_id: get_current_user_id()
			)
		);

		return true;
	}
}
