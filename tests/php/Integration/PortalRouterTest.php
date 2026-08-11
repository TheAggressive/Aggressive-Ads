<?php
/**
 * The portal route against real WordPress.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Assets\Assets;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Router;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Security\Roles;
use WP_UnitTestCase;

/**
 * Rewrite rules, the auth gate and template resolution.
 *
 * The grammar is unit-tested without a bootstrap; this is about what WordPress
 * actually does with it.
 */
final class PortalRouterTest extends WP_UnitTestCase {

	/**
	 * The router under test.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * An advertiser.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Sets up roles and pretty permalinks.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->router     = Plugin::instance()->container()->get( Router::class );
		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		/*
		 * A fresh style registry per test.
		 *
		 * $wp_styles is a global that survives between tests in one process, so
		 * "is the stylesheet enqueued?" otherwise answers yes for any test that
		 * runs after one which enqueued it — including the test asserting it
		 * does *not* load off-portal, which would then be reporting on its
		 * predecessor.
		 */
		$GLOBALS['wp_styles'] = null;

		// Rewrite rules only exist under pretty permalinks.
		$this->set_permalink_structure( '/%postname%/' );
		$this->router->register_rules();

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup: the rules have to exist in this process before go_to() can resolve one.
		flush_rewrite_rules( false );
	}

	/**
	 * Restores permalinks.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * All three rules are registered.
	 *
	 * @return void
	 */
	public function test_the_rewrite_rules_are_registered(): void {
		$rules = get_option( 'rewrite_rules' );

		$this->assertIsArray( $rules );

		$ours = array_filter(
			$rules,
			static fn ( string $target ): bool => str_contains( $target, Router::QUERY_PORTAL )
		);

		$this->assertCount( 3, $ours, 'Expected exactly three portal rules.' );
	}

	/**
	 * The query vars are readable.
	 *
	 * @return void
	 */
	public function test_the_query_vars_are_registered(): void {
		$vars = apply_filters( 'query_vars', array() );

		$this->assertContains( Router::QUERY_PORTAL, $vars );
		$this->assertContains( Router::QUERY_ROUTE, $vars );
		$this->assertContains( Router::QUERY_OBJECT, $vars );
	}

	/**
	 * **A portal request is not a 404.**
	 *
	 * Left alone, core resolves the request as a 404 before template_include
	 * runs, and the portal renders inside the theme's 404 template with a 404
	 * status code. Search engines and uptime monitors both notice.
	 *
	 * The `pre_handle_404` filter is what this actually pins: removing it
	 * fails this test, while removing the explicit is_404 assignment does not.
	 *
	 * @return void
	 */
	public function test_a_portal_request_is_not_a_404(): void {
		wp_set_current_user( $this->advertiser );

		$this->go_to( home_url( '/advertiser/' ) );

		$this->assertFalse( is_404() );
		$this->assertNotNull( $this->router->request() );
		$this->assertTrue( $this->router->request()->is_dashboard() );
	}

	/**
	 * A route with an object parses into both.
	 *
	 * @return void
	 */
	public function test_a_route_with_an_object_parses(): void {
		wp_set_current_user( $this->advertiser );

		$this->go_to( home_url( '/advertiser/campaigns/412/' ) );

		$request = $this->router->request();

		$this->assertNotNull( $request );
		$this->assertSame( Request::ROUTE_CAMPAIGNS, $request->route );
		$this->assertSame( 412, $request->object_id );
	}

	/**
	 * An undeclared route stays a 404 rather than rendering the shell.
	 *
	 * @return void
	 */
	public function test_an_undeclared_route_stays_a_404(): void {
		wp_set_current_user( $this->advertiser );

		$this->go_to( home_url( '/advertiser/not-a-screen/' ) );

		$this->assertNull( $this->router->request() );

		/*
		 * The assertion above is not enough on its own, and for a while it was
		 * the only one.
		 *
		 * The rewrite rule has already consumed the path by this point, so the
		 * main query carries no post selection and resolves as the home query.
		 * A router that merely declines to claim the request leaves WordPress
		 * rendering the front page — at 200 — for /advertiser/not-a-screen/.
		 * `request() === null` is true in both the broken and the fixed
		 * version, which is exactly why it passed while the behaviour was
		 * wrong.
		 */
		$this->assertTrue( is_404(), 'A portal URL naming no route must resolve as a 404.' );
	}

	/**
	 * An object segment that is not an id is a 404, not the list screen.
	 *
	 * /advertiser/campaigns/abc/ means "campaign abc". Answering it with the
	 * campaign list, at 200, is a soft 404: every client that checks status
	 * codes — crawlers, uptime monitors, the browser's own history — is told
	 * the address was fine.
	 *
	 * @return void
	 */
	public function test_a_malformed_object_segment_is_a_404(): void {
		wp_set_current_user( $this->advertiser );

		foreach ( array( 'abc', '0', '-1', '1.5' ) as $segment ) {
			$this->go_to( home_url( '/advertiser/campaigns/' . $segment . '/' ) );

			$this->assertNull( $this->router->request(), "Segment {$segment} should not parse." );
			$this->assertTrue( is_404(), "Segment {$segment} should resolve as a 404." );

			$template = apply_filters( 'template_include', 'theme-template.php' );

			$this->assertStringContainsString(
				'templates/portal/404.php',
				$template,
				"Segment {$segment} should render the portal's not-found screen."
			);
		}
	}

	/**
	 * The 404 status code is actually sent, not merely implied by is_404().
	 *
	 * Core decides the status in handle_404(), which runs before
	 * template_redirect and sends 200 because the home query it fell back to
	 * did find posts. is_404() being true and the response being 200 is the
	 * exact state this is here to rule out — the two are set in different
	 * places and only one of them is what a client sees.
	 *
	 * Captured through the `status_header` filter, which is the last thing
	 * status_header() consults before writing the header.
	 *
	 * @return void
	 */
	public function test_the_404_status_code_is_sent(): void {
		wp_set_current_user( $this->advertiser );

		$codes = array();

		add_filter(
			'status_header',
			static function ( $header, $code ) use ( &$codes ) {
				$codes[] = (int) $code;

				return $header;
			},
			10,
			2
		);

		$this->go_to( home_url( '/advertiser/campaigns/abc/' ) );
		$this->router->gate();

		$this->assertContains( 404, $codes, 'gate() must send a 404 for a portal URL that names nothing.' );
	}

	/**
	 * A well-formed id still resolves, so the check above is not just refusing.
	 *
	 * @return void
	 */
	public function test_a_well_formed_object_segment_still_resolves(): void {
		wp_set_current_user( $this->advertiser );

		$this->go_to( home_url( '/advertiser/campaigns/42/' ) );

		$request = $this->router->request();

		$this->assertNotNull( $request );
		$this->assertSame( 42, $request->object_id );
		$this->assertFalse( is_404() );
	}

	/**
	 * The template comes from the plugin, not the theme.
	 *
	 * @return void
	 */
	public function test_the_portal_template_is_ours(): void {
		wp_set_current_user( $this->advertiser );

		$this->go_to( home_url( '/advertiser/' ) );

		$template = apply_filters( 'template_include', 'theme-template.php' );

		$this->assertStringContainsString( 'templates/portal/dashboard.php', $template );
		$this->assertFileExists( $template );
	}

	/**
	 * Every declared route has its own screen.
	 *
	 * This used to name `help` as the example of an unbuilt route, so building
	 * that screen broke it — a test that fails as a side effect of the work
	 * going well teaches people to edit tests rather than read them. What is
	 * worth protecting is the opposite claim: that no route is silently
	 * resolving to the placeholder because somebody added it to the allowlist
	 * and forgot the template.
	 *
	 * @return void
	 */
	public function test_every_declared_route_has_its_own_screen(): void {
		wp_set_current_user( $this->advertiser );

		foreach ( Request::routes() as $route ) {
			$this->go_to( home_url( '/advertiser/' . ( Request::ROUTE_DASHBOARD === $route ? '' : $route . '/' ) ) );

			$template = apply_filters( 'template_include', 'theme-template.php' );

			$this->assertStringContainsString( 'templates/portal/' . $route . '.php', $template, "Route {$route} has no screen of its own." );
			$this->assertStringNotContainsString( 'placeholder.php', $template, "Route {$route} is falling through to the placeholder." );
		}
	}

	/**
	 * The placeholder is still there for the next route somebody declares.
	 *
	 * Router::template() falls back to it when a route's template is missing,
	 * so a route added to the allowlist before its screen exists renders the
	 * portal shell rather than a fatal. Kept deliberately, and asserted so it
	 * is not deleted as unused — nothing reaches it today precisely because
	 * every route above is built.
	 *
	 * @return void
	 */
	public function test_the_placeholder_fallback_still_exists(): void {
		$this->assertFileExists( LAAO_ADS_PLUGIN_DIR . 'templates/portal/placeholder.php' );
		$this->assertStringContainsString(
			"locate( 'placeholder.php' )",
			(string) file_get_contents( LAAO_ADS_PLUGIN_DIR . 'inc/Portal/class-router.php' ),
			'The router no longer falls back to the placeholder.'
		);
	}

	/**
	 * **A signed-in user without the capability gets the 403 screen.**
	 *
	 * @return void
	 */
	public function test_a_user_without_access_gets_the_403_template(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->go_to( home_url( '/advertiser/' ) );

		$template = apply_filters( 'template_include', 'theme-template.php' );

		$this->assertStringContainsString( '403.php', $template );
	}

	/**
	 * The template filter leaves every other request alone.
	 *
	 * @return void
	 */
	public function test_non_portal_requests_keep_their_template(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertNull( $this->router->request() );
		$this->assertSame( 'theme-template.php', apply_filters( 'template_include', 'theme-template.php' ) );
	}

	/**
	 * The portal is never indexed. It is one advertiser's private working
	 * area.
	 *
	 * @return void
	 */
	public function test_the_portal_is_not_indexed(): void {
		wp_set_current_user( $this->advertiser );

		$this->go_to( home_url( '/advertiser/' ) );
		$this->router->gate();

		$robots = apply_filters( 'wp_robots', array() );

		$this->assertArrayHasKey( 'noindex', $robots );
	}

	/**
	 * The stylesheet loads on the portal.
	 *
	 * @return void
	 */
	public function test_the_stylesheet_loads_on_the_portal(): void {
		wp_set_current_user( $this->advertiser );

		$this->go_to( home_url( '/advertiser/' ) );

		Plugin::instance()->container()->get( Assets::class )->enqueue();

		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ) );
	}

	/**
	 * **And nowhere else.**
	 *
	 * A plugin that enqueues its stylesheet everywhere shows up in somebody
	 * else's performance budget, and eventually in somebody else's layout bug.
	 *
	 * @return void
	 */
	public function test_the_stylesheet_does_not_load_elsewhere(): void {
		$this->go_to( home_url( '/' ) );

		Plugin::instance()->container()->get( Assets::class )->enqueue();

		$this->assertFalse( wp_style_is( Assets::HANDLE, 'enqueued' ) );
	}
}
