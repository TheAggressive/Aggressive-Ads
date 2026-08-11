<?php
/**
 * Portal URL grammar.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

/**
 * Where the portal lives.
 *
 * Only the base segment and URL building are here. The rewrite rules, the
 * request parser and the template resolution land with the router; this exists
 * now because the admin guard needs somewhere to send people, and hardcoding
 * that string in the guard is how two definitions of the portal URL appear.
 */
final class Routes {

	/**
	 * The default base segment.
	 */
	public const DEFAULT_BASE = 'advertiser';

	/**
	 * The portal's base segment.
	 *
	 * @return string
	 */
	public static function base(): string {
		/**
		 * Filters the portal's base URL segment.
		 *
		 * @param string $base Base segment, without slashes.
		 */
		$base = apply_filters( 'laao_ads_portal_base', self::DEFAULT_BASE );

		if ( ! is_string( $base ) ) {
			return self::DEFAULT_BASE;
		}

		$base = trim( sanitize_title( $base ) );

		return '' === $base ? self::DEFAULT_BASE : $base;
	}

	/**
	 * An absolute URL to a portal screen.
	 *
	 * @param string $route     Optional route segment, e.g. `campaigns`.
	 * @param int    $object_id Optional object id.
	 * @return string
	 */
	/**
	 * The canonical URL for a screen.
	 *
	 * The dashboard has two spellings — /advertiser/ and /advertiser/dashboard/
	 * — because the grammar names the route and the rewrite rule defaults to
	 * it. Only the short one is canonical, and collapsing that here means the
	 * rule exists once: the rail was already doing it inline, and the sign-in
	 * redirect was not, so signing in landed people on the long spelling of the
	 * page they had asked for.
	 *
	 * @param string $route     Route segment.
	 * @param int    $object_id Optional object id.
	 * @return string
	 */
	public static function canonical( string $route, int $object_id = 0 ): string {
		if ( Request::ROUTE_DASHBOARD === $route && 0 === $object_id ) {
			return self::url();
		}

		return self::url( $route, $object_id );
	}

	/**
	 * An absolute URL to a portal screen.
	 *
	 * @param string $route     Optional route segment, e.g. `campaigns`.
	 * @param int    $object_id Optional object id.
	 * @return string
	 */
	public static function url( string $route = '', int $object_id = 0 ): string {
		$path = self::base();

		if ( '' !== $route ) {
			$path .= '/' . rawurlencode( $route );
		}

		if ( $object_id > 0 ) {
			$path .= '/' . $object_id;
		}

		return home_url( '/' . $path . '/' );
	}
}
