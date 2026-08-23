<?php
/**
 * The six authenticated organization write handlers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Organization_Actions;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Tests\Redirect_Trap;
use Aggressive\Ads\Workflow\Organization_Membership;
use WP_UnitTestCase;

/**
 * The delivery layer for invite, approve, deny, remove, transfer and rename.
 *
 * The delivery layer requires portal access, and the workflow independently
 * requires organization ownership or the staff override. Both are covered:
 * losing the feature capability must close the endpoint even if the ownership
 * record remains, while a portal member still cannot manage an organization
 * they do not own.
 */
final class PortalOrganizationActionsTest extends WP_UnitTestCase {
	use Redirect_Trap;

	/**
	 * Handlers under test.
	 *
	 * @var Organization_Actions
	 */
	private Organization_Actions $actions;

	/**
	 * Membership workflow, for arranging fixtures.
	 *
	 * @var Organization_Membership
	 */
	private Organization_Membership $memberships;

	/**
	 * Pending invitation and request rows.
	 *
	 * @var Org_Access_Repository
	 */
	private Org_Access_Repository $access;

	/**
	 * Organization persistence.
	 *
	 * @var Org_Repository
	 */
	private Org_Repository $organizations;

	/**
	 * Owner of the first organization.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * A member of the first organization who does not own it.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Owner of the second organization.
	 *
	 * @var int
	 */
	private int $other_owner;

	/**
	 * The first organization.
	 *
	 * @var int
	 */
	private int $org;

	/**
	 * The second organization, which nobody in the first may touch.
	 *
	 * @var int
	 */
	private int $other_org;

