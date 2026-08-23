<?php
/**
 * Organization invitations and access approval.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Notification\Organization_Notification;
use Aggressive\Ads\Notification\Password_Notification;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * Grants membership only after an owner decision or a valid emailed token.
 */
final class Organization_Membership {

	/**
	 * Constructor.
	 *
	 * @param Org_Access_Repository     $access       Access persistence.
	 * @param Org_Repository            $organizations Organization persistence.
	 * @param User_Repository           $users        User persistence.
	 * @param Password_Notification     $passwords    Account setup/approval mail.
	 * @param Organization_Notification $notifications Membership mail.
	 * @param Audit_Repository          $audit        Audit persistence.
	 */
	public function __construct(
		private readonly Org_Access_Repository $access,
		private readonly Org_Repository $organizations,
		private readonly User_Repository $users,
		private readonly Password_Notification $passwords,
		private readonly Organization_Notification $notifications,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Create a verified-email setup account awaiting owner approval.
	 *
	 * @param int    $org_id  Candidate organization.
	 * @param string $email   Normalized email.
	 * @param int    $user_id New subscriber id.
	 * @return true|WP_Error
	 */
	public function request_access( int $org_id, string $email, int $user_id ): bool|WP_Error {
		$user = $this->users->by_id( $user_id );

		if (
			null === $user
			|| strtolower( (string) $user->user_email ) !== $email
			|| ! $this->organizations->is_active( $org_id )
			|| array() !== $this->organizations->org_ids_for_user( $user_id )
		) {
			return new WP_Error( 'aggr_access_request_invalid' );
		}

		$request_id = $this->access->create_request( $org_id, $email, $user_id );

		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}

		if ( ! $this->passwords->send_pending_setup( $user_id ) ) {
			$this->access->delete_pending( $request_id );

			return new WP_Error( 'aggr_pending_setup_email_failed' );
		}

		$owner_ids = $this->organizations->user_ids_for_org( $org_id );
		$owner_id  = $owner_ids[0] ?? 0;
		$notified  = $owner_id > 0 && $this->notifications->send_request(
			$owner_id,
			$email,
			$this->organizations->name( $org_id )
		);

		$this->audit->insert(
			new Audit_Event(
				event: $notified ? 'organization.access_requested' : 'organization.access_request_notification_failed',
				outcome: $notified ? Audit_Event::OUTCOME_OK : Audit_Event::OUTCOME_FAILED,
				object_type: 'user',
				object_id: $user_id,
				org_id: $org_id,
				message: $notified ? 'Created a pending organization access request.' : 'Created access request but could not notify its owner.'
			)
		);

		return true;
	}

	/**
	 * Create and email an owner-issued invitation.
	 *
	 * @param int    $org_id   Organization id.
	 * @param string $email    Invited email.
	 * @param int    $actor_id Owner or staff user.
	 * @return true|WP_Error
	 */
	public function invite( int $org_id, string $email, int $actor_id ): bool|WP_Error {
		if ( ! $this->can_manage( $org_id, $actor_id ) || ! $this->organizations->is_active( $org_id ) ) {
			return new WP_Error(
				'aggr_org_access_denied',
				__( 'You cannot manage that organization.', 'aggressive-ads' )
			);
		}

		$email = strtolower( sanitize_email( $email ) );
		if ( '' === $email || strlen( $email ) > Advertiser_Registration::MAX_EMAIL || ! is_email( $email ) ) {
			return new WP_Error( 'aggr_invalid_invite_email', __( 'Enter a valid email address.', 'aggressive-ads' ) );
		}

		$existing = $this->users->by_email( $email );
		if ( null !== $existing ) {
			$existing_orgs = $this->organizations->org_ids_for_user( $existing->ID );

			if ( in_array( $org_id, $existing_orgs, true ) ) {
				return new WP_Error( 'aggr_already_org_member', __( 'That person already belongs to this organization.', 'aggressive-ads' ) );
			}

			if ( array() !== $existing_orgs ) {
				return new WP_Error( 'aggr_other_org_member', __( 'That account already belongs to another organization.', 'aggressive-ads' ) );
			}

			$pending = $this->access->pending_for_user( $existing->ID );
			if ( null !== $pending && $org_id === (int) $pending['org_id'] ) {
				return new WP_Error( 'aggr_org_access_exists', __( 'That person already has an access request waiting below.', 'aggressive-ads' ) );
			}
		}

		$invite = $this->access->create_invite( $org_id, $email, $actor_id );
		if ( is_wp_error( $invite ) ) {
			return $invite;
		}

		$sent = $this->notifications->send_invite(
			$email,
			$this->organizations->name( $org_id ),
			$invite['token'],
			$invite['expires_at_ts']
		);

		if ( ! $sent ) {
			$this->access->delete_pending( $invite['id'] );

			return new WP_Error( 'aggr_invite_email_failed', __( 'The invitation email could not be sent.', 'aggressive-ads' ) );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'organization.invited',
				object_type: 'organization',
				object_id: $org_id,
				org_id: $org_id,
				message: 'Sent an expiring organization invitation.',
				context: array( 'invitation_id' => $invite['id'] ),
				actor_user_id: $actor_id
			)
		);

