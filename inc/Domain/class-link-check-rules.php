<?php
/**
 * What may be fetched when an advertiser asks whether their link works.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The gate in front of the one server-side fetch this plugin makes.
 *
 * `docs/threat-model.md` said nothing server-side ever fetches a destination
 * URL. Checking a link is exactly that request, to an address an advertiser
 * supplies, so it is the plugin's whole SSRF surface and is judged here before
 * anything leaves the server.
 *
 * **Stricter than a click URL.** `Campaign_Rules::is_valid_click_url()` says
 * what may be stored and rendered as an `href`, where the visitor's own
 * browser does the fetching and a private address reaches only their machine.
 * This says what the *server* may open a connection to, which is a different
 * question with a worse failure: refusing something checkable costs an
 * advertiser a convenience, while allowing something unfetchable would let
 * them read the inside of the host's network.
 *
 * Refusals are named so the page can say which rule was met, and named
 * vaguely enough that the answer maps to the URL the advertiser typed rather
 * than to anything about the network it was pointed at.
 */
final class Link_Check_Rules {

	public const REFUSE_MALFORMED   = 'malformed';
	public const REFUSE_SCHEME      = 'scheme';
	public const REFUSE_CREDENTIALS = 'credentials';
	public const REFUSE_PORT        = 'port';
	public const REFUSE_ADDRESS     = 'address';
	public const REFUSE_HOST        = 'host';

	public const OUTCOME_WORKS       = 'works';
	public const OUTCOME_MISSING     = 'missing';
	public const OUTCOME_PRIVATE     = 'private';
	public const OUTCOME_BROKEN      = 'broken';
	public const OUTCOME_UNREACHABLE = 'unreachable';

	/**
	 * Ports the check will connect to. The default port for each allowed
	 * scheme and nothing else: a URL naming another port is either not a web
	 * page or is aimed at something that only answers inside the network.
	 */
	private const ALLOWED_PORTS = array( 80, 443 );

	/**
	 * Host suffixes that resolve only inside a network, whatever DNS says.
	 *
	 * Checked as well as the resolved address, not instead of it, because a
	 * public resolver can answer with a private address and a private
	 * resolver can answer with a public one.
	 */
	private const PRIVATE_SUFFIXES = array( '.local', '.localhost', '.internal', '.intranet', '.home.arpa', '.test', '.example', '.invalid' );

	/**
	 * Host names that mean this machine.
	 */
	private const PRIVATE_HOSTS = array( 'localhost', 'ip6-localhost', 'ip6-loopback' );

	/**
	 * Why this URL may not be fetched, or '' when it may.
	 *
	 * @param string $url The advertiser's destination link.
	 * @return string One of the REFUSE_* constants, or '' to allow the fetch.
	 */
	public static function refuse( string $url ): string {
		/*
		 * Before trimming, not after. `trim()` eats a trailing null byte and
		 * newline, so trimming first turned "https://example.com/\0" into an
		 * ordinary URL and allowed it — while the caller still held the raw
		 * string it would have opened a connection to.
		 */
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return self::REFUSE_MALFORMED;
		}

		$url = trim( $url );

		if ( '' === $url ) {
			return self::REFUSE_MALFORMED;
		}

		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is a WordPress function, and this layer must not call one; see docs/architecture.md.

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			return self::REFUSE_MALFORMED;
		}

		if ( ! isset( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), Campaign_Rules::ALLOWED_URL_SCHEMES, true ) ) {
			return self::REFUSE_SCHEME;
		}

		// Credentials in a URL are sent to whatever answers, including a redirect target.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::REFUSE_CREDENTIALS;
		}

		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], self::ALLOWED_PORTS, true ) ) {
			return self::REFUSE_PORT;
		}

		return self::refuse_host( (string) $parts['host'] );
	}

	/**
	 * Why this host may not be fetched, or '' when it may.
	 *
	 * A bare address is refused outright rather than range-checked. An
	 * advertiser's campaign points at a site with a name; a link written as an
	 * address is the shape of a probe, and refusing the shape leaves nothing
	 * to get subtly wrong about which ranges are public this decade.
	 *
	 * @param string $host Host component of the URL.
	 * @return string One of the REFUSE_* constants, or ''.
	 */
	private static function refuse_host( string $host ): string {
		$host = strtolower( rtrim( $host, '.' ) );

		// Bracketed IPv6, as a URL writes it.
		if ( str_starts_with( $host, '[' ) ) {
			return self::REFUSE_ADDRESS;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::REFUSE_ADDRESS;
		}

		if ( in_array( $host, self::PRIVATE_HOSTS, true ) ) {
			return self::REFUSE_HOST;
		}

		foreach ( self::PRIVATE_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return self::REFUSE_HOST;
			}
		}

		// A name with no dot is a machine on the local network, not a website.
		if ( ! str_contains( $host, '.' ) ) {
			return self::REFUSE_HOST;
		}

		return '';
	}

	/**
	 * Whether an address the host resolved to may be connected to.
	 *
	 * The caller resolves; this judges. Public ranges only, so a name that
	 * answers with 127.0.0.1 or 169.254.169.254 — the cloud metadata service —
	 * is refused even though the name itself looked ordinary.
	 *
	 * @param string $ip A resolved IPv4 or IPv6 address.
	 * @return bool
	 */
	public static function is_public_address( string $ip ): bool {
		return false !== filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * What a response code means to the advertiser.
	 *
	 * @param int $status HTTP status code, or zero when nothing answered.
	 * @return string One of the OUTCOME_* constants.
	 */
	public static function outcome( int $status ): string {
		if ( $status >= 200 && $status < 400 ) {
			return self::OUTCOME_WORKS;
		}

		if ( 404 === $status || 410 === $status ) {
			return self::OUTCOME_MISSING;
		}

		/*
		 * A page behind a login or a bot wall is not a broken link, and saying
		 * "broken" would send the advertiser to fix a link that is fine. Many
		 * sites answer 403 to any request that is not a browser.
		 */
		if ( in_array( $status, array( 401, 403, 405, 429 ), true ) ) {
			return self::OUTCOME_PRIVATE;
		}

		return $status > 0 ? self::OUTCOME_BROKEN : self::OUTCOME_UNREACHABLE;
	}
}
