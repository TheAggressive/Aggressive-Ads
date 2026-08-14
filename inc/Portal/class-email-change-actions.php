<?php
/**
 * Portal email-change form handling.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Advertiser_Registration;
use Aggressive\Ads\Workflow\Email_Change;
use WP_Error;

/**
 * Request, cancel and confirm self-service email changes.
 */
final class Email_Change_Actions implements Service {

	public const REQUEST_ACTION = 'aggr_request_email_change';
	public const CANCEL_ACTION  = 'aggr_cancel_email_change';
	public const CONFIRM_ACTION = 'aggr_confirm_email_change';

	/**
	 * Constructor.
	 *
	 * @param Email_Change $changes Email-change workflow.
	 * @param Rate_Limiter $limiter Per-user abuse bound.
	 */
	public function __construct(
		private readonly Email_Change $changes,
		private readonly Rate_Limiter $limiter
	) {
	}

	/** Attach authenticated and confirmation handlers. */
	public function init(): void {
		add_action( 'admin_post_' . self::REQUEST_ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::CONFIRM_ACTION, array( $this, 'handle_confirm' ) );
	}

	/** Start a change for the signed-in portal user. */
	public function handle_request(): void {
		$this->assert_portal_access();
		check_admin_referer( self::REQUEST_ACTION );

		$user_id = get_current_user_id();
		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_EMAIL_CHANGE, $user_id );
		if ( is_wp_error( $allowed ) ) {
			$this->redirect_account( 'rate_limited' );
		}

		$result = $this->changes->request( $user_id, $this->post_email() );
		if ( is_wp_error( $result ) ) {
			$this->redirect_account( 'email_error', $result );
		}

