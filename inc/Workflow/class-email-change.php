<?php
/**
 * Portal-owned email change challenges.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Notification\Email_Change_Notification;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;
use WP_User;

/**
 * Issues, confirms and cancels self-service email changes without profile.php.
 */
final class Email_Change {

	public const TTL = 3 * DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param User_Repository           $users         User persistence.
	 * @param Email_Change_Notification $notifications Confirmation mail.
	 * @param Audit_Repository          $audit         Audit persistence.
	 */
	public function __construct(
		private readonly User_Repository $users,
		private readonly Email_Change_Notification $notifications,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Start a change for the authenticated portal user.
	 *
	 * Taken addresses receive the same opaque success outcome as a send so the
	 * request surface cannot enumerate accounts. Invalid shapes are refused
	 * with a fixed validation error.
	 *
	 * @param int    $user_id   Acting user.
	 * @param string $new_email Requested destination.
	 * @return true|WP_Error
	 */
	public function request( int $user_id, string $new_email ): bool|WP_Error {
		$user = $this->authorized_user( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$email = strtolower( sanitize_email( $new_email ) );
		if ( '' === $email || strlen( $email ) > Advertiser_Registration::MAX_EMAIL || ! is_email( $email ) ) {
			return new WP_Error( 'aggr_invalid_email', __( 'Enter a valid email address.', 'aggressive-ads' ) );
		}

		if ( strtolower( (string) $user->user_email ) === $email ) {
			return new WP_Error( 'aggr_email_unchanged', __( 'That is already your email address.', 'aggressive-ads' ) );
		}

		if ( $this->users->email_taken_by_other( $email, $user_id ) ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'portal.email_change_suppressed',
					outcome: Audit_Event::OUTCOME_DENIED,
					object_type: 'user',
					object_id: $user_id,
					message: 'Email change request suppressed because the address is unavailable.',
					actor_user_id: $user_id
				)
			);

			return true;
		}

		$token   = $this->token();
		$expires = time() + self::TTL;
		$stored  = $this->users->store_email_change(
			$user_id,
			array(
				'token_hash' => $this->digest( $token ),
				'new_email'  => $email,
				'expires_at' => $expires,
			)
		);

		if ( ! $stored ) {
			return new WP_Error( 'aggr_email_change_not_saved', __( 'The email change could not be started.', 'aggressive-ads' ) );
		}

		if ( ! $this->notifications->send_confirmation( $email, (string) $user->user_login, $token, $expires ) ) {
			$this->users->clear_email_change( $user_id );
			$this->audit->insert(
				new Audit_Event(
					event: 'portal.email_change_mail_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'user',
					object_id: $user_id,
					message: 'Email change confirmation mail could not be sent.',
					actor_user_id: $user_id
				)
			);

			return new WP_Error( 'aggr_email_change_mail_failed', __( 'The confirmation email could not be sent.', 'aggressive-ads' ) );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'portal.email_change_requested',
				object_type: 'user',
				object_id: $user_id,
				message: 'Issued a portal email-change confirmation.',
				actor_user_id: $user_id
			)
		);

		return true;
	}

	/**
	 * Drop an outstanding challenge without changing the address.
	 *
	 * @param int $user_id Acting user.
	 * @return true|WP_Error
	 */
	public function cancel( int $user_id ): bool|WP_Error {
		$user = $this->authorized_user( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$this->users->clear_email_change( $user_id );
		$this->audit->insert(
			new Audit_Event(
				event: 'portal.email_change_cancelled',
				object_type: 'user',
				object_id: $user_id,
				message: 'Cancelled a pending email change.',
				actor_user_id: $user_id
			)
		);

		return true;
	}

	/**
	 * Complete a change for the signed-in user who owns the challenge.
	 *
	 * @param int    $user_id Acting user.
	 * @param string $login   Login from the confirmation link.
	 * @param string $token   Raw bearer token.
	 * @return true|WP_Error
	 */
	public function confirm( int $user_id, string $login, string $token ): bool|WP_Error {
		$user = $this->authorized_user( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( (string) $user->user_login !== $login ) {
			return new WP_Error( 'aggr_invalid_email_change', __( 'This confirmation link is invalid or has expired.', 'aggressive-ads' ) );
		}

		$pending = $this->users->email_change( $user_id );
		if ( null === $pending || $pending['expires_at'] < time() || ! hash_equals( $pending['token_hash'], $this->digest( $token ) ) ) {
			$this->users->clear_email_change( $user_id );

			return new WP_Error( 'aggr_invalid_email_change', __( 'This confirmation link is invalid or has expired.', 'aggressive-ads' ) );
		}

		$email = $pending['new_email'];
		if ( $this->users->email_taken_by_other( $email, $user_id ) ) {
			$this->users->clear_email_change( $user_id );

			return new WP_Error( 'aggr_email_taken', __( 'That email address is no longer available.', 'aggressive-ads' ) );
		}

		// Consume before the write so a replay cannot complete twice if mail or
		// hooks re-enter, then restore only when the write itself fails.
		$this->users->clear_email_change( $user_id );

		$result = $this->users->update_email( $user_id, $email );
		if ( is_wp_error( $result ) ) {
			$this->users->store_email_change( $user_id, $pending );

			return new WP_Error( 'aggr_email_not_saved', __( 'Your email address could not be updated.', 'aggressive-ads' ) );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'portal.email_change_confirmed',
				object_type: 'user',
				object_id: $user_id,
				message: 'Confirmed a portal email change.',
				actor_user_id: $user_id
			)
		);

		return true;
	}

	/**
	 * Pending destination for the account screen, when one exists and is fresh.
	 *
	 * @param int $user_id User id.
	 */
	public function pending_email( int $user_id ): string {
		$pending = $this->users->email_change( $user_id );
		if ( null === $pending || $pending['expires_at'] < time() ) {
			return '';
		}

		return $pending['new_email'];
	}

	/**
	 * Whether a raw token still matches the user's pending challenge.
	 *
	 * @param int    $user_id User id.
	 * @param string $token  Raw token.
	 */
	public function token_matches( int $user_id, string $token ): bool {
		$pending = $this->users->email_change( $user_id );

		return null !== $pending
			&& $pending['expires_at'] >= time()
			&& hash_equals( $pending['token_hash'], $this->digest( $token ) );
	}

	/**
	 * Require a portal-capable account.
	 *
	 * @param int $user_id User id.
	 * @return WP_User|WP_Error
	 */
	private function authorized_user( int $user_id ): WP_User|WP_Error {
		$user = $this->users->by_id( $user_id );
		if ( null === $user || ! user_can( $user_id, Capabilities::ACCESS_PORTAL ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to do that.', 'aggressive-ads' ) );
		}

		return $user;
	}

	/** 256-bit URL-safe bearer token. */
	private function token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Salted one-way digest of a raw token.
	 *
	 * @param string $token Raw token.
	 */
	private function digest( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}
