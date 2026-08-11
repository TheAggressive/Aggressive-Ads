<?php
/**
 * The account, organization and help screens.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Account_Actions;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Router;
use LAAO_Advertiser_Portal\Portal\View_Data;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Roles;
use WP_UnitTestCase;

/**
 * The three screens that finish the portal.
 *
 * Account is the one with teeth. Admin_Guard redirects portal users away from
 * wp-admin, so this handler is the only route by which an advertiser can write
 * anything about their own user record — which makes it the only place a
 * privilege escalation could hide.
 */
final class PortalAccountTest extends WP_UnitTestCase {

	/**
	 * Screen data.
	 *
	 * @var View_Data
	 */
	private View_Data $view;

	/**
	 * Account writes.
	 *
	 * @var Account_Actions
	 */
	private Account_Actions $actions;

	/**
	 * The advertiser under test.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Their organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Sets up an advertiser who owns one organization.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->view    = Plugin::instance()->container()->get( View_Data::class );
		$this->actions = Plugin::instance()->container()->get( Account_Actions::class );

		$this->advertiser = self::factory()->user->create(
			array(
				'role'         => Roles::ADVERTISER,
				'user_email'   => 'dana@example.test',
				'display_name' => 'Dana Okonkwo',
			)
		);

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		wp_set_current_user( $this->advertiser );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * **The write touches three fields and nothing else.**
	 *
	 * This handler is reachable by anyone holding the portal capability, so an
	 * array forwarded wholesale from $_POST would let an advertiser set their
	 * own role. wp_update_user() is given an explicit three-key array for that
	 * reason, and this is the test that would notice if somebody "simplified"
	 * it into passing the request through.
	 *
	 * @return void
	 */
	public function test_saving_cannot_change_the_role_or_the_email(): void {
		$result = $this->actions->process_save(
			$this->advertiser,
			array(
				'display_name' => 'Dana O.',
				'first_name'   => 'Dana',
				'last_name'    => 'Okonkwo',

				// Present in the array and expected to be ignored entirely.
				'role'         => 'administrator',
				'user_email'   => 'attacker@example.test',
				'user_pass'    => 'hunter2',
			)
		);

		$this->assertTrue( $result );

		$user = get_userdata( $this->advertiser );

		$this->assertNotFalse( $user );
		$this->assertSame( 'Dana O.', $user->display_name );
		$this->assertSame( 'dana@example.test', $user->user_email );
		$this->assertSame( array( Roles::ADVERTISER ), array_values( $user->roles ) );
		$this->assertFalse( user_can( $this->advertiser, 'manage_options' ) );
	}

