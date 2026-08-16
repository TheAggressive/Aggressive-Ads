<?php
/**
 * Per-user review access granted from Advertising → Settings.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Reviewer_Access;
use WP_UnitTestCase;

/**
 * The roster is an index; capabilities are the authority. These assert the
 * capability side, because that is what every check in the plugin reads.
 */
final class ReviewerAccessTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Reviewer_Access
	 */
	private Reviewer_Access $access;

	/**
	 * Somebody who can change advertising settings.
	 *
	 * @var int
	 */
	private int $administrator;

	/**
	 * Installs roles and resolves the service.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->access        = Plugin::instance()->container()->get( Reviewer_Access::class );
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->administrator );
	}

	/**
	 * The roster option must not leak into later tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Reviewer_Access::OPTION );

		parent::tear_down();
	}

	/**
	 * Granting adds capabilities without disturbing the person's role.
	 *
	 * This is the whole reason the feature exists: WordPress treats a role as
	 * single-valued, so promoting an editor to Ad Reviewer would take editing
	 * away from them.
	 *
	 * @return void
	 */
	public function test_granting_keeps_the_existing_role(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( $this->access->grant( $editor ) );

		$this->assertTrue( user_can( $editor, Capabilities::REVIEW_CAMPAIGNS ) );
		$this->assertContains( 'editor', (array) get_userdata( $editor )->roles );
		$this->assertTrue( user_can( $editor, 'edit_others_posts' ), 'Editor capabilities must survive the grant.' );
	}

	/**
	 * A granted user reaches the staff surfaces, which is the point.
	 *
	 * @return void
	 */
	public function test_a_granted_user_holds_the_staff_capabilities(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertTrue( $this->access->grant( $editor ) );

		$this->assertTrue( user_can( $editor, Capabilities::PUBLISH_TO_ADSANITY ) );
		$this->assertTrue( user_can( $editor, Capabilities::VIEW_AUDIT_LOG ) );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE_SETTINGS ), 'Reviewing must not carry configuration.' );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE_PLACEMENTS ), 'Reviewing must not carry inventory.' );
	}

	/**
	 * Revoking removes exactly what granting added.
	 *
	 * @return void
	 */
	public function test_revoking_removes_access_and_leaves_the_role(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertTrue( $this->access->grant( $editor ) );
		$this->assertTrue( $this->access->revoke( $editor ) );

		$this->assertFalse( user_can( $editor, Capabilities::REVIEW_CAMPAIGNS ) );
		$this->assertContains( 'editor', (array) get_userdata( $editor )->roles );
		$this->assertSame( array(), $this->access->roster() );
	}

	/**
	 * An advertiser is somebody else's customer. Reviewing means reading every
	 * organization's unpublished creative, so this is a tenancy boundary, not a
	 * tidiness rule.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_be_granted_review_access(): void {
		$advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$result = $this->access->grant( $advertiser );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_user_is_advertiser', $result->get_error_code() );
		$this->assertFalse( user_can( $advertiser, Capabilities::REVIEW_CAMPAIGNS ) );
	}

	/**
	 * Handing out access is a settings-grade decision.
	 *
	 * @return void
	 */
	public function test_only_a_settings_manager_may_change_access(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $editor );
		$result = $this->access->grant( $editor );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertFalse( user_can( $editor, Capabilities::REVIEW_CAMPAIGNS ) );
	}

	/**
	 * A reviewer granted here is indistinguishable from one holding the role.
	 *
	 * @return void
	 */
	public function test_a_granted_user_matches_the_role(): void {
		$granted = self::factory()->user->create( array( 'role' => 'editor' ) );
		$by_role = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->assertTrue( $this->access->grant( $granted ) );

		foreach ( Reviewer_Access::capabilities() as $capability ) {
			$this->assertSame(
				user_can( $by_role, $capability ),
				user_can( $granted, $capability ),
				"Capability {$capability} differs between a granted user and the role."
			);
		}
	}

	/**
	 * An unknown identifier is reported rather than silently doing nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_user_is_reported(): void {
		$this->assertSame( 0, $this->access->find( 'nobody@example.com' ) );
	}

	/**
	 * Login or email both resolve, because an administrator will type whichever
	 * they have to hand.
	 *
	 * @return void
	 */
	public function test_a_user_resolves_by_login_or_email(): void {
		$editor = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'jane',
				'user_email' => 'jane@example.com',
			)
		);

		$this->assertSame( $editor, $this->access->find( 'jane' ) );
		$this->assertSame( $editor, $this->access->find( 'jane@example.com' ) );
	}

	/**
	 * Administrators are shown as permanent rather than switchable, so nobody
	 * toggles one off and wonders why nothing changed.
	 *
	 * @return void
	 */
	public function test_an_administrator_is_marked_permanent(): void {
		$this->assertTrue( $this->access->grant( $this->administrator ) );

		$roster = $this->access->roster();

		$this->assertSame( $this->administrator, $roster[0]['id'] );
		$this->assertTrue( $roster[0]['is_admin'] );
	}
}
