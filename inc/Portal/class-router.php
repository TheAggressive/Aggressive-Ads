<?php
/**
 * Putting the portal on a URL.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Portal;

use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Security\Capabilities;
use WP_Query;

/**
 * Rewrite rules, the auth gate, and template resolution.
 *
 * A route the plugin owns rather than a Page with a block: the portal is a
 * multi-screen area, and a page-plus-block design makes every screen a row an
 * editor can rename, trash or paste a pattern into. See
 * docs/adr/0005-portal-route-via-rewrite-rule.md.
 */
final class Router implements Service {

	/**
	 * Bumped whenever the rules below change. That bump is the entire
	 * deployment procedure for routing: without it the old rules stay in the
	 * database and the portal 404s in a way that looks like a broken deploy
	 * rather than a stale cache.
	 */
	public const REWRITE_VERSION = 1;

	public const QUERY_PORTAL = 'laao_ads_portal';
	public const QUERY_ROUTE  = 'laao_ads_route';
	public const QUERY_OBJECT = 'laao_ads_object';

	public const OPTION_REWRITE_VERSION = 'laao_ads_rewrite_version';

	/**
	 * The parsed request, once one has been recognised.
	 *
	 * @var Request|null
	 */
	private ?Request $request = null;

	/**
	 * Whether the URL landed on a portal rewrite rule at all.
	 *
	 * Distinct from $request, and the distinction is the whole point: a URL can
	 * match the rule and still name nothing — /advertiser/campaigns/abc/. That
	 * is not "not a portal request", it is a portal request for something that
	 * does not exist, and the two deserve different answers.
	 *
	 * @var bool
	 */
	private bool $is_portal_url = false;

	/**
	 * Attaches everything.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_query', array( $this, 'claim_request' ) );
		add_action( 'template_redirect', array( $this, 'gate' ) );
		add_filter( 'template_include', array( $this, 'template' ) );
	}

	/**
	 * Registers the three rules.
	 *
	 * @return void
	 */
	public function register_rules(): void {
		$base = preg_quote( Routes::base(), '/' );

		add_rewrite_rule(
			'^' . $base . '/?$',
			'index.php?' . self::QUERY_PORTAL . '=1&' . self::QUERY_ROUTE . '=' . Request::ROUTE_DASHBOARD,
			'top'
		);

		add_rewrite_rule(
			'^' . $base . '/([^/]+)/?$',
			'index.php?' . self::QUERY_PORTAL . '=1&' . self::QUERY_ROUTE . '=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^' . $base . '/([^/]+)/([^/]+)/?$',
			'index.php?' . self::QUERY_PORTAL . '=1&' . self::QUERY_ROUTE . '=$matches[1]&' . self::QUERY_OBJECT . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Makes the query vars readable.
	 *
	 * @param array<int, string> $vars Registered query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_PORTAL;
		$vars[] = self::QUERY_ROUTE;
		$vars[] = self::QUERY_OBJECT;

		return $vars;
	}

	/**
	 * Flushes exactly once, when the declared version moves.
	 *
	 * Never on every request: flush_rewrite_rules() regenerates every rule on
	 * the site and rewrites .htaccess, and calling it per request is a
	 * well-known way to make a site inexplicably slow.
	 *
	 * @return void
	 */
	public function maybe_flush(): void {
		if ( (int) get_option( self::OPTION_REWRITE_VERSION, 0 ) === self::REWRITE_VERSION ) {
			return;
		}

		// The soft form: skips the .htaccess write, which we do not need.
		//
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Version-gated, so this runs once per deploy that changes a route, never per request. VIP bans it because it is normally called on init unconditionally; that is the misuse, and the guard above is what prevents it. On VIP the flush would be an operational step, but this plugin ships to ordinary hosting where a plugin owning rewrite rules must flush its own. See docs/adr/0005-portal-route-via-rewrite-rule.md.
		flush_rewrite_rules( false );

		update_option( self::OPTION_REWRITE_VERSION, self::REWRITE_VERSION, true );
	}

	/**
	 * Recognises a portal request and stops WordPress treating it as a 404.
	 *
	 * Left alone, core resolves the request as a 404 before template_include
	 * runs, and the portal renders inside the theme's 404 template **with a 404
	 * status code**. Search engines and uptime monitors both notice.
	 *
	 * **`pre_handle_404` is what actually prevents it** — it short-circuits
	 * handle_404() so core never sets the flag in the first place. Verified by
	 * removing each in turn: without the filter the test fails, without the
	 * explicit flags it does not. The flags below are therefore belt and
	 * braces against a later code path or another plugin setting them, not the
	 * mechanism. An earlier version of this comment claimed they were, which
	 * is the kind of wrong that costs an afternoon.
	 *
	 * @param WP_Query $query The main query.
	 * @return void
	 */
	public function claim_request( WP_Query $query ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}

		/*
		 * Cleared first, because the main query *is* the current request.
		 *
		 * Returning early without this leaves a previously recognised portal
		 * request in place, and everything downstream believes it: the
		 * stylesheet enqueues on whatever page came next, and template_include
		 * offers a portal template to a request that is not one. Rare in a web
		 * request, ordinary under WP-CLI, cron, and any test process that
		 * visits two URLs.
		 */
		$this->request       = null;
		$this->is_portal_url = false;

		if ( '1' !== (string) $query->get( self::QUERY_PORTAL ) ) {
			return;
		}

		$this->is_portal_url = true;

		$this->request = Request::from(
			(string) $query->get( self::QUERY_ROUTE ),
			(string) $query->get( self::QUERY_OBJECT )
		);

		/*
		 * A portal URL naming nothing is a 404, and has to be made one.
		 *
		 * Returning here was not enough: the rewrite rule had already consumed
		 * the path, so the main query carried no post selection at all and
		 * resolved as the home query. WordPress then rendered the front page,
		 * at 200, for /advertiser/campaigns/abc/. set_404() here plus the
		 * status header in gate() — which runs at template_redirect, after
		 * core's own handle_404() has had its say — is what makes the answer
		 * match the URL.
		 */
		if ( null === $this->request ) {
			$query->set_404();

			return;
		}

		$query->is_home                     = false;
		$query->is_404                      = false;
		$query->is_singular                 = false;
		$query->is_archive                  = false;
		$query->query_vars['no_found_rows'] = true;

		add_filter( 'pre_handle_404', '__return_true' );
	}