	/**
	 * A blank display name is refused rather than stored.
	 *
	 * @return void
	 */
	public function test_a_blank_display_name_is_refused(): void {
		$result = $this->actions->process_save( $this->advertiser, array( 'display_name' => '   ' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'laao_ads_display_name_required', $result->get_error_code() );
		$this->assertSame( 'Dana Okonkwo', (string) get_userdata( $this->advertiser )->display_name );
	}

	/**
	 * An over-long name is refused on the server, not only by the input.
	 *
	 * @return void
	 */
	public function test_an_over_long_name_is_refused(): void {
		$result = $this->actions->process_save(
			$this->advertiser,
			array( 'display_name' => str_repeat( 'a', Account_Actions::MAX_NAME_LENGTH + 1 ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'laao_ads_name_too_long', $result->get_error_code() );
	}

	/**
	 * The account screen reports the caller's own details.
	 *
	 * @return void
	 */
	public function test_the_account_screen_reports_the_caller(): void {
		$account = $this->view->account();

		$this->assertSame( $this->advertiser, $account['id'] );
		$this->assertSame( 'dana@example.test', $account['email'] );
		$this->assertSame( 'Bright Angle Media', $account['org_name'] );
	}

	/**
	 * The organization screen lists the organization's own people, with roles.
	 *
	 * @return void
	 */
	public function test_the_organization_screen_lists_its_people(): void {
		$colleague = self::factory()->user->create(
			array(
				'role'         => Roles::ADVERTISER,
				'user_email'   => 'colleague@example.test',
				'display_name' => 'Sam Reyes',
			)
		);

		add_post_meta( $this->org_id, Org_Repository::META_MEMBER_USER, $colleague );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$org = $this->view->organization();

		$this->assertIsArray( $org );
		$this->assertSame( 'Bright Angle Media', $org['name'] );
		$this->assertTrue( $org['active'] );
		$this->assertCount( 2, $org['members'] );

		$this->assertTrue( $org['members'][0]['is_owner'] );
		$this->assertTrue( $org['members'][0]['is_you'] );
		$this->assertFalse( $org['members'][1]['is_owner'] );
		$this->assertSame( 'Sam Reyes', $org['members'][1]['name'] );
	}

	/**
	 * **No other organization's people are ever listed.**
	 *
	 * The member list carries email addresses, so a scoping error here leaks
	 * contact details between competing advertisers.
	 *
	 * @return void
	 */
	public function test_the_organization_screen_never_shows_another_org(): void {
		$stranger = self::factory()->user->create(
			array(
				'role'       => Roles::ADVERTISER,
				'user_email' => 'stranger@example.test',
			)
		);

		$other = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Rival Media',
			)
		);

		update_post_meta( $other, Org_Repository::META_OWNER_USER, $stranger );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$org = $this->view->organization();

		$this->assertIsArray( $org );
		$this->assertSame( $this->org_id, $org['id'] );
		$this->assertNotContains( 'stranger@example.test', array_column( $org['members'], 'email' ) );
	}

	/**
	 * A suspended organization says so rather than looking normal.
	 *
	 * @return void
	 */
	public function test_a_suspended_organization_is_reported_as_suspended(): void {
		update_post_meta( $this->org_id, Org_Repository::META_ORG_STATE, Org_Repository::STATE_SUSPENDED );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$org = $this->view->organization();

		$this->assertIsArray( $org );
		$this->assertFalse( $org['active'] );
	}

	/**
	 * A user with no organization gets a screen, not a fatal.
	 *
	 * @return void
	 */
	public function test_a_user_without_an_organization_gets_no_organization(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$this->assertNull( $this->view->organization() );
	}

	/**
	 * **Every status an advertiser can see is explained.**
	 *
	 * The glossary is built from the registered statuses, so a twelfth status
	 * appears on the help screen by itself — with a description, rather than
	 * with the fallback sentence that explains nothing.
	 *
	 * @return void
	 */
	public function test_help_explains_every_status(): void {
		$help = $this->view->help();

		$this->assertCount( count( Post_Statuses::all() ), $help['statuses'] );

		$descriptions = array_column( $help['statuses'], 'description' );

		$this->assertCount(
			count( Post_Statuses::all() ),
			array_unique( $descriptions ),
			'Two statuses share a description, so at least one fell through to the default.'
		);

		foreach ( $help['statuses'] as $status ) {
			$this->assertNotSame( '', $status['label'] );
			$this->assertNotSame( '', $status['description'] );
		}
	}

	/**
	 * The creative limits on the help screen come from the rules themselves.
	 *
	 * Help maintained by hand is help that goes wrong, and wrong help costs
	 * more than none because people act on it.
	 *
	 * @return void
	 */
	public function test_help_states_the_real_upload_limits(): void {
		$help = $this->view->help();

		$this->assertSame( array( 'JPEG', 'PNG', 'GIF', 'WebP' ), $help['file_types'] );
		$this->assertSame( '2 MB', $help['max_size'] );
	}

	/**
	 * All three routes resolve to their own screen, not the placeholder.
	 *
	 * @return void
	 */
	public function test_the_three_routes_have_real_templates(): void {
		$router = Plugin::instance()->container()->get( Router::class );

		$this->set_permalink_structure( '/%postname%/' );
		$router->register_rules();

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Test setup: the rules must exist in this process before go_to() can resolve one.
		flush_rewrite_rules( false );

		foreach ( array( Request::ROUTE_ORGANIZATION, Request::ROUTE_ACCOUNT, Request::ROUTE_HELP ) as $route ) {
			$this->go_to( home_url( '/advertiser/' . $route . '/' ) );

			$template = apply_filters( 'template_include', 'theme-template.php' );

			$this->assertStringContainsString( 'templates/portal/' . $route . '.php', $template );
			$this->assertStringNotContainsString( 'placeholder.php', $template );
			$this->assertFileExists( $template );
		}

		$this->set_permalink_structure( '' );
	}
}
