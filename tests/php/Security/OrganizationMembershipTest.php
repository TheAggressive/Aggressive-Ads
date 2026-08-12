<?php
/**
 * Secure organization matching, invitations, and approval.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Security;

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\View_Data;
use LAAO_Advertiser_Portal\Repository\Org_Access_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Roles;
use LAAO_Advertiser_Portal\Workflow\Advertiser_Registration;
use LAAO_Advertiser_Portal\Workflow\Organization_Membership;
use WP_UnitTestCase;
use WP_User;

/**
 * Pins the tenant boundary around public organization-name matching.
 */
final class OrganizationMembershipTest extends WP_UnitTestCase {

	/**
	 * Organization persistence.
	 *
	 * @var Org_Repository
	 */
	private Org_Repository $organizations;

	/**
	 * Organization access persistence.
	 *
	 * @var Org_Access_Repository
	 */
	private Org_Access_Repository $access;

	/**
	 * Membership workflow.
	 *
	 * @var Organization_Membership
	 */
	private Organization_Membership $memberships;

	/**
	 * Public registration workflow.
	 *
	 * @var Advertiser_Registration
	 */
	private Advertiser_Registration $registration;

	/**
	 * Portal view-data assembler.
	 *
	 * @var View_Data
	 */
	private View_Data $view;

