<?php
/**
 * Public signup form handling.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Advertiser_Registration;

/**
 * Protects, bounds and dispatches anonymous advertiser registration.
 */
final class Signup_Actions implements Service {

	public const SIGNUP_ACTION = 'aggr_signup';

	/**
	 * Constructor.
	 *
	 * @param Advertiser_Registration $registration Registration workflow.
	 * @param Rate_Limiter            $limiter      Anonymous abuse bound.
	 */
	public function __construct(
		private readonly Advertiser_Registration $registration,
		private readonly Rate_Limiter $limiter
	) {
	}

	/**
	 * Attaches both variants so a stale session cannot turn the form into a 400.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_nopriv_' . self::SIGNUP_ACTION, array( $this, 'handle_signup' ) );
		add_action( 'admin_post_' . self::SIGNUP_ACTION, array( $this, 'handle_signup' ) );
	}

	/**
	 * Creates the account and redirects with an allowlisted result code.
	 *
	 * @return void
	 */
	public function handle_signup(): void {
		check_admin_referer( self::SIGNUP_ACTION );

		$invite_token = $this->post_invite_token();

		$allowed = $this->limiter->attempt_for( Rate_Limiter::ACTION_SIGNUP, Rate_Limiter::client_subject() );

		if ( is_wp_error( $allowed ) ) {
			$this->redirect( 'rate_limited', $invite_token );
		}

		/*
		 * A filled trap receives the same success as a real request. Telling an
		 * automated submitter that it was detected only teaches it which field
		 * to omit; no data or mail is created.
		 */
		if ( '' !== $this->post_string( 'company_website' ) ) {
			$this->redirect( 'sent' );
		}

		$result = $this->registration->register(
			array(
				'first_name'        => $this->post_string( 'first_name' ),
				'last_name'         => $this->post_string( 'last_name' ),
				'organization_name' => $this->post_string( 'organization_name' ),
				'email'             => $this->post_string( 'email' ),
				'invite_token'      => $invite_token,
			)
		);

		if ( true === $result ) {
			$this->redirect( 'sent' );
		}

		$notice = match ( $result->get_error_code() ) {
			'aggr_registration_closed' => 'unavailable',
			'aggr_invalid_registration' => 'invalid',
			default                         => 'failed',
		};

		$this->redirect( $notice, $invite_token );
	}

	/**
	 * Reads one scalar POST value after the nonce has been verified.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function post_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handle_signup() verifies the action nonce; the workflow sanitizes and validates its strict field allowlist together.
		$value = $_POST[ $key ] ?? '';

		return is_string( $value ) ? wp_unslash( $value ) : '';
	}

	/**
	 * Reads the allowlisted signup notice.
	 *
	 * @return string
	 */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state; selects a fixed message and authorizes nothing.
		$value = isset( $_GET['aggr_signup'] ) ? sanitize_key( wp_unslash( $_GET['aggr_signup'] ) ) : '';

		return in_array( $value, array( 'sent', 'invalid', 'failed', 'rate_limited', 'unavailable' ), true ) ? $value : '';
	}

	/**
	 * Fixed messages contain no submitted data and no existence signal.
	 *
	 * @param string $code Notice code.
	 * @return string
	 */
	public static function notice_message( string $code ): string {
		return match ( $code ) {
			'sent'         => __( 'Check your email for a one-time link to set your password. If an account already uses that address, no additional email will be sent.', 'aggressive-ads' ),
			'invalid'      => __( 'Enter a valid name, organization and email address.', 'aggressive-ads' ),
			'rate_limited' => __( 'Too many signup attempts. Please wait before trying again.', 'aggressive-ads' ),
			'unavailable'  => __( 'Account registration is not available right now.', 'aggressive-ads' ),
			default        => __( 'We could not create the account. Please try again later.', 'aggressive-ads' ),
		};
	}

	/**
	 * Read a bounded invitation token from the emailed signup URL.
	 */
	public static function request_invite_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The bearer token authorizes nothing until the repository hashes, matches, checks email, state and expiry.
		$value = isset( $_GET['invite'] ) && is_string( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( $_GET['invite'] ) ) : '';

		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $value ) ? $value : '';
	}

	/**
	 * Read a strictly shaped invitation token from the nonce-protected POST.
	 */
	private function post_invite_token(): string {
		$value = $this->post_string( 'invite_token' );

		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $value ) ? $value : '';
	}

	/**
	 * Returns to the public screen without carrying submitted personal data.
	 *
	 * @param string $notice       Result code.
	 * @param string $invite_token Invitation token retained only for a retry.
	 * @return never
	 */
	private function redirect( string $notice, string $invite_token = '' ): never {
		$args = array( 'aggr_signup' => $notice );

		if ( 'sent' !== $notice && '' !== $invite_token ) {
			$args['invite'] = $invite_token;
		}

		wp_safe_redirect( add_query_arg( $args, Routes::url( Request::ROUTE_SIGNUP ) ) );

		exit;
	}
}