	/**
	 * The recognised request, if this is one.
	 *
	 * @return Request|null
	 */
	public function request(): ?Request {
		return $this->request;
	}

	/**
	 * Requires a logged-in user who may reach the portal.
	 *
	 * Core's auth_redirect() is used rather than a hand-rolled login redirect.
	 * It handles the redirect_to round trip, SSL and the interim-login case,
	 * and we would get at least one of those wrong.
	 *
	 * @return void
	 */
	public function gate(): void {
		if ( $this->is_portal_url && null === $this->request ) {
			status_header( 404 );
			nocache_headers();

			return;
		}

		if ( null === $this->request ) {
			return;
		}

		// A portal screen is one advertiser's private working area. Nothing
		// here belongs in an index.
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		if ( ! is_user_logged_in() ) {
			auth_redirect();

			return;
		}

		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			status_header( 403 );
			nocache_headers();
		}
	}

	/**
	 * Resolves the template, taking over from the theme entirely.
	 *
	 * @param string $template The template WordPress chose.
	 * @return string
	 */
	public function template( string $template ): string {
		if ( $this->is_portal_url && null === $this->request ) {
			return $this->locate( '404.php' );
		}

		if ( null === $this->request ) {
			return $template;
		}

		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return $this->locate( '403.php' );
		}

		$candidate = $this->locate( $this->request->template() );

		if ( '' !== $candidate ) {
			return $candidate;
		}

		// A route with no screen yet still renders the shell rather than the
		// theme's 404, which would be a different design on every site.
		return $this->locate( 'placeholder.php' );
	}

	/**
	 * Finds a portal template file.
	 *
	 * @param string $file Template filename.
	 * @return string Empty when it does not exist.
	 */
	private function locate( string $file ): string {
		$path = LAAO_ADS_PLUGIN_DIR . 'templates/portal/' . $file;

		return is_file( $path ) ? $path : '';
	}
}
