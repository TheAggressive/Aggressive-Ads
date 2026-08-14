<?php
/**
 * Core-backed password reset workflow.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use WP_Error;
use WP_User;

/**
 * Validates a one-time core key and changes a password without WordPress UI.
 */
final class Password_Reset {

	public const MIN_LENGTH = 12;
	public const MAX_LENGTH = 4096;

	/**
	 * Constructor.
	 *
	 * @param Audit_Repository $audit Audit persistence.
	 */
	public function __construct( private readonly Audit_Repository $audit ) {
	}

	/**
	 * Validates a reset key through WordPress core.
	 *
	 * @param string $key   One-time reset key.
	 * @param string $login Internal opaque login carried by the email link.
	 * @return WP_User|WP_Error
	 */
	public function validate( string $key, string $login ): WP_User|WP_Error {
		if ( '' === $key || '' === $login || strlen( $key ) > 100 || strlen( $login ) > 60 ) {
			return new WP_Error( 'aggr_invalid_reset_key', __( 'This password link is invalid or has expired.', 'aggressive-ads' ) );
		}

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return new WP_Error( 'aggr_invalid_reset_key', __( 'This password link is invalid or has expired.', 'aggressive-ads' ) );
		}

		return $user;
	}

	/**
	 * Consumes the key and changes the password.
	 *
	 * @param string $key          One-time reset key.
	 * @param string $login        Internal opaque login.
	 * @param string $password     New password.
	 * @param string $confirmation Repeated new password.
	 * @return true|WP_Error
	 */
	public function reset( string $key, string $login, string $password, string $confirmation ): bool|WP_Error {
		$user = $this->validate( $key, $login );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$length = strlen( $password );

		if ( $password !== $confirmation || $length < self::MIN_LENGTH || $length > self::MAX_LENGTH ) {
			return new WP_Error( 'aggr_invalid_password', __( 'Use matching passwords of at least 12 characters.', 'aggressive-ads' ) );
		}

		$policy_errors = new WP_Error();

		/**
		 * Runs the same extension point as WordPress's reset screen, so password
		 * policy and compromised-password plugins still participate.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is WordPress core's password-policy extension point, deliberately mirrored from wp-login.php.
		do_action( 'validate_password_reset', $policy_errors, $user );

		if ( $policy_errors->has_errors() ) {
			return new WP_Error( 'aggr_invalid_password', __( 'That password does not meet this site\'s security requirements.', 'aggressive-ads' ) );
		}

		reset_password( $user, $password );

		$this->audit->insert(
			new Audit_Event(
				event: 'portal.password_set',
				object_type: 'user',
				object_id: $user->ID,
				message: 'Set an advertiser password through a one-time portal link.'
			)
		);

		return true;
	}
}
