<?php
/**
 * Plugin Name: Aggressive Ads dev mail sender
 * Description: Gives wp_mail a valid From address inside the test container.
 *
 * Development fixture, never shipped: tests/fixtures/ is outside the release
 * archive and the packaging script fails if it appears.
 *
 * WordPress's default From address is `wordpress@localhost`, which PHPMailer
 * rejects outright as an invalid address. Every wp_mail() call therefore failed
 * before it reached a transport, and the plugin — correctly — recorded each one
 * as a failed notification. The result was a review timeline full of red that
 * described the environment rather than the code, which is the kind of noise
 * that teaches people to ignore a real failure.
 *
 * This only makes the address valid. There is still no SMTP transport in
 * the test container, so nothing is delivered; the point is that failures now mean
 * something.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

add_filter(
	'wp_mail_from',
	static function ( string $from ): string {
		return str_contains( $from, '@localhost' ) ? 'wordpress@example.test' : $from;
	}
);

/*
 * Browser tests exercise request handlers that exit, including signup's
 * compensating rollback when the transport refuses a message. The E2E harness
 * opts into a successful short circuit so it can test the successful browser
 * round trip without requiring a real SMTP service. Integration tests leave
 * the option absent and install their own per-test interception.
 */
add_filter(
	'pre_wp_mail',
	static function ( null|bool $short_circuit, array $mail ): null|bool {
		if ( (bool) get_option( 'aggr_dev_mail_capture', false ) ) {
			/*
			 * Development-only outbox. Attachments are deliberately excluded:
			 * they can contain private file paths and signup sends none. The
			 * fixture never enters a release archive.
			 */
			update_option(
				'aggr_dev_last_mail',
				array(
					'to'      => $mail['to'] ?? '',
					'subject' => $mail['subject'] ?? '',
					'message' => $mail['message'] ?? '',
				),
				false
			);

			return true;
		}

		return (bool) get_option( 'aggr_e2e_mail_success', false ) ? true : $short_circuit;
	},
	10,
	2
);
