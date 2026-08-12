<?php
/**
 * Signing in to the portal, on the portal.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Access_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Security\Rate_Limiter;
use WP_Error;
use WP_User;

/**
 * The portal's own sign-in form, authenticated by WordPress core.
 *
 * **Our markup, core's authentication.** The credentials go to wp_signon(),
 * which owns password verification, the auth cookies, session tokens and the
 * `authenticate` filter chain. That last one matters most: two-factor plugins,
 * login limiters and SSO all hook it, and a hand-rolled password comparison
 * would silently step around every one of them while looking like it worked.
 * Nothing here re-implements authentication — it re-skins the form.
 *
 * Three things this layer does own:
 *
 * - **Rate limiting**, counted per client rather than per user, because a
 *   failed attempt has no user to count against.
 * - **A single error message** for every failure. Core's login errors
 *   distinguish an unknown account from a wrong password, which turns the
 *   form into an account enumerator.
 * - **Redirect safety**, so `redirect_to` cannot bounce someone off-site.
 */
final class Login_Actions implements Service {

	public const LOGIN_ACTION  = 'laao_ads_login';
	public const LOGOUT_ACTION = 'laao_ads_logout';

	/**
	 * Constructor.
	 *
	 * @param Rate_Limiter          $limiter Abuse bounding.
	 * @param Audit_Repository      $audit   Audit persistence.
	 * @param Org_Access_Repository $access Pending organization access.
	 */
	public function __construct(
		private readonly Rate_Limiter $limiter,
		private readonly Audit_Repository $audit,
		private readonly Org_Access_Repository $access
	) {
	}

	/**
	 * Attaches the handlers.
	 *
	 * Registered for logged-out callers too — that is the entire point, and
	 * forgetting the nopriv variant is why a login form silently 400s.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_nopriv_' . self::LOGIN_ACTION, array( $this, 'handle_login' ) );
		add_action( 'admin_post_' . self::LOGIN_ACTION, array( $this, 'handle_login' ) );
	}

	/**
	 * Signs a person in and returns them to where they were going.
	 *
	 * @return void
	 */
	public function handle_login(): void {
		check_admin_referer( self::LOGIN_ACTION );

		$redirect = $this->requested_redirect();

		/*
		 * Counted before the credentials are looked at, and counted on every
		 * attempt rather than only on failures. Counting failures alone leaves
		 * an attacker free to test a password against ten thousand addresses
		 * as long as most of them fail cheaply.
		 */
		$allowed = $this->limiter->attempt_for( Rate_Limiter::ACTION_LOGIN, Rate_Limiter::client_subject() );

		if ( is_wp_error( $allowed ) ) {
			$this->redirect( $redirect, 'rate_limited' );
		}

		$user = wp_signon(
			array(
				'user_login'    => isset( $_POST['log'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['log'] ) ) ) : '',
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is a secret, not content. Sanitizing it would silently alter it and lock out anyone whose password contains the characters a sanitizer strips; wp_signon() hashes and compares the raw value.
				'user_password' => isset( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '',
				'remember'      => isset( $_POST['rememberme'] ),
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$this->record_failure( $user );
			$this->redirect( $redirect, 'failed' );
		}

		if ( ! $user instanceof WP_User || ! user_can( $user, Capabilities::ACCESS_PORTAL ) ) {
			if ( $user instanceof WP_User && null !== $this->access->pending_for_user( $user->ID ) ) {
				wp_logout();
				$this->redirect( Routes::url(), 'pending' );
			}

			/*
			 * A real account that may not use the portal — a subscriber, or a
			 * former advertiser. Signed in successfully, so the session is
			 * theirs; the portal simply is not. Sending them to the 403 screen
			 * is more useful than a login form that keeps refusing valid
			 * credentials with no explanation.
			 */
			$this->redirect( Routes::url(), '' );
		}

		$this->redirect( $redirect, '' );
	}

	/**
	 * Where a successful sign-in should land.
	 *
	 * Validated with wp_validate_redirect() against the site's own host, so a crafted
	 * `redirect_to` cannot carry someone to a lookalike site with a portal
	 * session in their browser.
	 *
	 * @return string
	 */
	private function requested_redirect(): string {
		$fallback = Routes::url();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified by the caller before this runs; this only reads a destination that is then validated against the site host.
		$raw = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( '' === $raw ) {
			return $fallback;
		}

		return wp_validate_redirect( $raw, $fallback );
	}

	/**
	 * Records a failed attempt without recording who was guessed at.
	 *
	 * The email is deliberately absent: an audit table that accumulates
	 * attempted logins accumulates other people's passwords the first time
	 * somebody types one into the email box.
	 *
	 * @param WP_Error $error Core's authentication error.
	 * @return void
	 */
	private function record_failure( WP_Error $error ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'portal.login_failed',
				outcome: Audit_Event::OUTCOME_DENIED,
				object_type: 'user',
				object_id: 0,
				message: 'A portal sign-in attempt was refused.',
				context: array( 'reason' => (string) $error->get_error_code() )
			)
		);
	}

	/**
	 * Reads the allowlisted sign-in notice.
	 *
	 * @return string
	 */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state; selects a fixed message and authorizes nothing.
		$value = isset( $_GET['laao_ads_login'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_login'] ) ) : '';

		return in_array( $value, array( 'failed', 'rate_limited', 'password_set', 'pending' ), true ) ? $value : '';
	}

	/**
	 * The one sentence every failure gets.
	 *
	 * Identical for an unknown email and a wrong password, on purpose. Core
	 * distinguishes them, and that distinction is what turns any login form
	 * into a way of discovering which accounts exist.
	 *
	 * @param string $code Notice code.
	 * @return string
	 */
	public static function notice_message( string $code ): string {
		return match ( $code ) {
			'rate_limited' => __( 'Too many sign-in attempts. Please wait a few minutes and try again.', 'laao-advertiser-portal' ),
			'password_set' => __( 'Your password is ready. Sign in with your work email.', 'laao-advertiser-portal' ),
			'pending'      => __( 'Your organization access request is still waiting for approval.', 'laao-advertiser-portal' ),
			default        => __( 'That email and password did not match. Please try again.', 'laao-advertiser-portal' ),
		};
	}

	/**
	 * Returns to the sign-in screen, or onward on success.
	 *
	 * @param string $url    Destination.
	 * @param string $notice Notice code, empty on success.
	 * @return never
	 */
	private function redirect( string $url, string $notice ): never {
		if ( '' !== $notice ) {
			$url = add_query_arg(
				array(
					'laao_ads_login' => $notice,
					'redirect_to'    => rawurlencode( $url ),
				),
				Routes::url( Request::ROUTE_LOGIN )
			);
		}

		wp_safe_redirect( $url );

		exit;
	}
}
