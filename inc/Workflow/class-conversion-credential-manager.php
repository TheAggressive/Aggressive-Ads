<?php
/**
 * Issuing, revoking and verifying server-to-server credentials.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Domain\Conversion_Credential;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Conversion_Credential_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * The credential's whole lifecycle, and the only place it is verified.
 *
 * The capability check repeats the controller's `permission_callback` for the
 * reason `Conversion_Definition_Manager` repeats it: a route is one caller, and
 * a workflow that trusts having been reached grants whatever the next caller
 * forgets to check. Here it matters more than usual — what this issues is a
 * bearer secret that reports into somebody's spend.
 */
final class Conversion_Credential_Manager {

	/**
	 * Constructor.
	 *
	 * @param Conversion_Credential_Repository $credentials Credential persistence.
	 * @param Org_Repository                   $orgs        Organization existence.
	 * @param Audit_Repository                 $audit       Audit persistence.
	 */
	public function __construct(
		private readonly Conversion_Credential_Repository $credentials,
		private readonly Org_Repository $orgs,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Issues one credential, returning the plaintext exactly once.
	 *
	 * @param int    $org_id Organization the credential may report for.
	 * @param string $label  Staff-facing name.
	 * @return array{id: int, token: string}|WP_Error
	 */
	public function issue( int $org_id, string $label ): array|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			$this->record( 0, $org_id, Audit_Event::OUTCOME_DENIED, 'Conversion credential issue denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage conversion credentials.', 'aggressive-ads' ),
				array( 'status' => 403 )
			);
		}

		$label = trim( $label );

		if ( ! Conversion_Credential::is_valid_label( $label ) ) {
			return new WP_Error(
				'aggr_credential_label_invalid',
				__( 'Give this credential a short name so it can be told apart later.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		/*
		 * Organization 0 is the publisher's own, and a credential may not be
		 * scoped to it. `Conversion_Attribution::decide()` lets an org-0
		 * definition accept a conversion from any campaign, because the visitor
		 * there is anonymous — issuing a credential with that scope would hand
		 * one integration the ability to report against every advertiser on the
		 * site.
		 */
		if ( $org_id <= 0 || '' === $this->orgs->name( $org_id ) ) {
			return new WP_Error(
				'aggr_credential_org_invalid',
				__( 'Choose the organization this credential reports for.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		if ( $this->credentials->live_count() >= Conversion_Credential_Repository::MAX_LIVE_CREDENTIALS ) {
			return new WP_Error(
				'aggr_credential_limit_reached',
				__( 'Revoke a credential before issuing another.', 'aggressive-ads' ),
				array( 'status' => 409 )
			);
		}

		$issued = $this->credentials->issue( $org_id, $label, get_current_user_id() );

		if ( null === $issued ) {
			$this->record( 0, $org_id, Audit_Event::OUTCOME_FAILED, 'Conversion credential issue failed.' );

			return new WP_Error(
				'aggr_credential_not_issued',
				__( 'The credential could not be created.', 'aggressive-ads' ),
				array( 'status' => 500 )
			);
		}

		/*
		 * The audit row records that a credential was issued, its id, its scope
		 * and who issued it — and none of the secret. An audit trail readable by
		 * `aggr_view_audit_log` that carried the token would hand it to a
		 * capability that was never meant to report conversions.
		 */
		$this->record( $issued['id'], $org_id, Audit_Event::OUTCOME_OK, 'Conversion credential issued.' );

		return $issued;
	}

	/**
	 * Revokes one credential.
	 *
	 * @param int $credential_id Credential row id.
	 * @return true|WP_Error
	 */
	public function revoke( int $credential_id ): true|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			$this->record( $credential_id, 0, Audit_Event::OUTCOME_DENIED, 'Conversion credential revoke denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage conversion credentials.', 'aggressive-ads' ),
				array( 'status' => 403 )
			);
		}

		$existing = $this->credentials->find( $credential_id );

		if ( null === $existing ) {
			return new WP_Error(
				'aggr_credential_not_found',
				__( 'That credential no longer exists.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		/*
		 * Revoking an already-revoked credential is success, not a conflict.
		 * The operator's intent — "this secret must not work" — is satisfied,
		 * and answering 409 during an incident invites somebody to go looking
		 * for a second problem that does not exist.
		 */
		if ( ! Conversion_Credential::is_live( $existing['revoked_at_ts'] ) ) {
			return true;
		}

		if ( ! $this->credentials->revoke( $credential_id ) ) {
			$this->record( $credential_id, $existing['org_id'], Audit_Event::OUTCOME_FAILED, 'Conversion credential revoke failed.' );

			return new WP_Error(
				'aggr_credential_not_revoked',
				__( 'The credential could not be revoked.', 'aggressive-ads' ),
				array( 'status' => 500 )
			);
		}

		$this->record( $credential_id, $existing['org_id'], Audit_Event::OUTCOME_OK, 'Conversion credential revoked.' );

		return true;
	}

	/**
	 * Verifies a presented secret and returns the credential it belongs to.
	 *
	 * Public, unauthenticated input, so this says nothing about *why* it failed:
	 * an unknown secret and a revoked one both come back null. The audit row
	 * below is what tells an operator the difference, and it is deliberately
	 * only written for the revoked case — an unknown secret on a public endpoint
	 * is something anybody can cause, and auditing it would be an unbounded
	 * write an attacker chooses.
	 *
	 * @param string $token Presented plaintext.
	 * @return array{id: int, org_id: int, label: string}|null
	 */
	public function authenticate( string $token ): ?array {
		$row = $this->credentials->find_by_token( $token );

		if ( null === $row ) {
			return null;
		}

		if ( ! Conversion_Credential::is_live( $row['revoked_at_ts'] ) ) {
			/*
			 * A revoked secret still being presented is the signal an operator
			 * most wants after revoking one: it says the integration has not
			 * been updated, or that the leak is still being used. Bounded,
			 * because reaching it requires holding a secret this site really
			 * issued.
			 */
			$this->record(
				$row['id'],
				$row['org_id'],
				Audit_Event::OUTCOME_DENIED,
				'Revoked conversion credential was presented.'
			);

			return null;
		}

		$this->credentials->touch( $row['id'] );

		return array(
			'id'     => $row['id'],
			'org_id' => $row['org_id'],
			'label'  => $row['label'],
		);
	}

	/**
	 * Records one credential decision.
	 *
	 * @param int    $credential_id Credential row id, or 0 when there is none yet.
	 * @param int    $org_id        Scope.
	 * @param string $outcome       Audit outcome.
	 * @param string $message       What happened.
	 */
	private function record( int $credential_id, int $org_id, string $outcome, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'conversion_credential.decision',
				outcome: $outcome,
				object_type: 'conversion_credential',
				object_id: $credential_id,
				org_id: $org_id,
				message: $message,
				actor_user_id: get_current_user_id()
			)
		);
	}
}