	/**
	 * Builds two organizations, an owner, a plain member and a second owner.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner       = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->member      = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->other_owner = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org       = $this->organization( 'Bright Angle Media', $this->owner );
		$this->other_org = $this->organization( 'Second tenant', $this->other_owner );

		add_post_meta( $this->org, Org_Repository::META_MEMBER_USER, $this->member );

		$container           = Plugin::instance()->container();
		$this->actions       = $container->get( Organization_Actions::class );
		$this->memberships   = $container->get( Organization_Membership::class );
		$this->access        = $container->get( Org_Access_Repository::class );
		$this->organizations = $container->get( Org_Repository::class );

		$this->organizations->flush_cache();
		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Clears request globals between handler invocations.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Creates one active organization.
	 *
	 * @param string $name  Organization name.
	 * @param int    $owner Owning user id.
	 * @return int
	 */
	private function organization( string $name, int $owner ): int {
		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);

		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $owner );

		return $org_id;
	}

	/**
	 * Populates the request superglobals a handler reads.
	 *
	 * `$_REQUEST` matters and is the reason this helper exists.
	 * `check_admin_referer()` reads the nonce from there, and PHP does not
	 * populate it from `$_POST` under CLI — so a test that sets only `$_POST`
	 * submits no nonce however carefully it minted one, dies at
	 * `wp_nonce_ays()`, and proves nothing about anything past that line.
	 *
	 * @param array<string, string> $fields POST fields.
	 * @return void
	 */
	private function submit( array $fields ): void {
		$_POST    = $fields;
		$_REQUEST = $fields;
	}

	/**
	 * Runs a handler, absorbing the redirect it ends with.
	 *
	 * @param string $handler Handler method name.
	 * @return list<string>
	 */
	private function dispatch( string $handler ): array {
		return $this->trap_redirects( fn () => $this->actions->{$handler}() );
	}

	/**
	 * Every handler and the nonce action it is supposed to require.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function handler_provider(): array {
		return array(
			'invite'   => array( 'handle_invite', Organization_Actions::INVITE_ACTION ),
			'approve'  => array( 'handle_approve', Organization_Actions::APPROVE_ACTION ),
			'deny'     => array( 'handle_deny', Organization_Actions::DENY_ACTION ),
			'remove'   => array( 'handle_remove', Organization_Actions::REMOVE_ACTION ),
			'transfer' => array( 'handle_transfer', Organization_Actions::TRANSFER_ACTION ),
			'rename'   => array( 'handle_rename', Organization_Actions::RENAME_ACTION ),
		);
	}

	/**
	 * All six handlers are attached to their admin_post actions.
	 *
	 * @return void
	 */
	public function test_every_handler_is_wired(): void {
		foreach ( self::handler_provider() as $case ) {
			list( $handler, $action ) = $case;

			$this->assertNotFalse(
				has_action( 'admin_post_' . $action, array( $this->actions, $handler ) ),
				$handler . ' is not attached.'
			);
		}
	}

	/**
	 * **Every handler refuses a request carrying no nonce.**
	 *
	 * Six authenticated state-changing endpoints, and nothing reached any of
	 * them: all six `check_admin_referer()` calls could be deleted together
	 * with the whole suite green.
	 *
	 * @dataProvider handler_provider
	 * @param string $handler Handler method name.
	 * @param string $action  Nonce action it requires. Unused here on purpose.
	 * @return void
	 */
	public function test_every_handler_refuses_a_request_with_no_nonce( string $handler, string $action ): void {
		unset( $action );

		wp_set_current_user( $this->owner );

		$this->submit(
			array(
				'email'             => 'invitee@example.test',
				'user_id'           => (string) $this->member,
				'access_id'         => '1',
				'organization_name' => 'Renamed without a nonce',
			)
		);

		$this->expectException( 'WPDieException' );

		$this->actions->{$handler}();
	}

	/**
	 * **A nonce minted for one action does not authorize another.**
	 *
	 * All six share a screen, so a rename nonce is the one an attacker is most
	 * likely to already hold.
	 *
	 * @return void
	 */
	public function test_a_nonce_for_another_action_is_refused(): void {
		wp_set_current_user( $this->owner );

		$this->submit(
			array(
				'user_id'  => (string) $this->member,
				'_wpnonce' => wp_create_nonce( Organization_Actions::RENAME_ACTION ),
			)
		);

		$this->expectException( 'WPDieException' );

		$this->actions->handle_remove();
	}

	/**
	 * **Revoking portal access closes every organization mutation.**
	 *
	 * Ownership is durable business data and can outlive a role correction or
	 * suspension. A valid nonce proves request intent, not feature authority, so
	 * neither fact may let a revoked account keep changing its tenant.
	 *
	 * @dataProvider handler_provider
	 * @param string $handler Handler method name.
	 * @param string $action  Nonce action it requires.
	 * @return void
	 */
	public function test_every_handler_refuses_an_owner_without_portal_access( string $handler, string $action ): void {
		$user = get_userdata( $this->owner );
		$this->assertNotFalse( $user );
		$user->add_cap( Capabilities::ACCESS_PORTAL, false );

		wp_set_current_user( $this->owner );
		$this->assertTrue( $this->organizations->is_owner( $this->org, $this->owner ) );
		$this->assertFalse( current_user_can( Capabilities::ACCESS_PORTAL ) );

		$this->submit(
			array(
				'email'             => 'blocked@example.test',
				'user_id'           => (string) $this->member,
				'access_id'         => '1',
				'organization_name' => 'Unauthorized rename',
				'_wpnonce'          => wp_create_nonce( $action ),
			)
		);

		$before_pending = $this->access->pending_for_org( $this->org );
		$before_members = $this->organizations->user_ids_for_org( $this->org );
		$before_name    = $this->organizations->name( $this->org );

		$denied = false;

		try {
			$this->actions->{$handler}();
			$this->fail( $handler . ' accepted an owner whose portal access was revoked.' );
		} catch ( \WPDieException ) {
			$denied = true;
		}

		$this->assertTrue( $denied, $handler . ' did not terminate with a denial.' );
		$this->assertSame( $before_pending, $this->access->pending_for_org( $this->org ) );
		$this->assertSame( $before_members, $this->organizations->user_ids_for_org( $this->org ) );
		$this->assertSame( $before_name, $this->organizations->name( $this->org ) );
		$this->assertTrue( $this->organizations->is_owner( $this->org, $this->owner ) );
	}

	/**
	 * **The organization acted on comes from the session, never the request.**
	 *
	 * `current_org_id()` resolves the tenant from the authenticated user and
	 * ignores input entirely. Teaching it to read a posted `org_id` — the
	 * ordinary way this is written — left all 668 tests green, and would let
	 * any portal account rename, empty or hand away any organization on the
	 * site with one form field.
	 *
	 * @return void
	 */
	public function test_the_tenant_is_derived_from_the_session_not_the_request(): void {
		wp_set_current_user( $this->owner );

		$this->submit(
			array(
				'org_id'   => (string) $this->other_org,
				'email'    => 'planted@example.test',
				'_wpnonce' => wp_create_nonce( Organization_Actions::INVITE_ACTION ),
			)
		);

		$redirects = $this->dispatch( 'handle_invite' );

		$this->assertSame(
			array(),
			$this->access->pending_for_org( $this->other_org ),
			'A posted org_id planted an invitation in an organization the user does not own.'
		);

		// The invitation did land — on the caller's own organization. Asserting
		// both halves is what separates "the field was ignored" from "the whole
		// request was rejected", and only the first is the behaviour claimed.
		$this->assertCount( 1, $this->access->pending_for_org( $this->org ) );
		$this->assertNotSame( array(), $redirects );
		$this->assertStringContainsString( 'aggr_org_notice=invited', $redirects[0] );
	}

	/**
	 * **Inviting into an organization requires managing it.**
	 *
	 * A plain member is inside the tenant and is not authorized to grow it.
	 * `invite()`'s `can_manage()` guard could be deleted with the suite green,
	 * and without it any member could hand an outsider an account in the
	 * organization they belong to.
	 *
	 * @return void
	 */
	public function test_a_plain_member_cannot_invite(): void {
		wp_set_current_user( $this->member );

		$this->assertFalse( $this->organizations->is_owner( $this->org, $this->member ) );

		$before = $this->access->pending_for_org( $this->org );

		$this->submit(
			array(
				'email'    => 'outsider@example.test',
				'_wpnonce' => wp_create_nonce( Organization_Actions::INVITE_ACTION ),
			)
		);

		$redirects = $this->dispatch( 'handle_invite' );

		$this->assertSame( $before, $this->access->pending_for_org( $this->org ) );
		$this->assertNotSame( array(), $redirects );
		$this->assertStringContainsString( 'aggr_org_notice=error', $redirects[0] );
	}

	/**
	 * **Closing a pending request requires managing the organization.**
	 *
	 * `deny()` revokes invitations and closes access requests, and can delete
	 * the pending account behind one. Its `can_manage()` guard could be deleted
	 * with the suite green, leaving any member able to cancel the owner's
	 * invitations.
	 *
	 * @return void
	 */
	public function test_a_plain_member_cannot_deny_a_pending_request(): void {
		wp_set_current_user( $this->owner );

		$this->assertTrue( $this->memberships->invite( $this->org, 'invitee@example.test', $this->owner ) );

		$pending = $this->access->pending_for_org( $this->org );

		$this->assertNotSame( array(), $pending, 'The fixture produced no pending row to deny.' );

		$row_id = (int) $pending[0]['id'];

		wp_set_current_user( $this->member );

		$this->submit(
			array(
				'access_id' => (string) $row_id,
				'_wpnonce'  => wp_create_nonce( Organization_Actions::DENY_ACTION ),
			)
		);

		$redirects = $this->dispatch( 'handle_deny' );

		$this->assertNotSame(
			array(),
			$this->access->pending_for_org( $this->org ),
			'A plain member closed the owner\'s pending invitation.'
		);
		$this->assertNotSame( array(), $redirects );
		$this->assertStringContainsString( 'aggr_org_notice=error', $redirects[0] );
	}

	/**
	 * The owner's own invitation still works, so the guards are not blanket.
	 *
	 * @return void
	 */
	public function test_the_owner_may_still_invite(): void {
		wp_set_current_user( $this->owner );

		$this->submit(
			array(
				'email'    => 'colleague@example.test',
				'_wpnonce' => wp_create_nonce( Organization_Actions::INVITE_ACTION ),
			)
		);

		$redirects = $this->dispatch( 'handle_invite' );

		$this->assertNotSame( array(), $redirects );
		$this->assertStringContainsString( 'aggr_org_notice=invited', $redirects[0] );
	}
}
