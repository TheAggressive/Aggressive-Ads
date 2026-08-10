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
	 * A route with no screen yet renders the shell, not the theme's 404.
	 *
	 * @return void
	 */
	public function test_a_route_without_a_screen_renders_the_shell(): void {
		wp_set_current_user( $this->advertiser );

		$this->go_to( home_url( '/advertiser/help/' ) );

		$template = apply_filters( 'template_include', 'theme-template.php' );

		$this->assertStringContainsString( 'placeholder.php', $template );
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
