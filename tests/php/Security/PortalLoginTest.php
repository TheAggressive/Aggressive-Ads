<?php
/**
 * Signing in on the portal rather than on wp-login.php.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Login_Actions;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The gate, the sign-in screen and the limits around it.
 *
 * The handler itself exits, so the browser suite drives the real form. What is
 * asserted here is everything decided before and around it: where an
 * unauthenticated caller is sent, which screen answers, and that failures are
 * indistinguishable from one another.
 */
final class PortalLoginTest extends WP_UnitTestCase {

	/**
	 * The router under test.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Redirects captured instead of performed.
	 *
	 * @var array<int, string>
	 */
	private array $redirects = array();

	/**
	 * Sets up roles, permalinks and redirect capture.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->router    = Plugin::instance()->container()->get( Router::class );
		$this->redirects = array();

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		$this->set_permalink_structure( '/%postname%/' );
		$this->router->register_rules();

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup: the rules must exist in this process before go_to() can resolve one.
		flush_rewrite_rules( false );
	}

	/**
	 * Restores permalinks and removes the capture.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Records a redirect and cancels it, so the caller does not exit.
	 *
	 * @param string $location Redirect target.
	 * @return false
	 */
	public function capture_redirect( string $location ): bool {
		$this->redirects[] = $location;

		return false;
	}

	/**
	 * **A signed-out visitor is sent to our form, never to wp-login.php.**
	 *
	 * The gate used to call auth_redirect(). An advertiser bounced to the
	 * WordPress login screen has, as far as they can tell, been thrown off the
	 * site they were using.
	 *
	 * @return void
	 */
	public function test_a_signed_out_visitor_is_sent_to_the_portal_form(): void {
		wp_set_current_user( 0 );

		$this->go_to( home_url( '/advertiser/campaigns/' ) );
		$this->router->gate();

		$this->assertCount( 1, $this->redirects );
		$this->assertStringContainsString( '/advertiser/login/', $this->redirects[0] );
		$this->assertStringNotContainsString( 'wp-login.php', $this->redirects[0] );
	}

	/**
	 * The destination is carried, so signing in lands where they were going.
	 *
	 * @return void
	 */
	public function test_the_destination_is_carried_through_the_redirect(): void {
		wp_set_current_user( 0 );

		$this->go_to( home_url( '/advertiser/campaigns/' ) );
		$this->router->gate();

		$this->assertStringContainsString(
			rawurlencode( home_url( '/advertiser/campaigns/' ) ),
			$this->redirects[0]
		);
	}

	/**
	 * The sign-in screen renders for someone with no session.
	 *
	 * The capability check in template() used to run first, so every signed-out
	 * caller got the 403 screen — including the one the gate had just sent here
	 * to sign in.
	 *
	 * @return void
	 */
	public function test_the_login_route_renders_without_a_session(): void {
		wp_set_current_user( 0 );

		$this->go_to( home_url( '/advertiser/login/' ) );

		$template = apply_filters( 'template_include', 'theme-template.php' );

		$this->assertStringContainsString( 'templates/portal/login.php', $template );
		$this->assertStringNotContainsString( '403.php', $template );
		$this->assertFileExists( $template );
	}

	/**
	 * Someone already signed in is moved on rather than shown a login form.
	 *
	 * @return void
	 */
	public function test_a_signed_in_visitor_is_moved_off_the_login_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$this->go_to( home_url( '/advertiser/login/' ) );
		$this->router->gate();

