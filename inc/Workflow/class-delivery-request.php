<?php
/**
 * Browser request facts for native fill.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

/**
 * Prefetch, origin, and connecting address. One place reads $_SERVER.
 */
final class Delivery_Request {

	/** Conservative tokens for well-known non-human fetchers. */
	private const BOT_TOKENS = array(
		'ahrefsbot',
		'applebot',
		'baiduspider',
		'bingbot',
		'bytespider',
		'discordbot',
		'dotbot',
		'duckduckbot',
		'facebookexternalhit',
		'googlebot',
		'linkedinbot',
		'mj12bot',
		'petalbot',
		'pinterestbot',
		'semrushbot',
		'slackbot',
		'twitterbot',
		'yandexbot',
	);

	/**
	 * Chromium Sec-Purpose and the older Purpose header.
	 */
	public static function is_prefetch(): bool {
		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE' ) as $header ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as a substring against a fixed token, never stored.
			$value = $_SERVER[ $header ] ?? '';

			if ( is_string( $value ) && str_contains( strtolower( $value ), 'prefetch' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the request identifies as a known crawler or link unfurler.
	 *
	 * This improves reporting quality for cooperative bots; it is not an abuse
	 * boundary because a hostile client can choose any User-Agent. Edge bot
	 * management and token/rate-limit controls remain the security controls.
	 */
	public static function is_obvious_bot(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- Compared with fixed tokens for an uncached tracking write; never persisted or rendered.
		$agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

		if ( ! is_string( $agent ) || '' === $agent ) {
			return false;
		}

		$agent = strtolower( $agent );

		foreach ( self::BOT_TOKENS as $token ) {
			if ( str_contains( $agent, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A browser on another host. Missing Origin is same-origin or non-browser.
	 */
	public static function is_cross_origin(): bool {
		$origin = get_http_origin();

		if ( ! is_string( $origin ) || '' === $origin ) {
			return false;
		}

		$from = wp_parse_url( $origin, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		return ! is_string( $from ) || ! is_string( $home ) || strtolower( $from ) !== strtolower( $home );
	}

	/**
	 * Fetch Metadata for a request that originated on another site.
	 */
	public static function is_cross_site_fetch(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against a fixed token, never stored.
		$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';

		return is_string( $site ) && 'cross-site' === strtolower( $site );
	}

	/**
	 * Validated connecting address, or empty when the server cannot identify one.
	 *
	 * Forwarded-for headers are ignored: they are attacker-controlled unless a
	 * known proxy is in front.
	 */
	public static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Validated as an IP below; hashed by Fill_Token before storage.
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );

		return is_string( $ip ) ? $ip : '';
	}
}