		$this->redirect_account( 'email_sent' );
	}

	/** Cancel a pending challenge. */
	public function handle_cancel(): void {
		$this->assert_portal_access();
		check_admin_referer( self::CANCEL_ACTION );

		$result = $this->changes->cancel( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->redirect_account( 'email_error', $result );
		}

		$this->redirect_account( 'email_cancelled' );
	}

	/** Complete a change after the mailbox link and a signed-in session. */
	public function handle_confirm(): void {
		$this->assert_portal_access();
		check_admin_referer( self::CONFIRM_ACTION );

		$result = $this->changes->confirm(
			get_current_user_id(),
			$this->post_login(),
			$this->post_token()
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_confirm( 'error', $result );
		}

		$this->redirect_account( 'email_changed' );
	}

	/**
	 * Bounded query-string argument for the confirmation screen.
	 *
	 * @param string $key    Argument name.
	 * @param int    $maxlen Maximum accepted length.
	 */
	public static function link_argument( string $key, int $maxlen ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A confirmation key authorizes nothing until the workflow validates it; the bounded scalar is sanitized on return.
		$value = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';

		return strlen( $value ) <= $maxlen ? sanitize_text_field( $value ) : '';
	}

	/** Allowlisted account-screen notice. */
	public static function account_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only PRG display state.
		$value = isset( $_GET['aggr_notice'] ) ? sanitize_key( wp_unslash( $_GET['aggr_notice'] ) ) : '';

		return in_array(
			$value,
			array( 'email_sent', 'email_cancelled', 'email_changed', 'email_error', 'rate_limited' ),
			true
		) ? $value : '';
	}

	/**
	 * Fixed account notice copy for email-change outcomes.
	 *
	 * @param string $notice Notice code.
	 */
	public static function account_notice_message( string $notice ): string {
		return match ( $notice ) {
			'email_sent'      => __( 'Check the new inbox for a confirmation link. You must be signed in to finish.', 'aggressive-ads' ),
			'email_cancelled' => __( 'The pending email change was cancelled.', 'aggressive-ads' ),
			'email_changed'   => __( 'Your email address was updated.', 'aggressive-ads' ),
			'rate_limited'    => __( 'Too many email change requests. Please wait before trying again.', 'aggressive-ads' ),
			default           => __( 'The email change could not be completed.', 'aggressive-ads' ),
		};
	}

	/**
	 * Fixed error copy for email-change validation failures.
	 *
	 * @param string $code Error code.
	 */
	public static function error_message( string $code ): string {
		return match ( $code ) {
			'aggr_invalid_email'             => __( 'Enter a valid email address.', 'aggressive-ads' ),
			'aggr_email_unchanged'           => __( 'That is already your email address.', 'aggressive-ads' ),
			'aggr_email_change_mail_failed'  => __( 'The confirmation email could not be sent. Please try again shortly.', 'aggressive-ads' ),
			'aggr_email_change_not_saved'    => __( 'The email change could not be started.', 'aggressive-ads' ),
			'aggr_invalid_email_change'      => __( 'This confirmation link is invalid or has expired.', 'aggressive-ads' ),
			'aggr_email_taken'               => __( 'That email address is no longer available.', 'aggressive-ads' ),
			'aggr_email_not_saved'           => __( 'Your email address could not be updated.', 'aggressive-ads' ),
			default                              => __( 'The email change could not be completed.', 'aggressive-ads' ),
		};
	}

	/** Confirm-screen notice from PRG. */
	public static function confirm_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only PRG display state.
		$value = isset( $_GET['aggr_notice'] ) ? sanitize_key( wp_unslash( $_GET['aggr_notice'] ) ) : '';

		return in_array( $value, array( 'error' ), true ) ? $value : '';
	}

	/** Confirm-screen error code. */
	public static function confirm_error_code(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only PRG display state.
		$value = isset( $_GET['aggr_error'] ) ? sanitize_key( wp_unslash( $_GET['aggr_error'] ) ) : '';

		return '' === $value ? '' : $value;
	}

	/** Refuse callers without portal access. */
	private function assert_portal_access(): void {
		if ( is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ),
			'',
			array( 'response' => 403 )
		);
	}

	/** Read the requested destination email from the nonce-protected POST. */
	private function post_email(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller verifies the action nonce; value is length-bounded then sanitize_email()'d in the workflow.
		$value = isset( $_POST['new_email'] ) && is_string( $_POST['new_email'] ) ? wp_unslash( $_POST['new_email'] ) : '';

		return strlen( $value ) <= Advertiser_Registration::MAX_EMAIL ? $value : '';
	}

	/** Read the opaque login from the nonce-protected POST. */
	private function post_login(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies the confirm action nonce.
		$value = isset( $_POST['login'] ) && is_string( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';

		return strlen( $value ) <= 60 ? $value : '';
	}

	/** Read the raw confirmation token from the nonce-protected POST. */
	private function post_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller verifies the confirm action nonce; token is compared via hash_equals against a stored HMAC.
		$value = isset( $_POST['key'] ) && is_string( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : '';

		return strlen( $value ) <= 100 ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Return to the account screen with an allowlisted notice.
	 *
	 * @param string        $notice Notice code.
	 * @param WP_Error|null $error  Optional error.
	 * @return never
	 */
	private function redirect_account( string $notice, ?WP_Error $error = null ): never {
		$args = array( 'aggr_notice' => $notice );
		if ( null !== $error ) {
			$args['aggr_error'] = sanitize_key( (string) $error->get_error_code() );
		}

		wp_safe_redirect( add_query_arg( $args, Routes::url( Request::ROUTE_ACCOUNT ) ) );
		exit;
	}

	/**
	 * Return to the confirmation screen with an allowlisted notice.
	 *
	 * @param string        $notice Notice code.
	 * @param WP_Error|null $error  Optional error.
	 * @return never
	 */
	private function redirect_confirm( string $notice, ?WP_Error $error = null ): never {
		$args = array(
			'aggr_notice' => $notice,
			'key'         => $this->post_token(),
			'login'       => $this->post_login(),
		);
		if ( null !== $error ) {
			$args['aggr_error'] = sanitize_key( (string) $error->get_error_code() );
		}

		wp_safe_redirect( add_query_arg( $args, Routes::url( Request::ROUTE_CONFIRM_EMAIL ) ) );
		exit;
	}
}