		$this->assertCount( 1, $this->redirects );
		$this->assertStringContainsString( '/advertiser/', $this->redirects[0] );
		$this->assertStringNotContainsString( 'login', $this->redirects[0] );
	}

	/**
	 * **Every failure says the same thing.**
	 *
	 * Core distinguishes an unknown username from a wrong password, and that
	 * distinction turns any login form into a way of discovering which accounts
	 * exist. The portal form collapses both into one sentence.
	 *
	 * @return void
	 */
	public function test_every_failure_reads_identically(): void {
		$messages = array();

		foreach ( array( 'failed', 'invalid_username', 'incorrect_password', 'invalid_email', '' ) as $code ) {
			$messages[] = Login_Actions::notice_message( $code );
		}

		$this->assertCount( 1, array_unique( $messages ), 'Two failure codes read differently, which enumerates accounts.' );

		// Rate limiting is the one failure worth distinguishing: it tells the
		// person to wait rather than to keep guessing, and reveals nothing
		// about whether the account exists.
		$this->assertNotSame( $messages[0], Login_Actions::notice_message( 'rate_limited' ) );
	}

	/**
	 * Only the two codes this screen understands are ever displayed.
	 *
	 * @return void
	 */
	public function test_the_notice_is_allowlisted(): void {
		$_GET['aggr_login'] = 'failed';
		$this->assertSame( 'failed', Login_Actions::request_notice() );

		$_GET['aggr_login'] = '<script>alert(1)</script>';
		$this->assertSame( '', Login_Actions::request_notice() );

		unset( $_GET['aggr_login'] );
	}

	/**
	 * Sign-in attempts are bounded per client, not per user.
	 *
	 * A failed attempt has no user to count against, so a limiter keyed on user
	 * id counts nothing at all during exactly the attack it exists to bound.
	 *
	 * @return void
	 */
	public function test_sign_in_attempts_are_bounded_for_anonymous_clients(): void {
		$limiter = Plugin::instance()->container()->get( Rate_Limiter::class );
		$subject = Rate_Limiter::client_subject();
		$limit   = Rate_Limiter::limit_for( Rate_Limiter::ACTION_LOGIN );

		$this->assertLessThan( PHP_INT_MAX, $limit, 'The login action must declare a limit.' );

		for ( $attempt = 0; $attempt < $limit; $attempt++ ) {
			$this->assertTrue( $limiter->attempt_for( Rate_Limiter::ACTION_LOGIN, $subject ) );
		}

		$this->assertWPError( $limiter->attempt_for( Rate_Limiter::ACTION_LOGIN, $subject ) );

		// The old signature counts nothing for a caller with no user id, which
		// is why the anonymous form had to exist.
		$this->assertTrue( $limiter->attempt( Rate_Limiter::ACTION_LOGIN, 0 ) );
	}

	/**
	 * The client identifier is hashed, so no address is stored.
	 *
	 * A rate-limit counter is not a visitor log, and a plugin that quietly
	 * accumulates addresses is a data-protection problem nobody asked for.
	 *
	 * @return void
	 */
	public function test_the_client_identifier_stores_no_address(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture: this sets the value the subject under test reads, it does not consume one.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		$subject = Rate_Limiter::client_subject();

		$this->assertStringNotContainsString( '203.0.113.42', $subject );
		$this->assertSame( $subject, Rate_Limiter::client_subject(), 'The identifier must be stable within a client.' );

		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture: this sets the value the subject under test reads, it does not consume one.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$this->assertNotSame( $subject, Rate_Limiter::client_subject(), 'Two clients must not share a counter.' );

		// Anything that is not an address shares one bucket rather than minting
		// a fresh counter per junk value.
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture: this sets the value the subject under test reads, it does not consume one.
		$_SERVER['REMOTE_ADDR'] = 'not-an-address';
		$first                  = Rate_Limiter::client_subject();
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture: this sets the value the subject under test reads, it does not consume one.
		$_SERVER['REMOTE_ADDR'] = 'also-not-an-address';

		$this->assertSame( $first, Rate_Limiter::client_subject() );
	}

	/**
	 * The login route is part of the grammar, so it cannot 404.
	 *
	 * @return void
	 */
	public function test_the_login_route_is_declared(): void {
		$this->assertContains( Request::ROUTE_LOGIN, Request::routes() );
		$this->assertNotNull( Request::from( Request::ROUTE_LOGIN ) );
	}
}
