<?php
/**
 * Staff package catalogue against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Menu;
use Aggressive\Ads\Admin\Package_Screen;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Package_Manager;
use WP_Error;
use WP_UnitTestCase;

/**
 * Proves package writes are authorized, verified, audited, and snapshot-safe.
 */
final class PackageAdminTest extends WP_UnitTestCase {

	/**
	 * Package workflow.
	 *
	 * @var Package_Manager
	 */
	private Package_Manager $manager;

	/**
	 * Package persistence.
	 *
	 * @var Package_Repository
	 */
	private Package_Repository $packages;

	/**
	 * Placement persistence.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Administrator user id.
	 *
	 * @var int
	 */
	private int $administrator;

	/**
	 * Reviewer user id.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Active placement post id.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Installs roles and one usable placement.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit      = new Audit_Repository();
		$this->packages   = new Package_Repository();
		$this->placements = new Placement_Repository();
		$this->campaigns  = Plugin::instance()->container()->get( Campaign_Repository::class );
		$this->manager    = Plugin::instance()->container()->get( Package_Manager::class );

		( new Installer( $this->audit, new Roles() ) )->install_roles();

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->reviewer      = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->placement_id  = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
	}

	/**
	 * Clears request state.
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * The Packages submenu is registered for the managing capability.
	 *
	 * @return void
	 */
	public function test_packages_submenu_is_wired(): void {
		global $submenu;

		wp_set_current_user( $this->administrator );
		$submenu = array();

		Plugin::instance()->container()->get( Menu::class )->register_parent();
		Plugin::instance()->container()->get( Package_Screen::class )->register_menu();

		$found = false;

		foreach ( $submenu[ Menu::PARENT_SLUG ] ?? array() as $item ) {
			if ( isset( $item[2] ) && Package_Screen::MENU_SLUG === $item[2] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found );

		// The catalogue's admin-post handlers were deleted with the server-
		// rendered template; writes go to REST\Packages_Controller now, and
		// leaving handlers registered with no form pointing at them would be
		// unreferenced write paths to the catalogue. See Rest\PackagesWriteTest.
		$this->assertFalse( has_action( 'admin_post_aggr_create_package' ) );
		$this->assertFalse( has_action( 'admin_post_aggr_update_package' ) );
	}

	/**
	 * An administrator can create a complete package. The change is audited.
	 *
	 * @return void
	 */
	public function test_authorized_create_is_saved_verified_and_audited(): void {
		wp_set_current_user( $this->administrator );

		$before = $this->audit_count( 'package.created' );
		$result = $this->manager->create( $this->valid_fields( array( 'is_default' => true ) ) );

		$this->assertIsInt( $result );
		$this->assertTrue( $this->packages->is_active( $result ) );
		$this->assertSame( $result, $this->packages->default_id() );
		$this->assertSame( array( $this->placement_id ), $this->packages->placement_ids( $result ) );
		$this->assertSame( 45000, $this->packages->price_cents( $result ) );
		$this->assertSame( $before + 1, $this->audit_count( 'package.created' ) );
	}

	/**
	 * Assigning a new default clears the previous flag.
	 *
	 * @return void
	 */
	public function test_saving_a_default_clears_the_previous_default(): void {
		wp_set_current_user( $this->administrator );

		$first  = $this->manager->create(
			$this->valid_fields(
				array(
					'name'       => 'First',
					'is_default' => true,
				)
			)
		);
		$second = $this->manager->create(
			$this->valid_fields(
				array(
					'name'       => 'Second',
					'is_default' => true,
				)
			)
		);

		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
		$this->assertSame( $second, $this->packages->default_id() );
		$this->assertSame( 0, (int) get_post_meta( $first, Package_Repository::META_IS_DEFAULT, true ) );
	}

	/**
	 * An inactive package may be incomplete. An active one may not.
	 *
	 * @return void
	 */
	public function test_active_package_requires_a_placement(): void {
		wp_set_current_user( $this->administrator );

		$denied = $this->manager->create(
			$this->valid_fields(
				array(
					'placement_ids' => array(),
					'is_active'     => true,
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'aggr_invalid_package_placements', $denied->get_error_code() );

		$draft = $this->manager->create(
			$this->valid_fields(
				array(
					'placement_ids' => array(),
					'is_active'     => false,
					'is_default'    => false,
				)
			)
		);

		$this->assertIsInt( $draft );
		$this->assertFalse( $this->packages->is_active( $draft ) );
		$this->assertNotContains( $draft, $this->packages->active_ids() );
	}

	/**
	 * A package cannot require more creatives than campaign reads support.
	 *
	 * @return void
	 */
	public function test_package_placement_count_is_bounded_by_creative_capacity(): void {
		wp_set_current_user( $this->administrator );

		$result = $this->manager->create(
			$this->valid_fields(
				array(
					'placement_ids' => range( 1, Creative_Repository::MAX_PER_CAMPAIGN + 1 ),
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_package_too_many_placements', $result->get_error_code() );
	}

	/**
	 * Editing a package does not rewrite a campaign that already selected it.
	 *
	 * @return void
	 */
	public function test_package_edits_do_not_mutate_campaign_snapshots(): void {
		wp_set_current_user( $this->administrator );

		$package_id = $this->manager->create( $this->valid_fields() );
		$this->assertIsInt( $package_id );

		$org_id = Plugin::instance()->container()->get( Org_Repository::class )->create_for_owner(
			'PACKAGE SNAPSHOT ORG',
			$this->advertiser
		);
		$this->assertIsInt( $org_id );

		$campaign_id = $this->campaigns->create_draft( $org_id, $this->advertiser, 'Snapshot campaign' );
		$this->assertIsInt( $campaign_id );

		$saved = $this->campaigns->update_draft(
			$campaign_id,
			array(
				'package_id'    => $package_id,
				'placement_ids' => array( $this->placement_id ),
				'budget_cents'  => 45000,
				'currency'      => 'USD',
			)
		);
		$this->assertTrue( $saved );

		$this->assertTrue(
			$this->manager->update(
				$package_id,
				$this->valid_fields( array( 'price_cents' => 99000 ) )
			)
		);

		$this->assertSame( 99000, $this->packages->price_cents( $package_id ) );
		$this->assertSame( 45000, $this->campaigns->budget_cents( $campaign_id ) );
		$this->assertSame( $package_id, $this->campaigns->package_id( $campaign_id ) );
	}

	/**
	 * Reviewers do not hold the managing capability.
	 *
	 * @return void
	 */
	public function test_a_reviewer_cannot_create_a_package(): void {
		wp_set_current_user( $this->reviewer );

		$result = $this->manager->create( $this->valid_fields() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
	}

	/**
	 * Valid create/update fields.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function valid_fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'            => 'Launch bundle',
				'placement_ids'   => array( $this->placement_id ),
				'duration_days'   => 30,
				'custom_duration' => false,
				'price_cents'     => 45000,
				'currency'        => 'USD',
				'is_active'       => true,
				'is_default'      => false,
			),
			$overrides
		);
	}

	/**
	 * How many audit rows exist for an event name.
	 *
	 * @param string $event Event name.
	 */
	private function audit_count( string $event ): int {
		global $wpdb;

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event = %s", $event ) );
	}
}
