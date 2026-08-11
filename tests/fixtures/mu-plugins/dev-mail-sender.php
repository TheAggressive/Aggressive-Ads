<?php
/**
 * Plugin Name: LAAO Ads dev mail sender
 * Description: Gives wp_mail a valid From address inside wp-env.
 *
 * Development fixture, never shipped: tests/fixtures/ is outside the release
 * archive and the packaging script fails if it appears.
 *
 * wp-env's default From address is `wordpress@localhost`, which PHPMailer
 * rejects outright as an invalid address. Every wp_mail() call therefore failed
 * before it reached a transport, and the plugin — correctly — recorded each one
 * as a failed notification. The result was a review timeline full of red that
 * described the environment rather than the code, which is the kind of noise
 * that teaches people to ignore a real failure.
 *
 * This only makes the address valid. There is still no SMTP transport in
 * wp-env, so nothing is delivered; the point is that failures now mean
 * something.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

add_filter(
	'wp_mail_from',
	static function ( string $from ): string {
		return str_contains( $from, '@localhost' ) ? 'wordpress@example.test' : $from;
	}
);
