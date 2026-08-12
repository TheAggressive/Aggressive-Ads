<?php
/**
 * The advertiser's own account, which they can otherwise never reach.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Notification\Password_Notification;
use LAAO_Advertiser_Portal\Security\Capabilities;
use WP_Error;
use WP_User;

/**
 * Profile writes for a user who has no access to wp-admin.
 *
 * **Why this exists.** `Security\Admin_Guard` redirects portal users away from
 * wp-admin, so /wp-admin/profile.php is unreachable for an advertiser. Until
 * this screen existed there was no way for one to change their own display
 * name, and no way to reach a password change except by guessing that the
 * login screen's "Lost your password?" link would work. The guard is right;
 * the missing destination was the defect.
 *
 * **What is deliberately not here.** Changing the email address is not
 * self-service. Core's flow for that emails a signed confirmation link to the
 * *new* address and completes on profile.php, which these users cannot reach,
 * so supporting it means owning a token: issue, expiry, single use, and rate
 * limiting. An account-takeover primitive is not something to approximate on
 * the way to a settings screen, so the address is shown read-only and changing
 * it goes through staff. See docs/roadmap.md.
 */
final class Account_Actions implements Service {

	public const SAVE_ACTION     = 'laao_ads_save_account';
	public const PASSWORD_ACTION = 'laao_ads_request_password_reset';

	/**
	 * The longest display name worth storing.
	 *
	 * Matches the campaign title bound for the same reason: a field with no
	 * limit is a field somebody eventually pastes a document into.
	 */
	public const MAX_NAME_LENGTH = 100;

	/**
	 * Constructor.
	 *
	 * @param Password_Notification $notification Portal recovery email.
	 */
	public function __construct( private readonly Password_Notification $notification ) {
	}

	/**
	 * Attaches the form handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::PASSWORD_ACTION, array( $this, 'handle_password_reset' ) );
	}

	/**
	 * Saves the fields an advertiser may change about themselves.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->assert_portal_access();
		check_admin_referer( self::SAVE_ACTION );

		$fields = array(
			'first_name'   => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
			'last_name'    => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
			'display_name' => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
		);

		$result = $this->process_save( get_current_user_id(), $fields );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result );
		}

		$this->redirect( 'saved' );
	}

	/**
	 * Sends the caller a password reset link.
	 *
	 * Password_Notification issues WordPress core's single-use reset key but
	 * puts the portal's set-password route in the message. The key, expiry,
	 * hashing and invalidation remain core-owned; the customer never crosses
	 * into WordPress's login UI.
	 *
	 * @return void
	 */
	public function handle_password_reset(): void {
		$this->assert_portal_access();
		check_admin_referer( self::PASSWORD_ACTION );

		$user = wp_get_current_user();

		if ( ! $user instanceof WP_User || 0 === $user->ID ) {
			$this->redirect( 'error', new WP_Error( 'laao_ads_account_missing', __( 'Your account could not be read.', 'laao-advertiser-portal' ) ) );
		}

		$sent = $this->notification->send_reset( $user->ID );

		if ( ! $sent ) {
			/*
			 * Core's own error, deliberately not forwarded verbatim: its codes
			 * distinguish "no such user" from "could not send", which is a
			 * distinction worth keeping out of a response anyone can trigger.
			 */
			$this->redirect( 'error', new WP_Error( 'laao_ads_password_reset_failed', __( 'The reset email could not be sent.', 'laao-advertiser-portal' ) ) );
		}

		$this->redirect( 'password_sent' );
	}

	/**
	 * Testable entry point for the profile write.
	 *
	 * @param int                   $user_id User to update.
	 * @param array<string, string> $fields  Sanitized values.
	 * @return true|WP_Error
	 */
	public function process_save( int $user_id, array $fields ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'laao_ads_account_missing', __( 'Your account could not be read.', 'laao-advertiser-portal' ) );
		}

		$display = trim( $fields['display_name'] ?? '' );

		if ( '' === $display ) {
			return new WP_Error( 'laao_ads_display_name_required', __( 'Enter a name to display.', 'laao-advertiser-portal' ) );
		}

		foreach ( array( $display, trim( $fields['first_name'] ?? '' ), trim( $fields['last_name'] ?? '' ) ) as $value ) {
			if ( mb_strlen( $value ) > self::MAX_NAME_LENGTH ) {
				return new WP_Error( 'laao_ads_name_too_long', __( 'Use 100 characters or fewer.', 'laao-advertiser-portal' ) );
			}
		}

		/*
		 * wp_update_user(), never a direct write.
		 *
		 * It is the only path that clears the user caches and fires the hooks
		 * other code listens to. It also means the update is confined to the
		 * three keys named here — a defence that matters because this handler
		 * is reachable by anyone holding the portal capability, and an array
		 * forwarded wholesale from $_POST would let one of them set `role`.
		 */
		$updated = wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => trim( $fields['first_name'] ?? '' ),
				'last_name'    => trim( $fields['last_name'] ?? '' ),
				'display_name' => $display,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'laao_ads_account_not_saved', __( 'Your details could not be saved.', 'laao-advertiser-portal' ) );
		}

		return true;
	}

	/**
	 * Reads an allowlisted display-only notice.
	 *
	 * @return string
	 */
	public static function request_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post/redirect/get display state; never authorizes or mutates anything.
		$value = isset( $_GET['laao_ads_notice'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_notice'] ) ) : '';

		return in_array( $value, array( 'saved', 'password_sent', 'error' ), true ) ? $value : '';
	}

	/**
	 * Reads an allowlisted error code.
	 *
	 * @return string
	 */
	public static function request_error_code(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post/redirect/get display state; never authorizes or mutates anything.
		$value = isset( $_GET['laao_ads_error'] ) ? sanitize_key( wp_unslash( $_GET['laao_ads_error'] ) ) : '';

		return '' === $value ? '' : $value;
	}

	/**
	 * The sentence shown for an error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		return match ( $code ) {
			'laao_ads_display_name_required' => __( 'Enter a name to display.', 'laao-advertiser-portal' ),
			'laao_ads_name_too_long'         => __( 'Use 100 characters or fewer.', 'laao-advertiser-portal' ),
			'laao_ads_password_reset_failed' => __( 'The reset email could not be sent. Please try again shortly.', 'laao-advertiser-portal' ),
			'laao_ads_account_missing'       => __( 'Your account could not be read.', 'laao-advertiser-portal' ),
			default                          => __( 'Your details could not be saved.', 'laao-advertiser-portal' ),
		};
	}

	/**
	 * Refuses anyone without portal access.
	 *
	 * @return void
	 */
	private function assert_portal_access(): void {
		if ( is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have permission to do that.', 'laao-advertiser-portal' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Returns to the account screen carrying only an allowlisted code.
	 *
	 * @param string        $notice Notice key.
	 * @param WP_Error|null $error  Error, when there is one.
	 * @return never
	 */
	private function redirect( string $notice, ?WP_Error $error = null ): never {
		$args = array( 'laao_ads_notice' => $notice );

		if ( null !== $error ) {
			$args['laao_ads_error'] = sanitize_key( (string) $error->get_error_code() );
		}

		wp_safe_redirect( add_query_arg( $args, Routes::url( Request::ROUTE_ACCOUNT ) ) );

		exit;
	}
}
