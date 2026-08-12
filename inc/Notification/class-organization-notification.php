<?php
/**
 * Organization invitation and access-request email.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Notification;

use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;
use LAAO_Advertiser_Portal\Repository\User_Repository;

/**
 * Sends individualized organization membership messages.
 */
final class Organization_Notification {

	/**
	 * Constructor.
	 *
	 * @param User_Repository $users User lookup.
	 */
	public function __construct( private readonly User_Repository $users ) {
	}

	/**
	 * Send an invitation bearer token only to its intended address.
	 *
	 * @param string $email      Invited email.
	 * @param string $org_name   Organization name.
	 * @param string $token      Raw single-use invitation token.
	 * @param int    $expires_at Expiry timestamp.
	 */
	public function send_invite( string $email, string $org_name, string $token, int $expires_at ): bool {
		$url = add_query_arg( 'invite', $token, Routes::url( Request::ROUTE_SIGNUP ) );

		$subject = sprintf(
			/* translators: %s: organization name. */
			__( 'Invitation to join %s', 'laao-advertiser-portal' ),
			$org_name
		);
		$body = sprintf(
			/* translators: 1: organization name. 2: invitation URL. 3: expiration date. */
			__( "You were invited to join %1\$s in the advertiser portal. Complete the invitation using this single-use link:\n\n%2\$s\n\nThis invitation expires %3\$s. If you were not expecting it, ignore this message.", 'laao-advertiser-portal' ),
			$org_name,
			$url,
			wp_date( 'F j, Y g:i a T', $expires_at )
		);

		return $this->send_email( $email, $subject, $body );
	}

	/**
	 * Tell organization owners that a duplicate-name request needs review.
	 *
	 * @param int    $owner_id Owner user id.
	 * @param string $email    Requesting address.
	 * @param string $org_name Organization name.
	 */
	public function send_request( int $owner_id, string $email, string $org_name ): bool {
		$owner = $this->users->by_id( $owner_id );

		if ( null === $owner ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: organization name. */
			__( 'Access request for %s', 'laao-advertiser-portal' ),
			$org_name
		);
		$body = sprintf(
			/* translators: 1: requesting email. 2: organization name. 3: organization screen URL. */
			__( "%1\$s entered a name similar to %2\$s while signing up. No access was granted. Review and approve or deny the request here:\n\n%3\$s", 'laao-advertiser-portal' ),
			$email,
			$org_name,
			Routes::url( Request::ROUTE_ORGANIZATION )
		);

		return $this->send_email( (string) $owner->user_email, $subject, $body );
	}

	/**
	 * Tell a denied requester without exposing internal organization data.
	 *
	 * @param string $email Requesting email.
	 */
	public function send_denied( string $email ): bool {
		return $this->send_email(
			$email,
			__( 'Advertiser organization request update', 'laao-advertiser-portal' ),
			__( 'Your request to join an existing advertiser organization was not approved. Contact the organization directly if you believe this was a mistake.', 'laao-advertiser-portal' )
		);
	}

	/**
	 * Send one plain-text transactional message.
	 *
	 * @param string $email   Recipient address.
	 * @param string $subject Message subject.
	 * @param string $body    Plain-text body.
	 */
	private function send_email( string $email, string $subject, string $body ): bool {
		if ( ! is_email( $email ) || '' === $subject || '' === $body ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional single-recipient membership notification.
		return wp_mail( $email, $subject, $body );
	}
}
