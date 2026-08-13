<?php
/**
 * Portal password setup and recovery messages.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Notification;

use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Repository\User_Repository;
use WP_User;

/**
 * Issues core reset keys but keeps every customer-facing URL in the portal.
 */
final class Password_Notification {

	/**
	 * Constructor.
	 *
	 * @param User_Repository $users User persistence.
	 */
	public function __construct( private readonly User_Repository $users ) {
	}

	/**
	 * Sends the initial account-setup message.
	 *
	 * @param int $user_id New user id.
	 * @return bool
	 */
	public function send_setup( int $user_id ): bool {
		$user = $this->users->by_id( $user_id );

		if ( null === $user ) {
			return false;
		}

		$site_name = $this->site_name();
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Set up your advertiser account', 'aggressive-ads' ),
			$site_name
		);
		$body = sprintf(
			/* translators: 1: first name. 2: site name. 3: one-time password setup URL. 4: portal sign-in URL. */
			__( "Hello %1\$s,\n\nYour advertiser account for %2\$s is ready. Use this one-time link to choose a password:\n\n%3\$s\n\nAfter setting it, sign in with this email address at:\n%4\$s\n\nIf you did not request this account, you can ignore this message.", 'aggressive-ads' ),
			$user->first_name,
			$site_name,
			$this->issue_url( $user ),
			Routes::url( Request::ROUTE_LOGIN )
		);

		return $this->send( $user, $subject, $body );
	}

	/**
	 * Sends setup while organization access waits for owner approval.
	 *
	 * @param int $user_id Pending user id.
	 */
	public function send_pending_setup( int $user_id ): bool {
		$user = $this->users->by_id( $user_id );

		if ( null === $user ) {
			return false;
		}

		$site_name = $this->site_name();
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Confirm your advertiser request', 'aggressive-ads' ),
			$site_name
		);
		$body = sprintf(
			/* translators: 1: first name. 2: site name. 3: one-time password setup URL. */
			__( "Hello %1\$s,\n\nWe received your advertiser access request for %2\$s. Use this one-time link to choose a password:\n\n%3\$s\n\nAn organization owner must approve the request before the portal becomes available. We will email you when that happens. If you did not make this request, you can ignore this message.", 'aggressive-ads' ),
			$user->first_name,
			$site_name,
			$this->issue_url( $user )
		);

		return $this->send( $user, $subject, $body );
	}

	/**
	 * Sends approval without invalidating an already-issued setup key.
	 *
	 * @param int    $user_id  Approved user id.
	 * @param string $org_name Organization display name.
	 */
	public function send_access_approved( int $user_id, string $org_name ): bool {
		$user = $this->users->by_id( $user_id );

		if ( null === $user ) {
			return false;
		}

		$site_name = $this->site_name();
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Your organization access was approved', 'aggressive-ads' ),
			$site_name
		);
		$body = sprintf(
			/* translators: 1: first name. 2: organization name. 3: site name. 4: portal password-recovery URL. 5: portal sign-in URL. */
			__( "Hello %1\$s,\n\nYour access to %2\$s in the %3\$s advertiser portal was approved. Sign in at:\n\n%5\$s\n\nIf you still need to choose or reset your password, request a link here:\n%4\$s", 'aggressive-ads' ),
			$user->first_name,
			$org_name,
			$site_name,
			Routes::url( Request::ROUTE_FORGOT_PASSWORD ),
			Routes::url( Request::ROUTE_LOGIN )
		);

		if (
			! str_contains( $body, Routes::url( Request::ROUTE_FORGOT_PASSWORD ) )
			|| ! str_contains( $body, Routes::url( Request::ROUTE_LOGIN ) )
		) {
			return false;
		}

		return $this->deliver( $user, $subject, $body );
	}

	/**
	 * Sends a password-recovery message for an existing portal account.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function send_reset( int $user_id ): bool {
		$user = $this->users->by_id( $user_id );

		if ( null === $user ) {
			return false;
		}

		$site_name = $this->site_name();
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Reset your advertiser password', 'aggressive-ads' ),
			$site_name
		);
		$body = sprintf(
			/* translators: 1: first name. 2: site name. 3: one-time password reset URL. */
			__( "Hello %1\$s,\n\nA password reset was requested for your %2\$s advertiser account. Use this one-time link to choose a new password:\n\n%3\$s\n\nIf you did not request this change, you can ignore this message.", 'aggressive-ads' ),
			$user->first_name,
			$site_name,
			$this->issue_url( $user )
		);

		return $this->send( $user, $subject, $body );
	}

	/**
	 * Issues a core key and wraps it in the portal-owned route.
	 *
	 * @param WP_User $user User receiving the link.
	 * @return string Empty when key generation fails.
	 */
	private function issue_url( WP_User $user ): string {
		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'key'   => $key,
				'login' => $user->user_login,
			),
			Routes::url( Request::ROUTE_SET_PASSWORD )
		);
	}

	/**
	 * Hands one message to the configured transport.
	 *
	 * @param WP_User $user    Recipient.
	 * @param string  $subject Subject.
	 * @param string  $body    Plain-text body.
	 * @return bool
	 */
	private function send( WP_User $user, string $subject, string $body ): bool {
		if ( '' === $body || ! str_contains( $body, Routes::url( Request::ROUTE_SET_PASSWORD ) ) ) {
			return false;
		}

		return $this->deliver( $user, $subject, $body );
	}

	/**
	 * Hand one validated message to the configured transport.
	 *
	 * @param WP_User $user    Recipient.
	 * @param string  $subject Subject.
	 * @param string  $body    Plain-text body.
	 */
	private function deliver( WP_User $user, string $subject, string $body ): bool {

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional, single-recipient account access or recovery message.
		return wp_mail( $user->user_email, $subject, $body );
	}

	/**
	 * Returns a header-safe site name.
	 *
	 * @return string
	 */
	private function site_name(): string {
		return sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	}
}
