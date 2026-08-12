<?php
/**
 * A parsed portal request.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

/**
 * The URL grammar, as a value object.
 *
 * ```
 * /advertiser/                  → dashboard
 * /advertiser/{route}/          → a top-level screen
 * /advertiser/{route}/{object}/ → a screen scoped to one object
 * ```
 *
 * Unknown routes, over-long segments and anything carrying a path separator
 * resolve to nothing, and the router turns that into a 404 before a controller
 * runs. Calls no WordPress function, so the grammar — which is the part that
 * decides whether a request is even real — is unit-tested without a bootstrap.
 */
final class Request {

	public const ROUTE_DASHBOARD    = 'dashboard';
	public const ROUTE_CAMPAIGNS    = 'campaigns';
	public const ROUTE_ORGANIZATION = 'organization';
	public const ROUTE_ACCOUNT      = 'account';
	public const ROUTE_HELP         = 'help';
	/**
	 * Routes that do not require a session — see Router::gate().
	 */
	public const ROUTE_LOGIN           = 'login';
	public const ROUTE_SIGNUP          = 'signup';
	public const ROUTE_FORGOT_PASSWORD = 'forgot-password';
	public const ROUTE_SET_PASSWORD    = 'set-password';
	public const ROUTE_CONFIRM_EMAIL   = 'confirm-email';

	/**
	 * Long enough for any route we will name, short enough that a segment
	 * cannot be used to carry a payload.
	 */
	public const MAX_SEGMENT_LENGTH = 40;

	/**
	 * Constructor. Private — use from().
	 *
	 * @param string $route     The screen.
	 * @param int    $object_id The object it is scoped to, or 0.
	 */
	private function __construct(
		public readonly string $route,
		public readonly int $object_id
	) {
	}

	/**
	 * Every route the portal answers.
	 *
	 * An allowlist rather than a pattern: a route that does not exist should be
	 * a 404 from the grammar, not a template lookup that happens to miss.
	 *
	 * @return array<int, string>
	 */
	public static function routes(): array {
		return array(
			self::ROUTE_DASHBOARD,
			self::ROUTE_CAMPAIGNS,
			self::ROUTE_ORGANIZATION,
			self::ROUTE_ACCOUNT,
			self::ROUTE_HELP,
			self::ROUTE_LOGIN,
			self::ROUTE_SIGNUP,
			self::ROUTE_FORGOT_PASSWORD,
			self::ROUTE_SET_PASSWORD,
			self::ROUTE_CONFIRM_EMAIL,
		);
	}

	/**
	 * Parses a route and object segment, or returns null.
	 *
	 * @param string $route          Raw route segment.
	 * @param string $object_segment Raw object segment.
	 * @return self|null
	 */
	public static function from( string $route, string $object_segment = '' ): ?self {
		/*
		 * Checked before trimming, and this order is load-bearing.
		 *
		 * PHP's trim() strips "\0" and "\n" as part of its default character
		 * list, so trimming first turns "campaigns\0" into "campaigns" and the
		 * segment sails through every check below. Two different URLs then
		 * name the same screen, which is an equivalence a log or a cache keyed
		 * on the raw path does not share.
		 *
		 * The rule everywhere else in this codebase is refuse, do not correct
		 * — a request carrying a control character is not a typo.
		 */
		if ( self::has_control_characters( $route ) || self::has_control_characters( $object_segment ) ) {
			return null;
		}

		$route = '' === trim( $route ) ? self::ROUTE_DASHBOARD : trim( $route );

		if ( ! self::is_safe_segment( $route ) || ! in_array( $route, self::routes(), true ) ) {
			return null;
		}

		$object_segment = trim( $object_segment );

		if ( '' === $object_segment ) {
			return new self( $route, 0 );
		}

		// Authentication screens are never object-scoped. Treating /signup/7/
		// as the signup screen would create a second canonical URL for a public
		// form and let arbitrary ids leak into access logs beside it.
		if ( in_array( $route, self::public_routes(), true ) ) {
			return null;
		}

		if ( ! self::is_safe_segment( $object_segment ) ) {
			return null;
		}

		// Object segments are ids. Anything else is somebody probing.
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,17}$/', $object_segment ) ) {
			return null;
		}

		return new self( $route, (int) $object_segment );
	}

	/**
	 * Whether this is the dashboard.
	 *
	 * @return bool
	 */
	public function is_dashboard(): bool {
		return self::ROUTE_DASHBOARD === $this->route && 0 === $this->object_id;
	}

	/**
	 * Whether this request names a specific object.
	 *
	 * @return bool
	 */
	public function has_object(): bool {
		return $this->object_id > 0;
	}

	/**
	 * Routes that may render without an authenticated session.
	 *
	 * @return array<int, string>
	 */
	public static function public_routes(): array {
		return array(
			self::ROUTE_LOGIN,
			self::ROUTE_SIGNUP,
			self::ROUTE_FORGOT_PASSWORD,
			self::ROUTE_SET_PASSWORD,
			self::ROUTE_CONFIRM_EMAIL,
		);
	}

	/**
	 * Whether the request is an anonymous account surface.
	 *
	 * @return bool
	 */
	public function is_public(): bool {
		return in_array( $this->route, self::public_routes(), true );
	}

	/**
	 * The template file this request resolves to, without a path.
	 *
	 * @return string
	 */
	public function template(): string {
		if ( $this->has_object() ) {
			return $this->route . '-detail.php';
		}

		return $this->route . '.php';
	}

	/**
	 * Whether a raw segment carries anything below printable ASCII.
	 *
	 * @param string $segment Raw segment, untrimmed.
	 * @return bool
	 */
	private static function has_control_characters( string $segment ): bool {
		return 1 === preg_match( '/[\x00-\x1F\x7F]/', $segment );
	}

	/**
	 * Whether a raw segment is usable at all.
	 *
	 * Rejects separators, control characters, traversal and anything long
	 * enough to be a payload rather than a name.
	 *
	 * @param string $segment Raw segment.
	 * @return bool
	 */
	private static function is_safe_segment( string $segment ): bool {
		if ( '' === $segment || strlen( $segment ) > self::MAX_SEGMENT_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z0-9-]+$/i', $segment );
	}
}
