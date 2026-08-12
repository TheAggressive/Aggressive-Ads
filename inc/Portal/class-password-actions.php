<?php
/**
 * Portal-native password setup and recovery actions.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Notification\Password_Notification;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\User_Repository;
use LAAO_Advertiser_Portal\Security\Capabilities;
use LAAO_Advertiser_Portal\Security\Rate_Limiter;
use LAAO_Advertiser_Portal\Workflow\Password_Reset;
use WP_Error;

/**
 * Owns the public forms while delegating key and password handling to core.
 */
final class Password_Actions implements Service {

	public const REQUEST_ACTION = 'laao_ads_request_password';
	public const SET_ACTION     = 'laao_ads_set_password';

	/**
	 * Constructor.
	 *
	 * @param User_Repository       $users        User lookup.
	 * @param Password_Notification $notification Transactional mail.
	 * @param Password_Reset        $passwords    Password workflow.
	 * @param Rate_Limiter          $limiter      Anonymous abuse bound.
	 * @param Audit_Repository      $audit        Audit persistence.
	 */
	public function __construct(
		private readonly User_Repository $users,
		private readonly Password_Notification $notification,
		private readonly Password_Reset $passwords,
		private readonly Rate_Limiter $limiter,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Attaches anonymous and stale-session variants of both actions.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_nopriv_' . self::REQUEST_ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_' . self::REQUEST_ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_nopriv_' . self::SET_ACTION, array( $this, 'handle_set' ) );
		add_action( 'admin_post_' . self::SET_ACTION, array( $this, 'handle_set' ) );
	}

	/**
	 * Sends a recovery link without revealing whether an address exists.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		check_admin_referer( self::REQUEST_ACTION );

		$redirect = $this->requested_redirect();
		$allowed  = $this->limiter->attempt_for( Rate_Limiter::ACTION_PASSWORD_RESET, Rate_Limiter::client_subject() );

		if ( is_wp_error( $allowed ) ) {
			$this->redirect_request( 'rate_limited', $redirect );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The action nonce was verified above; the bounded scalar is sanitized immediately below.
		$raw   = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '';
		$email = strlen( $raw ) <= 100 ? strtolower( sanitize_email( $raw ) ) : '';
		$user  = '' !== $email && is_email( $email ) ? $this->users->by_email( $email ) : null;

		if ( null !== $user && user_can( $user, Capabilities::ACCESS_PORTAL ) && ! $this->notification->send_reset( $user->ID ) ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'portal.password_email_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'user',
					object_id: $user->ID,
					message: 'The advertiser password-recovery email was not accepted by the transport.'
				)
			);
		}

		/*
		 * Missing accounts, non-portal accounts and mail failures deliberately get
		 * the same response as success. The public form is not an account oracle.
		 */
		$this->redirect_request( 'sent', $redirect );
	}

	/**
	 * Preserves only a same-site destination from the sign-in journey.
	 *
	 * @return string
	 */
	private function requested_redirect(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified the nonce; this navigation value is validated against the site host.
		$raw = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';

		return '' === $raw ? '' : wp_validate_redirect( $raw, '' );
	}

	/**
	 * Consumes one reset key and returns only to portal-owned screens.
	 *
	 * @return void
	 */
	public function handle_set(): void {
		check_admin_referer( self::SET_ACTION );

		$key      = $this->post_text( 'key', 100 );
		$login    = $this->post_text( 'login', 60 );
		$password = $this->post_secret( 'password' );
		$confirm  = $this->post_secret( 'password_confirmation' );
		$result   = $this->passwords->reset( $key, $login, $password, $confirm );

		if ( true === $result ) {
			wp_safe_redirect(
				add_query_arg( 'laao_ads_login', 'password_set', Routes::url( Request::ROUTE_LOGIN ) )
			);

			exit;
		}

		if ( 'laao_ads_invalid_password' === $result->get_error_code() ) {
			$this->redirect_set( 'invalid_password', $key, $login );
		}

		$this->redirect_set( 'invalid_key' );
	}

	/**
	 * Reads a bounded text field after nonce verification.
	 *
	 * @param string $key Field name.
	 * @param int    $max Maximum bytes.
	 * @return string
	 */
	private function post_text( string $key, int $max ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The caller verifies the action nonce; the bounded scalar is sanitized on return.
		$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		return strlen( $value ) <= $max ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Reads a password without content sanitization.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function post_secret( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered; the workflow bounds them before hashing.
		$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		return strlen( $value ) <= Password_Reset::MAX_LENGTH ? $value : '';
	}

	/**
	 * Reads the password-request notice allowlist.
	 *
	 * @return string
	 */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state selects a fixed message.
		$value = isset( $_GET['laao_ads_password_request'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_password_request'] ) ) : '';

		return in_array( $value, array( 'sent', 'rate_limited' ), true ) ? $value : '';
	}

	/**
	 * Reads the set-password notice allowlist.
	 *
	 * @return string
	 */
	public static function set_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state selects a fixed message.
		$value = isset( $_GET['laao_ads_password'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_password'] ) ) : '';

		return in_array( $value, array( 'invalid_password', 'invalid_key' ), true ) ? $value : '';
	}

	/**
	 * Reads a bounded reset-link argument.
	 *
	 * @param string $name Argument name.
	 * @param int    $max  Maximum bytes.
	 * @return string
	 */
	public static function link_argument( string $name, int $max ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A reset key authorizes nothing until core validates it; the bounded scalar is sanitized on return.
		$value = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? wp_unslash( $_GET[ $name ] ) : '';

		return strlen( $value ) <= $max ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Fixed request-screen copy.
	 *
	 * @param string $notice Notice code.
	 * @return string
	 */
	public static function request_message( string $notice ): string {
		return 'rate_limited' === $notice
			? __( 'Too many password requests. Please wait before trying again.', 'laao-advertiser-portal' )
			: __( 'If a portal account uses that address, a one-time password link has been sent.', 'laao-advertiser-portal' );
	}

	/**
	 * Redirects back to the recovery request screen.
	 *
	 * @param string $notice   Notice code.
	 * @param string $redirect Same-site post-login destination.
	 * @return never
	 */
	private function redirect_request( string $notice, string $redirect = '' ): never {
		$args = array( 'laao_ads_password_request' => $notice );

		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}

		wp_safe_redirect(
			add_query_arg( $args, Routes::url( Request::ROUTE_FORGOT_PASSWORD ) )
		);

		exit;
	}

	/**
	 * Redirects back to a set-password state without ever carrying a password.
	 *
	 * @param string $notice Notice code.
	 * @param string $key    Reset key when it may be retried.
	 * @param string $login  Opaque login when it may be retried.
	 * @return never
	 */
	private function redirect_set( string $notice, string $key = '', string $login = '' ): never {
		$args = array( 'laao_ads_password' => $notice );

		if ( '' !== $key && '' !== $login ) {
			$args['key']   = $key;
			$args['login'] = $login;
		}

		wp_safe_redirect( add_query_arg( $args, Routes::url( Request::ROUTE_SET_PASSWORD ) ) );

		exit;
	}
}
