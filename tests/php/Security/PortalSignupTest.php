<?php
/**
 * Secure public advertiser registration.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Portal\Signup_Actions;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Advertiser_Registration;
use WP_UnitTestCase;
use WP_User;

/**
 * The public route, account transaction, activation and abuse controls.
 */
final class PortalSignupTest extends WP_UnitTestCase {

	/**
	 * Registration workflow.
	 *
	 * @var Advertiser_Registration
	 */
	private Advertiser_Registration $registration;

	/**
	 * Captured transactional messages.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	/**
	 * Whether the intercepted mail transport succeeds.
	 *
	 * @var bool
	 */
	private bool $mail_succeeds = true;

	/**
	 * Enables registration and intercepts outbound mail.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'users_can_register', 1 );
		$this->registration  = Plugin::instance()->container()->get( Advertiser_Registration::class );
		$this->mail          = array();
		$this->mail_succeeds = true;

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Restores global policy and hooks.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		delete_option( 'users_can_register' );
		delete_option( Settings::OPTION );
		unset( $_GET['aggr_signup'], $_GET['invite'] );
		$_POST = array();
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Captures one message without involving an external mail transport.
	 *
	 * @param null|bool            $short_circuit Earlier short-circuit value.
	 * @param array<string, mixed> $mail          Normalized mail arguments.
	 * @return bool
	 */
	public function capture_mail( null|bool $short_circuit, array $mail ): bool {
		$this->mail[] = $mail;

		return $this->mail_succeeds;
	}

	/**
	 * A canonical valid request fixture.
	 *
	 * @param string $email Email override.
	 * @return array<string, string>
	 */
	private function fields( string $email = 'new-advertiser@example.test' ): array {
		return array(
			'first_name'        => 'Amina',
			'last_name'         => 'Rivera',
			'organization_name' => 'Copper State Arts',
			'email'             => $email,
		);
	}

	/**
	 * Signup is wired for anonymous requests and represented in the grammar.
	 *
	 * @return void
	 */
	public function test_signup_is_a_wired_public_route(): void {
		$actions = Plugin::instance()->container()->get( Signup_Actions::class );

		$this->assertSame( 10, has_action( 'admin_post_nopriv_' . Signup_Actions::SIGNUP_ACTION, array( $actions, 'handle_signup' ) ) );
		$this->assertContains( Request::ROUTE_SIGNUP, Request::routes() );
		$this->assertContains( Request::ROUTE_SIGNUP, Request::public_routes() );
	}

	/**
	 * A missing nonce dies before rate limiting or persistence runs.
	 *
	 * @return void
	 */
	public function test_signup_handler_rejects_a_missing_nonce(): void {
		$_POST = array(
			'email' => 'csrf@example.test',
		);

		$this->expectException( 'WPDieException' );
		Plugin::instance()->container()->get( Signup_Actions::class )->handle_signup();
	}

	/**
	 * A nonce for a different public action cannot authorize signup.
	 *
	 * @return void
	 */
	public function test_signup_handler_rejects_a_forged_nonce(): void {
		$_POST = array(
			'_wpnonce' => wp_create_nonce( 'some_other_public_action' ),
			'email'    => 'csrf@example.test',
		);

		$this->expectException( 'WPDieException' );
		Plugin::instance()->container()->get( Signup_Actions::class )->handle_signup();
	}