	/**
	 * Captured transactional messages.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	/** Enable registration and capture transactional messages. */
	public function set_up(): void {
		parent::set_up();

		$container           = Plugin::instance()->container();
		$this->organizations = $container->get( Org_Repository::class );
		$this->access        = $container->get( Org_Access_Repository::class );
		$this->memberships   = $container->get( Organization_Membership::class );
		$this->registration  = $container->get( Advertiser_Registration::class );
		$this->view          = $container->get( View_Data::class );
		$this->mail          = array();

		update_option( 'users_can_register', 1 );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/** Restore global hooks and policy. */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		delete_option( 'users_can_register' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Capture one message without using an external transport.
	 *
	 * @param null|bool            $short_circuit Earlier short-circuit value.
	 * @param array<string, mixed> $mail          Mail arguments.
	 */
	public function capture_mail( null|bool $short_circuit, array $mail ): bool {
		$this->mail[] = $mail;

		return true;
	}

	/**
	 * Create an active organization with a real owner.
	 *
	 * @param string $name Organization name.
	 * @return array{owner_id: int, org_id: int}
	 */
	private function make_org( string $name = 'COPPER STATE ARTS' ): array {
		$owner_id = self::factory()->user->create(
			array(
				'role'       => Roles::ADVERTISER,
				'user_email' => 'owner-' . wp_generate_uuid4() . '@example.test',
			)
		);
		$org_id   = $this->organizations->create_for_owner( $name, $owner_id );

		$this->assertIsInt( $org_id );

		return array(
			'owner_id' => $owner_id,
			'org_id'   => $org_id,
		);
	}

	/** A normal public registration fixture. */
	private function fields( string $email, string $organization = 'COPPER STATE ARTS' ): array {
		return array(
			'first_name'        => 'Amina',
			'last_name'         => 'Rivera',
			'organization_name' => $organization,
			'email'             => $email,
		);
	}

	/** Exact canonical and unambiguous misspelled names resolve privately. */
	public function test_matching_normalizes_case_punctuation_accents_and_close_spelling(): void {
		$org = $this->make_org( 'Museo Águila, LLC' );

		$this->assertSame( 'MUSEO AGUILA LLC', Org_Repository::canonical_name( ' museo águila, llc ' ) );
		$this->assertSame( 'MUSEO ÁGUILA, LLC', get_the_title( $org['org_id'] ) );
		$this->assertSame( $org['org_id'], $this->organizations->matching_org_id( 'Museo Aguila LLC' ) );
		$this->assertSame( $org['org_id'], $this->organizations->matching_org_id( 'Museo Aguila LCC' ) );
	}

	/** A possible duplicate creates no second tenant and grants no portal role. */
	public function test_duplicate_name_signup_waits_for_owner_approval(): void {
		$org    = $this->make_org();
		$result = $this->registration->register( $this->fields( 'requester@example.test', 'copper-state arts' ) );

		$this->assertTrue( $result );

		$user = get_user_by( 'email', 'requester@example.test' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( array( 'subscriber' ), $user->roles );
		$this->assertSame( array(), $this->organizations->org_ids_for_user( $user->ID ) );

		$pending = $this->access->pending_for_user( $user->ID );
		$this->assertIsArray( $pending );
		$this->assertSame( $org['org_id'], $pending['org_id'] );
		$this->assertSame( Org_Access_Repository::KIND_REQUEST, $pending['kind'] );
		$this->assertCount( 2, $this->mail, 'The requester and organization owner should each receive one message.' );
		$this->assertStringContainsString( 'No access was granted', (string) $this->mail[1]['message'] );
	}

	/** An expired request may be retried only for the same subscriber and org. */
	public function test_expired_request_can_be_retried_without_stranding_the_email(): void {
		global $wpdb;

		$org = $this->make_org();
		$this->registration->register( $this->fields( 'retry@example.test' ) );

		$user    = get_user_by( 'email', 'retry@example.test' );
		$pending = $user instanceof WP_User ? $this->access->pending_for_user( $user->ID ) : null;
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertIsArray( $pending );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security test forces the lifecycle edge without waiting seven days.
		$wpdb->update(
			$this->access->table_name(),
			array( 'expires_at_ts' => time() - 1 ),
			array( 'id' => (int) $pending['id'] ),
			array( '%d' ),
			array( '%d' )
		);

		$this->mail = array();
		$this->assertTrue( $this->registration->register( $this->fields( 'retry@example.test' ) ) );

		$retried = $this->access->pending_for_user( $user->ID );
		$this->assertIsArray( $retried );
		$this->assertSame( $org['org_id'], $retried['org_id'] );
		$this->assertNotSame( $pending['id'], $retried['id'] );
		$this->assertCount( 2, $this->mail );
	}

	/** An owner may approve its row; an unrelated tenant owner may not. */
	public function test_approval_is_scoped_to_the_organization_owner(): void {
		$org      = $this->make_org();
		$attacker = $this->make_org( 'OTHER ORGANIZATION' );
		$this->registration->register( $this->fields( 'approval@example.test' ) );

		$user    = get_user_by( 'email', 'approval@example.test' );
		$pending = $user instanceof WP_User ? $this->access->pending_for_user( $user->ID ) : null;
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertIsArray( $pending );

		$denied = $this->memberships->approve( (int) $pending['id'], $org['org_id'], $attacker['owner_id'] );
		$this->assertWPError( $denied );
		$this->assertSame( 'laao_ads_org_access_denied', $denied->get_error_code() );

		$this->assertTrue( $this->memberships->approve( (int) $pending['id'], $org['org_id'], $org['owner_id'] ) );
		$this->assertSame( array( $org['org_id'] ), $this->organizations->org_ids_for_user( $user->ID ) );
		$this->assertContains( Roles::ADVERTISER, get_userdata( $user->ID )->roles );
		$this->assertNull( $this->access->pending_for_user( $user->ID ) );
	}

	/** Invitations are email-bound, single-use, and do not require retyping a name. */
	public function test_invitation_grants_only_the_intended_email_once(): void {
		$org = $this->make_org();

		$this->assertTrue( $this->memberships->invite( $org['org_id'], 'invitee@example.test', $org['owner_id'] ) );
		$this->assertMatchesRegularExpression( '/[?&]invite=([A-Za-z0-9_-]{43})/', (string) $this->mail[0]['message'] );
		preg_match( '/[?&]invite=([A-Za-z0-9_-]{43})/', (string) $this->mail[0]['message'], $matches );
		$token = $matches[1];

		$fields                 = $this->fields( 'invitee@example.test', '' );
		$fields['invite_token'] = $token;
		$this->assertTrue( $this->registration->register( $fields ) );

		$user = get_user_by( 'email', 'invitee@example.test' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( array( $org['org_id'] ), $this->organizations->org_ids_for_user( $user->ID ) );
		$this->assertContains( Roles::ADVERTISER, $user->roles );
		$this->assertCount( 2, $this->mail );

		$reused = $this->registration->register( $fields );
		$this->assertWPError( $reused );
		$this->assertSame( 'laao_ads_invalid_invitation', $reused->get_error_code() );
	}

	/** An invited existing account keeps its original role while gaining access. */
	public function test_existing_account_invitation_preserves_other_roles(): void {
		$org     = $this->make_org();
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'existing-invite@example.test',
			)
		);

		$this->assertTrue( $this->memberships->invite( $org['org_id'], 'existing-invite@example.test', $org['owner_id'] ) );
		preg_match( '/[?&]invite=([A-Za-z0-9_-]{43})/', (string) $this->mail[0]['message'], $matches );

		$fields                 = $this->fields( 'existing-invite@example.test', '' );
		$fields['invite_token'] = $matches[1];
		$this->assertTrue( $this->registration->register( $fields ) );

		$user = get_userdata( $user_id );
		$this->assertContains( 'editor', $user->roles );
		$this->assertContains( Roles::ADVERTISER, $user->roles );
		$this->assertSame( array( $org['org_id'] ), $this->organizations->org_ids_for_user( $user_id ) );
	}

	/** A stale invitation is rejected before any user or membership is created. */
	public function test_expired_invitation_cannot_be_consumed(): void {
		global $wpdb;

		$org = $this->make_org();
		$this->memberships->invite( $org['org_id'], 'expired@example.test', $org['owner_id'] );
		preg_match( '/[?&]invite=([A-Za-z0-9_-]{43})/', (string) $this->mail[0]['message'], $matches );
		$row = $this->access->invitation( $matches[1], 'expired@example.test' );
		$this->assertIsArray( $row );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security test forces token expiry without waiting three days.
		$wpdb->update(
			$this->access->table_name(),
			array( 'expires_at_ts' => time() - 1 ),
			array( 'id' => (int) $row['id'] ),
			array( '%d' ),
			array( '%d' )
		);

		$fields                 = $this->fields( 'expired@example.test', '' );
		$fields['invite_token'] = $matches[1];
		$result                 = $this->registration->register( $fields );

		$this->assertWPError( $result );
		$this->assertSame( 'laao_ads_invalid_invitation', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'expired@example.test' ) );
	}

	/** A token presented with a different email creates neither user nor access. */
	public function test_invitation_token_cannot_be_used_by_another_email(): void {
		$org = $this->make_org();
		$this->memberships->invite( $org['org_id'], 'intended@example.test', $org['owner_id'] );
		preg_match( '/[?&]invite=([A-Za-z0-9_-]{43})/', (string) $this->mail[0]['message'], $matches );

		$fields                 = $this->fields( 'wrong@example.test', '' );
		$fields['invite_token'] = $matches[1];
		$result                 = $this->registration->register( $fields );

		$this->assertWPError( $result );
		$this->assertSame( 'laao_ads_invalid_invitation', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'email', 'wrong@example.test' ) );
		$this->assertNull( $this->access->invitation( $matches[1], 'wrong@example.test' ) );
		$this->assertIsArray( $this->access->invitation( $matches[1], 'intended@example.test' ) );
	}

	/** Pending addresses are visible to the owner, never ordinary members. */
	public function test_pending_access_email_is_owner_only_view_data(): void {
		$org       = $this->make_org();
		$member_id = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->assertTrue( $this->organizations->add_member( $org['org_id'], $member_id ) );
		$this->assertTrue( $this->memberships->invite( $org['org_id'], 'private-pending@example.test', $org['owner_id'] ) );

		wp_set_current_user( $org['owner_id'] );
		$owner_data = $this->view->organization();
		$this->assertIsArray( $owner_data );
		$this->assertSame( 'private-pending@example.test', $owner_data['pending_access'][0]['email'] );

		wp_set_current_user( $member_id );
		$member_data = $this->view->organization();
		$this->assertIsArray( $member_data );
		$this->assertFalse( $member_data['can_manage_members'] );
		$this->assertSame( array(), $member_data['pending_access'] );
	}
}
