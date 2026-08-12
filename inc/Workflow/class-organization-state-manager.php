<?php
/**
 * Authorized organization active/suspended state writes.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use WP_Error;

/**
 * Suspends or reactivates an organization after an explicit staff decision.
 */
final class Organization_State_Manager {

	/**
	 * Constructor.
	 *
	 * @param Org_Repository   $organizations Organization persistence.
	 * @param Audit_Repository $audit         Audit persistence.
	 */
	public function __construct(
		private readonly Org_Repository $organizations,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Suspend an organization so it cannot submit or grow membership.
	 *
	 * @param int $org_id Organization id.
	 * @return true|WP_Error
	 */
	public function suspend( int $org_id ): bool|WP_Error {
		return $this->set_state( $org_id, Org_Repository::STATE_SUSPENDED );
	}

	/**
	 * Restore an organization to the active transactional state.
	 *
	 * @param int $org_id Organization id.
	 * @return true|WP_Error
	 */
	public function reactivate( int $org_id ): bool|WP_Error {
		return $this->set_state( $org_id, Org_Repository::STATE_ACTIVE );
	}

	/**
	 * Apply one explicit lifecycle state.
	 *
	 * @param int    $org_id Organization id.
	 * @param string $state  Active or suspended.
	 * @return true|WP_Error
	 */
	private function set_state( int $org_id, string $state ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_ORGS ) ) {
			$this->record( $org_id, Audit_Event::OUTCOME_DENIED, 'Organization state change denied.' );

			return new WP_Error(
				'laao_ads_forbidden',
				__( 'You do not have permission to manage organizations.', 'laao-advertiser-portal' )
			);
		}

		if ( ! $this->organizations->exists( $org_id ) ) {
			return new WP_Error(
				'laao_ads_org_not_found',
				__( 'That organization could not be found.', 'laao-advertiser-portal' )
			);
		}

		$previous = $this->organizations->state( $org_id );
		if ( $previous === $state ) {
			return true;
		}

		if ( ! $this->organizations->set_state( $org_id, $state ) ) {
			$this->record( $org_id, Audit_Event::OUTCOME_FAILED, 'Organization state write failed.' );

			return new WP_Error(
				'laao_ads_org_state_not_saved',
				__( 'The organization state could not be saved.', 'laao-advertiser-portal' )
			);
		}

		$suspended = Org_Repository::STATE_SUSPENDED === $state;

		$this->audit->insert(
			new Audit_Event(
				event: $suspended ? 'organization.suspended' : 'organization.reactivated',
				object_type: 'organization',
				object_id: $org_id,
				org_id: $org_id,
				message: $suspended ? 'Suspended the organization.' : 'Reactivated the organization.',
				context: array( 'previous_state' => $previous ),
				actor_user_id: get_current_user_id()
			)
		);

		return true;
	}

	/**
	 * Records a denied or failed change without request payloads.
	 *
	 * @param int    $org_id  Organization post id.
	 * @param string $outcome Audit outcome.
	 * @param string $message Fixed summary.
	 */
	private function record( int $org_id, string $outcome, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'organization.state_change_failed',
				outcome: $outcome,
				object_type: 'organization',
				object_id: max( 0, $org_id ),
				org_id: max( 0, $org_id ),
				message: $message,
				actor_user_id: get_current_user_id()
			)
		);
	}
}