		return true;
	}

	/**
	 * Consume an invitation for an existing WordPress account.
	 *
	 * @param string $token Bearer token.
	 * @param string $email Intended email.
	 * @param int    $user_id Existing user id.
	 * @return true|WP_Error
	 */
	public function accept_existing_invitation( string $token, string $email, int $user_id ): bool|WP_Error {
		return $this->accept_invitation( $token, $email, $user_id, false );
	}

	/**
	 * Consume an invitation for a newly created subscriber.
	 *
	 * @param string $token Bearer token.
	 * @param string $email Intended email.
	 * @param int    $user_id New user id.
	 * @return true|WP_Error
	 */
	public function accept_new_invitation( string $token, string $email, int $user_id ): bool|WP_Error {
		return $this->accept_invitation( $token, $email, $user_id, true );
	}

	/**
	 * Approve a duplicate-name access request.
	 *
	 * @param int $request_id Request row id.
	 * @param int $org_id     Organization id.
	 * @param int $actor_id   Owner/staff id.
	 * @return true|WP_Error
	 */
	public function approve( int $request_id, int $org_id, int $actor_id ): bool|WP_Error {
		if ( ! $this->can_manage( $org_id, $actor_id ) ) {
			return new WP_Error(
				'aggr_org_access_denied',
				__( 'You cannot manage that organization.', 'aggressive-ads' )
			);
		}

		$row = $this->access->pending( $request_id, $org_id, Org_Access_Repository::KIND_REQUEST );
		if ( null === $row || ! $this->access->claim( $request_id ) ) {
			return new WP_Error( 'aggr_access_request_unavailable' );
		}

		$user_id = (int) $row['request_user_id'];
		$user    = $this->users->by_id( $user_id );

		if ( null === $user || strtolower( (string) $user->user_email ) !== $row['email'] || ! $this->eligible_for_org( $user_id, $org_id ) ) {
			$this->access->release_claim( $request_id );

			return new WP_Error( 'aggr_access_request_invalid' );
		}

		$result = $this->grant_claimed( $request_id, $org_id, $user_id, $actor_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $this->passwords->send_access_approved( $user_id, $this->organizations->name( $org_id ) ) ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'organization.approval_email_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'user',
					object_id: $user_id,
					org_id: $org_id,
					message: 'Organization access was approved but account email failed.'
				)
			);
		}

		return true;
	}

	/**
	 * Remove an existing non-owner member from the organization.
	 *
	 * The owner meta key is never cleared here. Ownership transfer is a
	 * separate workflow; without it, removing the owner would leave a tenant
	 * nobody can administer.
	 *
	 * @param int $org_id   Organization id.
	 * @param int $user_id  Member being removed.
	 * @param int $actor_id Owner or staff user.
	 * @return true|WP_Error
	 */
	public function remove( int $org_id, int $user_id, int $actor_id ): bool|WP_Error {
		if ( ! $this->can_manage( $org_id, $actor_id ) ) {
			return new WP_Error(
				'aggr_org_access_denied',
				__( 'You cannot manage that organization.', 'aggressive-ads' )
			);
		}

		if ( $user_id <= 0 || ! in_array( $user_id, $this->organizations->user_ids_for_org( $org_id ), true ) ) {
			return new WP_Error( 'aggr_not_org_member', __( 'That person is not a member of this organization.', 'aggressive-ads' ) );
		}

		if ( $this->organizations->is_owner( $org_id, $user_id ) ) {
			return new WP_Error( 'aggr_cannot_remove_owner', __( 'The organization owner cannot be removed. Transfer ownership first.', 'aggressive-ads' ) );
		}

		$user = $this->users->by_id( $user_id );
		if ( null === $user ) {
			return new WP_Error( 'aggr_not_org_member', __( 'That person is not a member of this organization.', 'aggressive-ads' ) );
		}

		if ( ! $this->organizations->remove_member( $org_id, $user_id ) ) {
			return new WP_Error( 'aggr_membership_not_saved', __( 'The member could not be removed.', 'aggressive-ads' ) );
		}

		// One portal tenant per user today: with no remaining membership the
		// advertiser role is just a privilege that no longer authorizes anything.
		if ( array() === $this->organizations->org_ids_for_user( $user_id ) ) {
			$this->users->remove_advertiser_role( $user_id );
		}

		$email    = strtolower( (string) $user->user_email );
		$org_name = $this->organizations->name( $org_id );
		$notified = '' !== $email && $this->notifications->send_removed( $email, $org_name );

		$this->audit->insert(
			new Audit_Event(
				event: $notified ? 'organization.member_removed' : 'organization.member_removed_notification_failed',
				outcome: $notified ? Audit_Event::OUTCOME_OK : Audit_Event::OUTCOME_FAILED,
				object_type: 'user',
				object_id: $user_id,
				org_id: $org_id,
				message: $notified
					? 'Removed an organization member.'
					: 'Removed an organization member but could not notify them.',
				actor_user_id: $actor_id
			)
		);

		return true;
	}

	/**
	 * Deny a request or revoke an unconsumed invitation.
	 *
	 * @param int $row_id   Access row id.
	 * @param int $org_id   Organization id.
	 * @param int $actor_id Owner/staff id.
	 * @return true|WP_Error
	 */
	public function deny( int $row_id, int $org_id, int $actor_id ): bool|WP_Error {
		if ( ! $this->can_manage( $org_id, $actor_id ) ) {
			return new WP_Error(
				'aggr_org_access_denied',
				__( 'You cannot manage that organization.', 'aggressive-ads' )
			);
		}

		$row = $this->access->pending( $row_id, $org_id, Org_Access_Repository::KIND_REQUEST )
			?? $this->access->pending( $row_id, $org_id, Org_Access_Repository::KIND_INVITE );

		if ( null === $row || ! $this->access->claim( $row_id ) ) {
			return new WP_Error( 'aggr_access_request_unavailable' );
		}

		$status = Org_Access_Repository::KIND_INVITE === $row['kind']
			? Org_Access_Repository::STATUS_REVOKED
			: Org_Access_Repository::STATUS_DENIED;

		if ( ! $this->access->resolve( $row_id, $status, $actor_id ) ) {
			$this->access->release_claim( $row_id );

			return new WP_Error( 'aggr_access_request_not_saved' );
		}

		if ( Org_Access_Repository::KIND_REQUEST === $row['kind'] ) {
			$this->notifications->send_denied( (string) $row['email'] );
			$this->delete_denied_pending_user( (int) $row['request_user_id'] );
		}

		$this->audit->insert(
			new Audit_Event(
				event: Org_Access_Repository::KIND_INVITE === $row['kind'] ? 'organization.invitation_revoked' : 'organization.access_denied',
				outcome: Audit_Event::OUTCOME_DENIED,
				object_type: 'organization',
				object_id: $org_id,
				org_id: $org_id,
				message: Org_Access_Repository::KIND_INVITE === $row['kind'] ? 'Revoked an organization invitation.' : 'Denied an organization access request.',
				context: array( 'access_id' => $row_id ),
				actor_user_id: $actor_id
			)
		);

		return true;
	}

	/**
	 * Transfer organization ownership to an existing non-owner member.
	 *
	 * @param int $org_id       Organization id.
	 * @param int $new_owner_id Member who becomes owner.
	 * @param int $actor_id     Current owner or staff user.
	 * @return true|WP_Error
	 */
	public function transfer( int $org_id, int $new_owner_id, int $actor_id ): bool|WP_Error {
		if ( ! $this->can_manage( $org_id, $actor_id ) ) {
			return new WP_Error(
				'aggr_org_access_denied',
				__( 'You cannot manage that organization.', 'aggressive-ads' )
			);
		}

		$current_owner = 0;
		foreach ( $this->organizations->user_ids_for_org( $org_id ) as $user_id ) {
			if ( $this->organizations->is_owner( $org_id, $user_id ) ) {
				$current_owner = $user_id;
				break;
			}
		}

		if ( $current_owner <= 0 ) {
			return new WP_Error( 'aggr_ownership_unavailable', __( 'This organization has no owner to transfer from.', 'aggressive-ads' ) );
		}

		if ( $new_owner_id <= 0 || $new_owner_id === $current_owner ) {
			return new WP_Error( 'aggr_invalid_ownership_transfer', __( 'Choose a different member to become the owner.', 'aggressive-ads' ) );
		}

		if ( ! in_array( $new_owner_id, $this->organizations->user_ids_for_org( $org_id ), true ) ) {
			return new WP_Error( 'aggr_not_org_member', __( 'That person is not a member of this organization.', 'aggressive-ads' ) );
		}

		if ( $this->organizations->is_owner( $org_id, $new_owner_id ) ) {
			return new WP_Error( 'aggr_invalid_ownership_transfer', __( 'Choose a different member to become the owner.', 'aggressive-ads' ) );
		}

		$new_owner = $this->users->by_id( $new_owner_id );
		$old_owner = $this->users->by_id( $current_owner );
		if ( null === $new_owner || null === $old_owner ) {
			return new WP_Error( 'aggr_not_org_member', __( 'That person is not a member of this organization.', 'aggressive-ads' ) );
		}

		if ( ! $this->organizations->transfer_ownership( $org_id, $new_owner_id ) ) {
			return new WP_Error( 'aggr_ownership_not_saved', __( 'Ownership could not be transferred.', 'aggressive-ads' ) );
		}

		$org_name = $this->organizations->name( $org_id );
		$received = $this->notifications->send_ownership_received( strtolower( (string) $new_owner->user_email ), $org_name );
		$sent_old = $this->notifications->send_ownership_transferred( strtolower( (string) $old_owner->user_email ), $org_name );
		$notified = $received && $sent_old;

		$this->audit->insert(
			new Audit_Event(
				event: $notified ? 'organization.ownership_transferred' : 'organization.ownership_transfer_notification_failed',
				outcome: $notified ? Audit_Event::OUTCOME_OK : Audit_Event::OUTCOME_FAILED,
				object_type: 'user',
				object_id: $new_owner_id,
				org_id: $org_id,
				message: $notified
					? 'Transferred organization ownership.'
					: 'Transferred organization ownership but could not notify every party.',
				context: array( 'previous_owner_id' => $current_owner ),
				actor_user_id: $actor_id
			)
		);

		return true;
	}

	/**
	 * Rename the organization display name and its reserved canonical identity.
	 *
	 * @param int    $org_id   Organization id.
	 * @param string $name     Requested display name.
	 * @param int    $actor_id Owner or staff user.
	 * @return true|WP_Error
	 */
	public function rename( int $org_id, string $name, int $actor_id ): bool|WP_Error {
		if ( ! $this->can_manage( $org_id, $actor_id ) ) {
			return new WP_Error(
				'aggr_org_access_denied',
				__( 'You cannot manage that organization.', 'aggressive-ads' )
			);
		}

		$result = $this->organizations->rename( $org_id, $name );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'organization.renamed',
				object_type: 'organization',
				object_id: $org_id,
				org_id: $org_id,
				message: 'Renamed the organization.',
				actor_user_id: $actor_id
			)
		);

		return true;
	}

	/**
	 * Common invitation consumption.
	 *
	 * @param string $token      Raw invitation token.
	 * @param string $email      Intended recipient address.
	 * @param int    $user_id    Accepting user id.
	 * @param bool   $send_setup Whether setup mail must succeed before commit.
	 * @return true|WP_Error
	 */
	private function accept_invitation( string $token, string $email, int $user_id, bool $send_setup ): bool|WP_Error {
		$row = $this->access->invitation( $token, $email );
		if ( null === $row || ! $this->eligible_for_org( $user_id, (int) $row['org_id'] ) || ! $this->access->claim( (int) $row['id'] ) ) {
			return new WP_Error( 'aggr_invalid_invitation', __( 'This invitation is invalid or has expired.', 'aggressive-ads' ) );
		}

		$org_id         = (int) $row['org_id'];
		$before_resolve = $send_setup
			? fn (): bool => $this->passwords->send_setup( $user_id )
			: null;
		$result         = $this->grant_claimed( (int) $row['id'], $org_id, $user_id, 0, $before_resolve );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $send_setup && ! $this->passwords->send_access_approved( $user_id, $this->organizations->name( $org_id ) ) ) {
			// Access remains valid; mail can be recovered through the portal's
			// password-reset flow and must never undo an accepted invitation.
			$this->audit->insert(
				new Audit_Event(
					event: 'organization.acceptance_email_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'user',
					object_id: $user_id,
					org_id: $org_id,
					message: 'Organization invitation was accepted but account email failed.'
				)
			);
		}

		return true;
	}

	/**
	 * Apply membership and finish a claimed access row with compensation.
	 *
	 * @param int           $row_id         Claimed access row id.
	 * @param int           $org_id         Organization id.
	 * @param int           $user_id        User receiving access.
	 * @param int           $actor_id       Resolving owner/staff id, or zero for token acceptance.
	 * @param callable|null $before_resolve Required side effect before the row commits.
	 * @return true|WP_Error
	 */
	private function grant_claimed( int $row_id, int $org_id, int $user_id, int $actor_id, ?callable $before_resolve = null ): bool|WP_Error {
		$had_membership = in_array( $user_id, $this->organizations->user_ids_for_org( $org_id ), true );
		$had_role       = $this->users->has_advertiser_role( $user_id );

		if ( ! $this->organizations->add_member( $org_id, $user_id ) || ! $this->users->add_advertiser_role( $user_id ) ) {
			$this->compensate_grant( $org_id, $user_id, $had_membership, $had_role );
			$this->access->release_claim( $row_id );

			return new WP_Error( 'aggr_membership_not_saved' );
		}

		if ( null !== $before_resolve && ! $before_resolve() ) {
			$this->compensate_grant( $org_id, $user_id, $had_membership, $had_role );
			$this->access->release_claim( $row_id );

			return new WP_Error( 'aggr_membership_email_failed' );
		}

		if ( ! $this->access->resolve( $row_id, Org_Access_Repository::STATUS_ACCEPTED, $actor_id ) ) {
			$this->compensate_grant( $org_id, $user_id, $had_membership, $had_role );
			$this->access->release_claim( $row_id );

			return new WP_Error( 'aggr_membership_not_saved' );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'organization.access_approved',
				object_type: 'user',
				object_id: $user_id,
				org_id: $org_id,
				message: 'Approved organization membership.',
				context: array( 'access_id' => $row_id ),
				actor_user_id: $actor_id
			)
		);

		return true;
	}

	/**
	 * Undo only membership state introduced by the current claimed workflow.
	 *
	 * @param int  $org_id         Organization id.
	 * @param int  $user_id        User id.
	 * @param bool $had_membership Whether membership predated the claim.
	 * @param bool $had_role       Whether the advertiser role predated it.
	 */
	private function compensate_grant( int $org_id, int $user_id, bool $had_membership, bool $had_role ): void {
		if ( ! $had_membership ) {
			$this->organizations->remove_member( $org_id, $user_id );
		}

		if ( ! $had_role ) {
			$this->users->remove_advertiser_role( $user_id );
		}
	}

	/**
	 * Whether the actor owns the org or carries the staff primitive.
	 *
	 * @param int $org_id   Organization id.
	 * @param int $actor_id Actor user id.
	 */
	private function can_manage( int $org_id, int $actor_id ): bool {
		return $this->organizations->is_owner( $org_id, $actor_id )
			|| ( $actor_id > 0 && user_can( $actor_id, Capabilities::MANAGE_ORGS ) );
	}

	/**
	 * A portal account currently acts for at most one organization.
	 *
	 * @param int $user_id User id.
	 * @param int $org_id  Candidate organization id.
	 */
	private function eligible_for_org( int $user_id, int $org_id ): bool {
		$memberships = $this->organizations->org_ids_for_user( $user_id );

		return $this->organizations->is_active( $org_id )
			&& ( array() === $memberships || array( $org_id ) === $memberships );
	}

	/**
	 * Remove a denied registration user only when it owns nothing else.
	 *
	 * @param int $user_id Pending subscriber id.
	 */
	private function delete_denied_pending_user( int $user_id ): void {
		$user = $this->users->by_id( $user_id );

		if ( null === $user || array() !== $this->organizations->org_ids_for_user( $user_id ) ) {
			return;
		}

		$roles = array_values( array_diff( $user->roles, array( 'subscriber' ) ) );
		if ( array() === $roles ) {
			$this->users->delete_registration_account( $user_id );
		}
	}
}
