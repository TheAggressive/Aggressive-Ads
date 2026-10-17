<?php
/**
 * Why each pending invitation and access request is on the organization screen.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Pending_Access_View_Data;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The names a row may carry, and the ones it must never.
 *
 * The risk is not a missing hint, it is a hint that names someone who is not in
 * the organization — a staff member, or a member of another one — to an owner
 * who has no business learning it. So every case that names a person has a
 * twin that proves who is *not* named.
 */
final class OrganizationPendingContextTest extends WP_UnitTestCase {

	/**
	 * Organization lookups.
	 *
	 * @var Org_Repository
	 */
	private Org_Repository $orgs;

	/**
	 * Pending access persistence.
	 *
	 * @var Org_Access_Repository
	 */
	private Org_Access_Repository $access;

	/**
	 * The organization's owner.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * The organization.
	 *
	 * @var int
	 */
	private int $org;

	/**
	 * A member the owner is not.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Builds an organization with an owner and one named member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container    = Plugin::instance()->container();
		$this->orgs   = $container->get( Org_Repository::class );
		$this->access = $container->get( Org_Access_Repository::class );

		$this->owner = self::factory()->user->create(
			array(
				'role'         => Roles::ADVERTISER,
				'display_name' => 'Olive Owner',
			)
		);
		$org         = $this->orgs->create_for_owner( 'Bright Angle Media', $this->owner );
		$this->assertIsInt( $org );
		$this->org = $org;

		$this->member = self::factory()->user->create(
			array(
				'role'         => Roles::ADVERTISER,
				'display_name' => 'Morgan Reyes',
			)
		);
		$this->assertTrue( $this->orgs->add_member( $this->org, $this->member ) );

		wp_set_current_user( $this->owner );
	}

	/**
	 * The one pending row, as the owner's screen receives it.
	 *
	 * @return array<string, mixed>
	 */
	private function only_row(): array {
		$data = Plugin::instance()->container()->get( View_Data::class )->organization();

		$this->assertIsArray( $data );
		$this->assertCount( 1, $data['pending_access'] );

		return $data['pending_access'][0];
	}

	/**
	 * A request from someone whose address is `$email`.
	 *
	 * @param string $email Address on the request.
	 * @param string $name  Requester's display name.
	 * @return void
	 */
	private function request_from( string $email, string $name = 'gm123' ): void {
		$user = self::factory()->user->create(
			array(
				'role'         => Roles::ADVERTISER,
				'user_email'   => $email,
				'display_name' => $name,
			)
		);

		$this->assertIsInt( $this->access->create_request( $this->org, $email, $user ) );
	}

	public function test_an_invitation_the_viewer_sent_says_so(): void {
		$this->assertIsArray( $this->access->create_invite( $this->org, 'new@example.test', $this->owner ) );

		$row = $this->only_row();

		$this->assertTrue( $row['invited_by_you'] );
		$this->assertSame( '', $row['invited_by'], 'The viewer is "you", never their own name.' );
		$this->assertSame( '', $row['name_match'] );
	}

	public function test_an_invitation_a_member_sent_names_them(): void {
		$this->assertIsArray( $this->access->create_invite( $this->org, 'new@example.test', $this->member ) );

		$row = $this->only_row();

		$this->assertFalse( $row['invited_by_you'] );
		$this->assertSame( 'Morgan Reyes', $row['invited_by'] );
	}

	public function test_an_invitation_staff_sent_never_names_them(): void {
		$staff = self::factory()->user->create(
			array(
				'role'         => Roles::REVIEWER,
				'display_name' => 'Reva Reviewer',
			)
		);
		$this->assertIsArray( $this->access->create_invite( $this->org, 'new@example.test', $staff ) );

		$row = $this->only_row();

		$this->assertSame( Pending_Access_View_Data::INVITER_SITE_TEAM, $row['invited_by'] );
		$this->assertStringNotContainsString( 'Reva', (string) wp_json_encode( $row ), 'A person outside the organization must not be named.' );
	}

	public function test_an_invitation_with_no_recorded_sender_claims_nobody(): void {
		$this->assertIsArray( $this->access->create_invite( $this->org, 'new@example.test', 0 ) );

		$row = $this->only_row();

		$this->assertSame( '', $row['invited_by'] );
		$this->assertFalse( $row['invited_by_you'] );
	}

	public function test_a_request_that_looks_like_a_member_names_that_member(): void {
		$this->request_from( 'morgan.reyes@gmail.com' );

		$row = $this->only_row();

		$this->assertSame( 'Morgan Reyes', $row['name_match'] );
		$this->assertSame( '', $row['invited_by'] );
		$this->assertFalse( $row['invited_by_you'] );
	}

	public function test_a_request_that_looks_like_nobody_here_names_nobody(): void {
		$this->request_from( 'someone.else@gmail.com' );

		$this->assertSame( '', $this->only_row()['name_match'] );
	}

	public function test_a_member_of_another_organization_is_never_suggested(): void {
		$other_owner = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$other       = $this->orgs->create_for_owner( 'Elsewhere Ltd', $other_owner );
		$this->assertIsInt( $other );
		$stranger = self::factory()->user->create(
			array(
				'role'         => Roles::ADVERTISER,
				'display_name' => 'Casey Doe',
			)
		);
		$this->assertTrue( $this->orgs->add_member( $other, $stranger ) );

		$this->request_from( 'casey.doe@gmail.com' );

		$row = $this->only_row();

		$this->assertSame( '', $row['name_match'] );
		$this->assertStringNotContainsString( 'Casey', (string) wp_json_encode( $row ) );
	}

	public function test_a_member_with_a_stale_request_is_not_matched_to_themselves(): void {
		// A request row can outlive the approval that made its sender a member.
		$this->assertIsInt( $this->access->create_request( $this->org, 'morgan.reyes@gmail.com', $this->member ) );

		$this->assertSame( '', $this->only_row()['name_match'], 'Someone already in is not "matching" themselves.' );
	}

	public function test_a_suggestion_never_approves_anyone(): void {
		$this->request_from( 'morgan.reyes@gmail.com' );

		$this->assertSame( 'Morgan Reyes', $this->only_row()['name_match'] );
		$this->assertCount( 2, $this->orgs->user_ids_for_org( $this->org ), 'A matching name must not have added a member.' );
	}

	public function test_the_screen_words_each_reason_as_a_hint_and_nothing_else(): void {
		$this->request_from( 'morgan.reyes@gmail.com' );
		$this->assertIsArray( $this->access->create_invite( $this->org, 'by-you@example.test', $this->owner ) );
		$this->assertIsArray( $this->access->create_invite( $this->org, 'by-member@example.test', $this->member ) );

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/screens/organization.php';
		$html = (string) ob_get_clean();
		$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) );

		$this->assertStringContainsString( 'Asked to join · matches the name “Morgan Reyes”', (string) $text );
		$this->assertStringContainsString( 'Invited by you', (string) $text );
		$this->assertStringContainsString( 'Invited by Morgan Reyes', (string) $text );
		$this->assertSame( 1, substr_count( (string) $text, 'matches the name' ), 'Only the request carries a name match.' );
	}
}
