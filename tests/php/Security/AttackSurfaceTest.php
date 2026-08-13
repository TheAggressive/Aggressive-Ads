<?php
/**
 * Structural mitigations.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Security\Admin_Guard;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Some risks are removed by design rather than defended against. That is only
 * true while the design holds, so each one is asserted.
 */
final class AttackSurfaceTest extends WP_UnitTestCase {

	/**
	 * Ensures roles exist before capability checks.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new \Aggressive\Ads\Install\Installer(
			new \Aggressive\Ads\Repository\Audit_Repository(),
			new Roles()
		) )->install_roles();
	}

	/**
	 * The plugin registers no admin-ajax handlers at all.
	 *
	 * The attack surface is empty rather than guarded. Every wp_ajax_* endpoint
	 * is a route with its own authorization to get right, and this plugin has
	 * no reason to have any — REST is the surface.
	 *
	 * @return void
	 */
	public function test_no_admin_ajax_handlers_are_registered(): void {
		global $wp_filter;

		$found = array();

		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( ! is_string( $hook ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^wp_ajax_(nopriv_)?laao_ads/', $hook ) ) {
				$found[] = $hook;
			}
		}

		$this->assertSame( array(), $found, 'The plugin registered an admin-ajax handler.' );
	}

	/**
	 * No meta key of ours is exposed through REST.
	 *
	 * @return void
	 */
	public function test_no_plugin_meta_is_registered_for_rest(): void {
		$exposed = array();

		foreach ( get_registered_meta_keys( 'post' ) as $key => $args ) {
			if ( ! str_starts_with( (string) $key, '_aggr_' ) ) {
				continue;
			}

			if ( ! empty( $args['show_in_rest'] ) ) {
				$exposed[] = (string) $key;
			}
		}

		// Collected rather than asserted in the loop, so this still asserts
		// something while no meta is registered yet. A loop-only assertion in
		// an empty loop is a test that reports green having run nothing.
		$this->assertSame( array(), $exposed );
	}

	/**
	 * The admin guard is actually hooked.
	 *
	 * @return void
	 */
	public function test_the_admin_guard_is_registered(): void {
		$this->assertNotFalse(
			has_action(
				'admin_init',
				array( Plugin::instance()->container()->get( Admin_Guard::class ), 'guard' )
			)
		);
	}

	/**
	 * An advertiser is bounced out of the admin.
	 *
	 * @return void
	 */
	public function test_an_advertiser_is_redirected_out_of_the_admin(): void {
		$guard   = Plugin::instance()->container()->get( Admin_Guard::class );
		$user_id = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		wp_set_current_user( $user_id );

		$this->assertTrue( $guard->should_redirect() );
	}

	/**
	 * Staff are not, because the review queue lives in the admin.
	 *
	 * @return void
	 */
	public function test_staff_are_not_redirected(): void {
		$guard = Plugin::instance()->container()->get( Admin_Guard::class );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertFalse( $guard->should_redirect() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( $guard->should_redirect() );
	}

	/**
	 * A logged-out visitor is left alone, so the login flow is untouched.
	 *
	 * @return void
	 */
	public function test_logged_out_visitors_are_not_redirected(): void {
		$guard = Plugin::instance()->container()->get( Admin_Guard::class );

		wp_set_current_user( 0 );

		$this->assertFalse( $guard->should_redirect() );
	}

	/**
	 * Form handlers at admin-post.php are exempt.
	 *
	 * It runs through admin_init, and redirecting it breaks every form handler
	 * on the site for that user — a failure that looks like a broken feature
	 * rather than a redirect.
	 *
	 * @return void
	 */
	public function test_admin_post_is_exempt(): void {
		$guard = Plugin::instance()->container()->get( Admin_Guard::class );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$previous           = $GLOBALS['pagenow'] ?? '';
		$GLOBALS['pagenow'] = 'admin-post.php';

		$exempt = $guard->should_redirect();

		$GLOBALS['pagenow'] = $previous;

		$this->assertFalse( $exempt, 'admin-post.php was redirected.' );
	}

	/**
	 * The advertiser role holds no capability that reaches site content.
	 *
	 * @param string $forbidden Capability an advertiser must never hold.
	 * @return void
	 *
	 * @dataProvider data_forbidden_capabilities
	 */
	public function test_an_advertiser_holds_no_content_capability( string $forbidden ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$this->assertFalse( current_user_can( $forbidden ) );
	}

	/**
	 * Capabilities that would undo the portal's containment.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_forbidden_capabilities(): array {
		return array(
			'upload_files'    => array( 'upload_files' ),
			'edit_posts'      => array( 'edit_posts' ),
			'unfiltered_html' => array( 'unfiltered_html' ),
			'manage_options'  => array( 'manage_options' ),
			'edit_users'      => array( 'edit_users' ),
			'install_plugins' => array( 'install_plugins' ),
		);
	}

	/**
	 * An advertiser cannot reach the approval capability, which publishes to a
	 * public site and can bill a customer.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_publish_to_the_provider(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$this->assertFalse( current_user_can( Capabilities::PUBLISH_TO_ADSANITY ) );
		$this->assertFalse( current_user_can( Capabilities::REVIEW_CAMPAIGNS ) );
	}

	/**
	 * Every post type is still private after a full boot, not merely at
	 * registration time — a later plugin could have filtered the arguments.
	 *
	 * @return void
	 */
	public function test_post_types_are_still_private_after_boot(): void {
		foreach ( Post_Types::all() as $slug ) {
			$object = get_post_type_object( $slug );

			$this->assertNotNull( $object );
			$this->assertFalse( $object->public );
			$this->assertFalse( $object->show_in_rest );
		}
	}
}
