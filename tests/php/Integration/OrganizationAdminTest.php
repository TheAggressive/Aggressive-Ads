<?php
/**
 * Staff organization suspension against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Organization_Data;
use Aggressive\Ads\Admin\Organization_Screen;
use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Proves suspension writes are authorized, verified, and auditable.
 */
final class OrganizationAdminTest extends WP_UnitTestCase {

	/**
	 * Organization screen controller.
	 *
	 * @var Organization_Screen
	 */
	private Organization_Screen $screen;

	/**
	 * Organization read model.
	 *
	 * @var Organization_Data
	 */
	private Organization_Data $data;

	/**
	 * Organization persistence.
	 *
	 * @var Org_Repository
	 */
	private Org_Repository $organizations;

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
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Fixture organization id.
	 *
	 * @var int
	 */
	private int $org_id;

	/** Installs capabilities and one owned organization. */
	public function set_up(): void {
		parent::set_up();

		$container           = Plugin::instance()->container();
		$this->audit         = $container->get( Audit_Repository::class );
		$this->organizations = $container->get( Org_Repository::class );

		( new Installer( $this->audit, new Roles() ) )->install_roles();

		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->advertiser    = self::factory()->user->create(
			array(
				'role'         => Roles::ADVERTISER,
				'display_name' => 'Bright Angle Owner',
			)
		);

		$org_id = $this->organizations->create_for_owner( 'BRIGHT ANGLE STUDIO', $this->advertiser );
		$this->assertIsInt( $org_id );
		$this->org_id = $org_id;

		$this->screen = $container->get( Organization_Screen::class );
		$this->data   = $container->get( Organization_Data::class );
	}

	/** Clears request state changed by handler tests. */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Menu and authenticated handlers are attached.
	 *
	 * There is deliberately no `admin_enqueue_scripts` assertion. This screen was
	 * converted to native wp-admin markup — form-table, wp-list-table, notice —
	 * which core already styles, so it enqueues nothing of ours. The assertion
	 * that used to be here outlived the behaviour it described and went on
	 * failing unnoticed, because the suite it ran in was exiting early and
	 * reporting success. See bin/ci/run-wp-tests.sh.
	 */
	public function test_organization_surface_is_wired(): void {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->screen, 'register_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Organization_Screen::SUSPEND_ACTION, array( $this->screen, 'handle_suspend' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Organization_Screen::REACTIVATE_ACTION, array( $this->screen, 'handle_reactivate' ) ) );
	}

	/** An administrator can suspend and reactivate with audit and read-back. */
	public function test_authorized_suspension_is_saved_verified_and_audited(): void {
		wp_set_current_user( $this->administrator );

		$this->assertTrue( $this->organizations->is_active( $this->org_id ) );
		$this->assertTrue( $this->screen->process_state_change( $this->org_id, Org_Repository::STATE_SUSPENDED ) );
		$this->assertFalse( $this->organizations->is_active( $this->org_id ) );
		$this->assertSame( Org_Repository::STATE_SUSPENDED, $this->organizations->state( $this->org_id ) );

		$events = $this->audit->for_object( 'organization', $this->org_id, $this->org_id );
		$this->assertNotEmpty( $events );
		$this->assertSame( 'organization.suspended', $events[0]['event'] );
		$this->assertSame( Audit_Event::OUTCOME_OK, $events[0]['outcome'] );

		$this->assertTrue( $this->screen->process_state_change( $this->org_id, Org_Repository::STATE_ACTIVE ) );
		$this->assertTrue( $this->organizations->is_active( $this->org_id ) );

		$events = $this->audit->for_object( 'organization', $this->org_id, $this->org_id );
		$this->assertSame( 'organization.reactivated', $events[0]['event'] );
	}

	/** Advertisers cannot suspend even their own organization through the staff workflow. */
	public function test_advertiser_cannot_suspend_an_organization(): void {
		wp_set_current_user( $this->advertiser );

		$result = $this->screen->process_state_change( $this->org_id, Org_Repository::STATE_SUSPENDED );
		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertTrue( $this->organizations->is_active( $this->org_id ) );
	}

	/** Idempotent repeat writes succeed without inventing a missing organization. */
	public function test_idempotent_state_and_missing_organization(): void {
		wp_set_current_user( $this->administrator );

		$this->assertTrue( $this->screen->process_state_change( $this->org_id, Org_Repository::STATE_ACTIVE ) );
		$this->assertTrue( $this->screen->process_state_change( $this->org_id, Org_Repository::STATE_SUSPENDED ) );
		$this->assertTrue( $this->screen->process_state_change( $this->org_id, Org_Repository::STATE_SUSPENDED ) );

		$missing = $this->screen->process_state_change( 999999, Org_Repository::STATE_SUSPENDED );
		$this->assertWPError( $missing );
		$this->assertSame( 'aggr_org_not_found', $missing->get_error_code() );
	}

	/** The staff list exposes the organization and its suspension state. */
	public function test_organization_list_includes_owner_and_state(): void {
		wp_set_current_user( $this->administrator );

		$view = $this->data->view();
		$row  = null;

		foreach ( $view['rows'] as $candidate ) {
			if ( $this->org_id === $candidate['id'] ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertIsArray( $row );
		$this->assertSame( 'BRIGHT ANGLE STUDIO', $row['name'] );
		$this->assertSame( 'Bright Angle Owner', $row['owner_name'] );
		$this->assertTrue( $row['active'] );

		$this->assertTrue( $this->organizations->set_state( $this->org_id, Org_Repository::STATE_SUSPENDED ) );
		$view = $this->data->view();

		foreach ( $view['rows'] as $candidate ) {
			if ( $this->org_id === $candidate['id'] ) {
				$this->assertFalse( $candidate['active'] );
				$this->assertSame( Org_Repository::STATE_SUSPENDED, $candidate['state'] );
				return;
			}
		}

		$this->fail( 'Suspended organization missing from staff list.' );
	}
}
