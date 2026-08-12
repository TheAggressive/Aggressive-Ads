<?php
/**
 * Portal-owned password setup and recovery.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Security;

use LAAO_Advertiser_Portal\Notification\Password_Notification;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Password_Actions;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Router;
use LAAO_Advertiser_Portal\Security\Rate_Limiter;
use LAAO_Advertiser_Portal\Security\Roles;
use LAAO_Advertiser_Portal\Workflow\Password_Reset;
use WP_UnitTestCase;

/**
 * Proves reset keys remain core-backed while no customer sees WordPress UI.
 */
final class PortalPasswordResetTest extends WP_UnitTestCase {

	/**
	 * Captured transactional messages.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	/**
	 * Installs mail capture.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Removes global hooks and request state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		$_GET  = array();
		$_POST = array();
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Captures mail without a transport.
	 *
	 * @param null|bool            $short_circuit Earlier result.
	 * @param array<string, mixed> $mail          Mail arguments.
	 * @return bool
	 */
	public function capture_mail( null|bool $short_circuit, array $mail ): bool {
		$this->mail[] = $mail;

		return true;
	}

	/**
	 * Initial setup mail links only to the portal and carries a valid core key.
	 *
	 * @return void
	 */
	public function test_setup_mail_uses_a_portal_url_with_a_valid_core_key(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'       => Roles::ADVERTISER,
				'user_login' => 'opaque-reset-login',
				'user_email' => 'reset@example.test',
			)
		);

		$notification = Plugin::instance()->container()->get( Password_Notification::class );

		$this->assertTrue( $notification->send_setup( $user_id ) );
		$this->assertCount( 1, $this->mail );

		$message = (string) $this->mail[0]['message'];

		$this->assertStringContainsString( '/advertiser/set-password/', $message );
		$this->assertStringNotContainsString( 'wp-login.php', $message );

		$args = $this->link_arguments( $message );
		$user = Plugin::instance()->container()->get( Password_Reset::class )->validate( $args['key'], $args['login'] );

		$this->assertNotWPError( $user );
		$this->assertSame( $user_id, $user->ID );
	}

	/**
	 * A valid key is single-use and password policy is enforced server-side.
	 *
	 * @return void
	 */
	public function test_password_is_set_once_without_weakening_core_validation(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'       => Roles::ADVERTISER,
				'user_login' => 'single-use-reset-login',
				'user_email' => 'single-use@example.test',
			)
		);

		Plugin::instance()->container()->get( Password_Notification::class )->send_setup( $user_id );
		$args      = $this->link_arguments( (string) $this->mail[0]['message'] );
		$passwords = Plugin::instance()->container()->get( Password_Reset::class );

		$weak = $passwords->reset( $args['key'], $args['login'], 'short', 'short' );
		$this->assertWPError( $weak );
		$this->assertSame( 'laao_ads_invalid_password', $weak->get_error_code() );
		$this->assertNotWPError( $passwords->validate( $args['key'], $args['login'] ), 'A rejected password must not consume the link.' );

		$this->assertTrue( $passwords->reset( $args['key'], $args['login'], 'a secure portal passphrase', 'a secure portal passphrase' ) );
		$this->assertWPError( $passwords->validate( $args['key'], $args['login'] ), 'A successful reset must consume the link.' );
		$this->assertTrue( wp_check_password( 'a secure portal passphrase', (string) get_userdata( $user_id )->user_pass, $user_id ) );
	}

	/**
	 * Both public screens are routed and their handlers require action nonces.
	 *
	 * @return void
	 */
	public function test_password_screens_are_wired_public_routes(): void {
		$actions = Plugin::instance()->container()->get( Password_Actions::class );

		$this->assertContains( Request::ROUTE_FORGOT_PASSWORD, Request::public_routes() );
		$this->assertContains( Request::ROUTE_SET_PASSWORD, Request::public_routes() );
		$this->assertSame( 10, has_action( 'admin_post_nopriv_' . Password_Actions::REQUEST_ACTION, array( $actions, 'handle_request' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_nopriv_' . Password_Actions::SET_ACTION, array( $actions, 'handle_set' ) ) );

		$this->set_permalink_structure( '/%postname%/' );
		$router = Plugin::instance()->container()->get( Router::class );
		$router->register_rules();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup needs the routes in this process.
		flush_rewrite_rules( false );
		$this->go_to( home_url( '/advertiser/forgot-password/' ) );

		$template = apply_filters( 'template_include', 'theme-template.php' );
		$this->assertStringContainsString( 'templates/portal/forgot-password.php', $template );

		ob_start();
		require LAAO_ADS_PLUGIN_DIR . 'templates/portal/screens/forgot-password.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( '/advertiser/login/', $html );
		$this->assertStringNotContainsString( 'wp-login.php', $html );
	}

	/**
	 * Recovery requests have a finite anonymous-client limit.
	 *
	 * @return void
	 */
	public function test_password_requests_are_rate_limited(): void {
		$this->assertLessThan( PHP_INT_MAX, Rate_Limiter::limit_for( Rate_Limiter::ACTION_PASSWORD_RESET ) );
	}

	/**
	 * Extracts the only portal set-password URL from a plain-text message.
	 *
	 * @param string $message Message body.
	 * @return array{key: string, login: string}
	 */
	private function link_arguments( string $message ): array {
		$matched = preg_match( '#https?://[^\s]+/advertiser/set-password/\?[^\s]+#', $message, $matches );

		$this->assertSame( 1, $matched, 'The message did not contain a portal set-password URL.' );

		$query = (string) wp_parse_url( $matches[0], PHP_URL_QUERY );
		parse_str( $query, $args );

		$this->assertArrayHasKey( 'key', $args );
		$this->assertArrayHasKey( 'login', $args );

		return array(
			'key'   => (string) $args['key'],
			'login' => (string) $args['login'],
		);
	}
}
