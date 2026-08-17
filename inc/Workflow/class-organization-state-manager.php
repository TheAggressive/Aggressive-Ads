<?php
/**
 * Authorized organization active/suspended state writes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;
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
	 * Applies one explicit lifecycle state.
	 *
	 * Public because the REST route needs the state vocabulary to live in one
	 * place. suspend() and reactivate() are the internal spellings of the same
	 * call, and a caller that already holds the state as a string should not
	 * have to translate it back into a verb — that translation is what would
	 * end up duplicated in every screen and route that grows a third state.
	 *
	 * Being callable from outside is why the allowlist below exists. The private
	 * version could trust its two callers; this one cannot, and an unrecognised
	 * state must not reach the meta write, because Org_Repository stores what it
	 * is given and every later read compares against the two known constants.
	 *
	 * @param int    $org_id Organization id.
	 * @param string $state  Active or suspended.
	 * @return true|WP_Error
	 */
	public function set_state( int $org_id, string $state ): bool|WP_Error {
		if ( ! in_array( $state, array( Org_Repository::STATE_ACTIVE, Org_Repository::STATE_SUSPENDED ), true ) ) {
			return new WP_Error(
				'aggr_invalid_org_state',
				__( 'That is not an organization state.', 'aggressive-ads' )
			);
		}

		if ( ! current_user_can( Capabilities::MANAGE_ORGS ) ) {
			$this->record( $org_id, Audit_Event::OUTCOME_DENIED, 'Organization state change denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage organizations.', 'aggressive-ads' )
			);
		}

		if ( ! $this->organizations->exists( $org_id ) ) {
			return new WP_Error(
				'aggr_org_not_found',
				__( 'That organization could not be found.', 'aggressive-ads' )
			);
		}

		$previous = $this->organizations->state( $org_id );
		if ( $previous === $state ) {
			return true;
		}

		if ( ! $this->organizations->set_state( $org_id, $state ) ) {
			$this->record( $org_id, Audit_Event::OUTCOME_FAILED, 'Organization state write failed.' );

			return new WP_Error(
				'aggr_org_state_not_saved',
				__( 'The organization state could not be saved.', 'aggressive-ads' )
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
