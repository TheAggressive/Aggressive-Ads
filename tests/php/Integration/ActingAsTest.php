<?php
/**
 * The acting-as session.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Acting_As;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * A session changes scope, never permission.
 *
 * Most of these tests exist to hold that line. A session that granted anything
 * would be a privilege-escalation primitive any reviewer could point at any
 * organization, so the interesting assertions are the ones about what a
 * session does *not* do.
 */
final class ActingAsTest extends WP_UnitTestCase {

	/**
	 * Holds the review capability.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Holds no staff capability.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * The client organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Subject.
	 *
	 * @var Acting_As
	 */
	private Acting_As $acting;

	/**
	 * Builds the organization and users.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->acting     = $container->get( Acting_As::class );
		$this->reviewer   = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_title'  => 'Blue Ridge Coffee',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Staff can start a session, and the portal scopes to it.
	 *
	 * @return void
	 */
	public function test_a_session_scopes_the_portal_to_the_advertiser(): void {
		wp_set_current_user( $this->reviewer );

		$view = Plugin::instance()->container()->get( View_Data::class );

		$this->assertSame( 0, $view->org_id(), 'Staff have no organization of their own.' );

		$this->assertTrue( $this->acting->enter( $this->org_id ) );
		$this->assertTrue( $this->acting->active() );
		$this->assertSame( $this->org_id, $view->org_id() );
		$this->assertSame( 'Blue Ridge Coffee', $this->acting->org_name() );
	}

	/**
	 * Leaving ends it.
	 *
	 * @return void
	 */
	public function test_leaving_ends_the_session(): void {
		wp_set_current_user( $this->reviewer );

		$this->acting->enter( $this->org_id );
		$this->acting->leave();

		$this->assertFalse( $this->acting->active() );
		$this->assertSame( 0, $this->acting->org_id() );
	}

	/**
	 * An advertiser cannot start one.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_act_for_an_organization(): void {
		wp_set_current_user( $this->advertiser );

		$this->assertFalse( $this->acting->enter( $this->org_id ) );
		$this->assertFalse( $this->acting->active() );
	}

	/**
	 * An organization that does not exist cannot be acted for.
	 *
	 * @return void
	 */
	public function test_an_unknown_organization_is_refused(): void {
		wp_set_current_user( $this->reviewer );

		$this->assertFalse( $this->acting->enter( 999999 ) );
	}

	/**
	 * A session stops applying the moment the capability is withdrawn.
	 *
	 * Stored state outlives the grant that allowed it. Re-checking only at
	 * `enter()` would leave a demoted reviewer acting for a client until the
	 * session happened to expire.
	 *
	 * @return void
	 */
	public function test_losing_the_capability_ends_the_session_immediately(): void {
		wp_set_current_user( $this->reviewer );
		$this->acting->enter( $this->org_id );

		$this->assertTrue( $this->acting->active() );

		$user = get_user_by( 'id', $this->reviewer );
		$user->remove_cap( Capabilities::REVIEW_CAMPAIGNS );
		$user->remove_role( Roles::REVIEWER );

		wp_set_current_user( 0 );
		wp_set_current_user( $this->reviewer );

		$fresh = new Acting_As(
			Plugin::instance()->container()->get( User_Repository::class ),
			Plugin::instance()->container()->get( Org_Repository::class ),
			Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Audit_Repository::class )
		);

		$this->assertSame( 0, $fresh->org_id(), 'A demoted reviewer is still acting for a client.' );
	}

	/**
	 * An expired session stops applying.
	 *
	 * @return void
	 */
	public function test_an_expired_session_stops_applying(): void {
		$users = Plugin::instance()->container()->get( User_Repository::class );

		wp_set_current_user( $this->reviewer );

		$users->store_acting_as( $this->reviewer, $this->org_id, time() - 1 );

		$this->assertSame( 0, $users->acting_as( $this->reviewer ) );
	}

	/**
	 * A session grants nothing.
	 *
	 * This is the whole safety argument. Acting for an organization must not
	 * hand the actor a capability they did not already hold, or the session
	 * becomes a way to acquire rights rather than a way to see a screen.
	 *
	 * @return void
	 */
	public function test_a_session_grants_no_capability(): void {
		wp_set_current_user( $this->reviewer );

		$before = current_user_can( Capabilities::MANAGE_ORGS );

		$this->acting->enter( $this->org_id );

		$this->assertSame(
			$before,
			current_user_can( Capabilities::MANAGE_ORGS ),
			'Entering a session changed what the actor may do.'
		);
		$this->assertFalse(
			current_user_can( Capabilities::MANAGE_SETTINGS ),
			'A session handed out a capability the reviewer never held.'
		);
	}

	/**
	 * Both ends of the session are audited.
	 *
	 * @return void
	 */
	public function test_the_session_is_audited_at_both_ends(): void {
		wp_set_current_user( $this->reviewer );

		$this->acting->enter( $this->org_id );
		$this->acting->leave();

		$events = array_column(
			Plugin::instance()->container()
				->get( \Aggressive\Ads\Repository\Audit_Repository::class )
				->for_object( 'organization', $this->org_id, $this->org_id, 50 ),
			'event'
		);

		$this->assertContains( 'onbehalf.session_started', $events );
		$this->assertContains( 'onbehalf.session_ended', $events );
	}
}
