<?php
/**
 * Email-change confirmation mail.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Notification;

use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;

/**
 * Sends the one-time confirmation link only to the requested new address.
 */
final class Email_Change_Notification {

	/**
	 * Mail the destination mailbox a portal confirmation URL.
	 *
	 * @param string $email      Destination address.
	 * @param string $login      Opaque WordPress login for the link.
	 * @param string $token      Raw single-use token.
	 * @param int    $expires_at Expiry timestamp.
	 */
	public function send_confirmation( string $email, string $login, string $token, int $expires_at ): bool {
		if ( ! is_email( $email ) || '' === $login || '' === $token || $expires_at <= 0 ) {
			return false;
		}

		$url = add_query_arg(
			array(
				'key'   => $token,
				'login' => $login,
			),
			Routes::url( Request::ROUTE_CONFIRM_EMAIL )
		);

		$subject = __( 'Confirm your new advertiser portal email', 'aggressive-ads' );
		$body    = sprintf(
			/* translators: 1: confirmation URL. 2: expiration date. */
			__( "Confirm this email address for your advertiser portal account using this one-time link:\n\n%1\$s\n\nThis link expires %2\$s. You must be signed in as the same account to finish. If you did not request this change, ignore this message.", 'aggressive-ads' ),
			$url,
			wp_date( 'F j, Y g:i a T', $expires_at )
		);

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional single-recipient confirmation to the requested new address.
		return wp_mail( $email, $subject, $body, Notification_Delivery::sender_headers() );
	}
}