	/**
	 * The route renders without a session and contains no password collector.
	 *
	 * @return void
	 */
	public function test_signup_renders_a_nonce_protected_passwordless_form(): void {
		wp_set_current_user( 0 );

		$router = Plugin::instance()->container()->get( Router::class );
		$this->set_permalink_structure( '/%postname%/' );
		$router->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup needs the route in this process.
		flush_rewrite_rules( false );
		$this->go_to( home_url( '/advertiser/signup/' ) );

		$template = apply_filters( 'template_include', 'theme-template.php' );

		$this->assertStringContainsString( 'templates/portal/signup.php', $template );
		$this->assertFileExists( $template );

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/screens/signup.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'name="company_website"', $html );
		$this->assertStringNotContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'autocomplete="email"', $html );
	}

	/**
	 * A registration creates one active org owner and one usable portal user.
	 *
	 * @return void
	 */
	public function test_registration_creates_the_user_org_and_activation_email(): void {
		$result = $this->registration->register( $this->fields() );

		$this->assertTrue( $result );

		$user = get_user_by( 'email', 'new-advertiser@example.test' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertContains( Roles::ADVERTISER, $user->roles );
		$this->assertNotSame( 'new-advertiser', $user->user_login, 'The public email local-part must not become an enumerable login.' );

		$orgs = ( new Org_Repository() )->org_ids_for_user( $user->ID );
		$this->assertCount( 1, $orgs );
		$this->assertSame( 'COPPER STATE ARTS', get_the_title( $orgs[0] ) );
		$this->assertSame( Org_Repository::STATE_ACTIVE, get_post_meta( $orgs[0], Org_Repository::META_ORG_STATE, true ) );
		$this->assertSame( $user->ID, (int) get_post_meta( $orgs[0], Org_Repository::META_OWNER_USER, true ) );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'new-advertiser@example.test', $this->mail[0]['to'] );
		$this->assertStringContainsString( '/advertiser/set-password/', (string) $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'wp-login.php', (string) $this->mail[0]['message'] );
		$this->assertStringContainsString( '/advertiser/login/', (string) $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'user_pass', (string) $this->mail[0]['message'] );

		$events = ( new Audit_Repository() )->for_object( 'user', $user->ID, $orgs[0] );
		$this->assertSame( 'advertiser.registered', $events[0]['event'] ?? '' );
	}

	/**
	 * Existing addresses are indistinguishable and cannot be mail-bombed.
	 *
	 * @return void
	 */
	public function test_an_existing_email_returns_success_without_creating_or_mailing(): void {
		self::factory()->user->create( array( 'user_email' => 'existing@example.test' ) );

		$this->assertTrue( $this->registration->register( $this->fields( 'existing@example.test' ) ) );
		$this->assertCount( 0, $this->mail );

		$organizations = get_posts(
			array(
				'post_type'      => Post_Types::ORGANIZATION,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$this->assertCount( 0, $organizations );
	}

	/**
	 * Invalid input never allocates records or sends mail.
	 *
	 * @return void
	 */
	public function test_invalid_input_fails_before_any_write(): void {
		$fields               = $this->fields( 'not-an-email' );
		$fields['first_name'] = '';
		$result               = $this->registration->register( $fields );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_invalid_registration', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'not-an-email' ) );
		$this->assertCount( 0, $this->mail );
	}

	/**
	 * Server-side limits hold even when a client ignores HTML maxlength.
	 *
	 * @return void
	 */
	public function test_overlong_input_is_rejected_before_persistence(): void {
		$fields                      = $this->fields();
		$fields['organization_name'] = str_repeat( 'a', Advertiser_Registration::MAX_ORG_NAME + 1 );

		$result = $this->registration->register( $fields );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_invalid_registration', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'new-advertiser@example.test' ) );
	}

	/**
	 * A mail failure compensates both durable writes and leaves no portal user.
	 *
	 * @return void
	 */
	public function test_activation_mail_failure_rolls_back_user_and_org(): void {
		$this->mail_succeeds = false;

		$result = $this->registration->register( $this->fields( 'mail-fails@example.test' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_registration_failed', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'mail-fails@example.test' ) );
		$this->assertCount( 1, $this->mail );

		$organizations = get_posts(
			array(
				'post_type'      => Post_Types::ORGANIZATION,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$this->assertCount( 0, $organizations );
	}

	/**
	 * Public registration must be explicitly enabled by site policy.
	 *
	 * @return void
	 */
	public function test_registration_fails_closed_when_site_signup_is_disabled(): void {
		update_option( 'users_can_register', 0 );

		$result = $this->registration->register( $this->fields() );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_registration_closed', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'new-advertiser@example.test' ) );
	}

	/**
	 * The public signup module is a kill-switch, independent of WordPress's switch.
	 *
	 * @return void
	 */
	public function test_registration_fails_closed_when_the_signup_module_is_off(): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();
		$document['modules'][ Settings_Schema::MODULE_PUBLIC_SIGNUP ] = false;

		$this->assertTrue( $settings->save( $document ) );

		$result = $this->registration->register( $this->fields() );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_registration_closed', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'new-advertiser@example.test' ) );
	}

	/**
	 * An invitation is membership, not open registration, so the closed check
	 * does not apply. An unknown token still fails later for a different reason.
	 *
	 * @return void
	 */
	public function test_an_invitation_is_not_closed_when_the_signup_module_is_off(): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();
		$document['modules'][ Settings_Schema::MODULE_PUBLIC_SIGNUP ] = false;

		$this->assertTrue( $settings->save( $document ) );

		$fields                 = $this->fields();
		$fields['invite_token'] = str_repeat( 'A', 43 );
		$result                 = $this->registration->register( $fields );

		$this->assertWPError( $result );
		$this->assertNotSame( 'aggr_registration_closed', $result->get_error_code() );
	}

	/**
	 * Off means the route is a 404, not a closed form.
	 *
	 * @return void
	 */
	public function test_signup_is_a_404_when_the_module_is_off(): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();
		$document['modules'][ Settings_Schema::MODULE_PUBLIC_SIGNUP ] = false;

		$this->assertTrue( $settings->save( $document ) );

		$router = Plugin::instance()->container()->get( Router::class );
		$this->set_permalink_structure( '/%postname%/' );
		$router->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup needs the route in this process.
		flush_rewrite_rules( false );
		$this->go_to( home_url( '/advertiser/signup/' ) );

		$this->assertTrue( is_404(), 'Public signup off must make /advertiser/signup/ a 404.' );
		$this->assertNull( $router->request() );
	}

	/**
	 * An invitation URL still resolves when public signup is off.
	 *
	 * @return void
	 */
	public function test_an_invitation_still_reaches_signup_when_the_module_is_off(): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();
		$document['modules'][ Settings_Schema::MODULE_PUBLIC_SIGNUP ] = false;

		$this->assertTrue( $settings->save( $document ) );

		$token          = str_repeat( 'A', 43 );
		$_GET['invite'] = $token;
		$router         = Plugin::instance()->container()->get( Router::class );
		$this->set_permalink_structure( '/%postname%/' );
		$router->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup needs the route in this process.
		flush_rewrite_rules( false );
		$this->go_to( home_url( '/advertiser/signup/?invite=' . $token ) );

		$this->assertFalse( is_404() );
		$request = $router->request();
		$this->assertNotNull( $request );
		$this->assertSame( Request::ROUTE_SIGNUP, $request->route );
	}

	/**
	 * Signup attempts have a real anonymous-client limit.
	 *
	 * @return void
	 */
	public function test_signup_attempts_are_bounded_per_client(): void {
		$limiter = Plugin::instance()->container()->get( Rate_Limiter::class );
		$subject = 'signup-security-test-' . wp_generate_uuid4();
		$limit   = Rate_Limiter::limit_for( Rate_Limiter::ACTION_SIGNUP );

		$this->assertLessThan( PHP_INT_MAX, $limit );

		for ( $attempt = 0; $attempt < $limit; ++$attempt ) {
			$this->assertTrue( $limiter->attempt_for( Rate_Limiter::ACTION_SIGNUP, $subject ) );
		}

		$this->assertWPError( $limiter->attempt_for( Rate_Limiter::ACTION_SIGNUP, $subject ) );
	}

	/**
	 * Query-string output is allowlisted and never reflects submitted data.
	 *
	 * @return void
	 */
	public function test_signup_notices_are_allowlisted(): void {
		$_GET['aggr_signup'] = 'sent';
		$this->assertSame( 'sent', Signup_Actions::request_notice() );

		$_GET['aggr_signup'] = '<script>alert(1)</script>';
		$this->assertSame( '', Signup_Actions::request_notice() );
	}

	/**
	 * A signed-in visitor does not sit on the open signup form.
	 *
	 * @return void
	 */
	public function test_a_signed_in_visitor_is_moved_off_open_signup(): void {
		$gate = $this->signed_in_signup_gate( '/advertiser/signup/' );

		$this->assertCount( 1, $gate['redirects'] );
		$this->assertStringContainsString( '/advertiser/', $gate['redirects'][0] );
		$this->assertStringNotContainsString( 'signup', $gate['redirects'][0] );
	}

	/**
	 * An invitation URL stays on signup even when another WordPress session exists.
	 *
	 * @return void
	 */
	public function test_a_signed_in_visitor_keeps_an_invitation_signup(): void {
		$gate = $this->signed_in_signup_gate( '/advertiser/signup/?invite=' . str_repeat( 'A', 43 ) );

		$request = $gate['router']->request();
		$this->assertNotNull( $request );
		$this->assertSame( Request::ROUTE_SIGNUP, $request->route );
		$this->assertSame( array(), $gate['redirects'] );
	}

	/**
	 * Success drops the invite token, so the confirmation notice must keep the screen.
	 *
	 * @return void
	 */
	public function test_a_signed_in_visitor_keeps_the_signup_confirmation(): void {
		$gate = $this->signed_in_signup_gate( '/advertiser/signup/?aggr_signup=sent' );

		$request = $gate['router']->request();
		$this->assertNotNull( $request );
		$this->assertSame( Request::ROUTE_SIGNUP, $request->route );
		$this->assertSame( array(), $gate['redirects'] );
	}

	/**
	 * Drive gate() as a signed-in advertiser and capture redirects.
	 *
	 * @param string $path Portal path including any query string.
	 * @return array{router: Router, redirects: list<string>}
	 */
	private function signed_in_signup_gate( string $path ): array {
		$redirects = array();
		$capture   = static function ( string $location ) use ( &$redirects ): bool {
			$redirects[] = $location;

			return false;
		};

		add_filter( 'wp_redirect', $capture );

		$router = Plugin::instance()->container()->get( Router::class );
		$this->set_permalink_structure( '/%postname%/' );
		$router->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup needs the route in this process.
		flush_rewrite_rules( false );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );
		$this->go_to( home_url( $path ) );
		$router->gate();

		remove_filter( 'wp_redirect', $capture );

		return array(
			'router'    => $router,
			'redirects' => $redirects,
		);
	}
}
