<?php
/**
 * Organization-scoped object authorization.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Security;

use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Roles;
use WP_UnitTestCase;

/**
 * The IDOR surface, exercised through the real capability pipeline.
 *
 * These cannot be written against mocks. The question is not "does our method
 * return the right array" — it is whether core's map_meta_cap, with our filter
 * attached at priority 10 taking four arguments, denies advertiser B on
 * advertiser A's campaign. Answering that needs a real WP_User, a real
 * $wp_filter and real map_meta_cap().
 */
final class OwnershipTest extends WP_UnitTestCase {

	/**
	 * Organization A.
	 *
	 * @var int
	 */
	private int $org_a;

	/**
	 * Organization B.
	 *
	 * @var int
	 */
	private int $org_b;

	/**
	 * A user belonging to organization A.
	 *
	 * @var int
	 */
	private int $user_a;

	/**
	 * A second user belonging to organization A.
	 *
	 * @var int
	 */
	private int $user_a2;

	/**
	 * A user belonging to organization B.
	 *
	 * @var int
	 */
	private int $user_b;

	/**
	 * A staff reviewer, belonging to no organization.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Campaign owned by organization A.
	 *
	 * @var int
	 */
	private int $campaign_a;

	/**
	 * Campaign owned by organization B.
	 *
	 * @var int
	 */
	private int $campaign_b;

	/**
	 * Builds two organizations, their users, and a campaign each.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new \LAAO_Advertiser_Portal\Install\Installer(
			new \LAAO_Advertiser_Portal\Repository\Audit_Repository(),
			new Roles()
		) )->install_roles();

		$this->user_a   = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->user_a2  = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->user_b   = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		$this->org_a = $this->create_org( $this->user_a, array( $this->user_a2 ) );
		$this->org_b = $this->create_org( $this->user_b, array() );

		$this->campaign_a = $this->create_campaign( $this->org_a, $this->user_a );
		$this->campaign_b = $this->create_campaign( $this->org_b, $this->user_b );

		$this->flush_ownership_cache();
	}

	/**
	 * Creates an organization with an owner and members.
	 *
	 * @param int             $owner   Owning user id.
	 * @param array<int, int> $members Additional member user ids.
	 * @return int
	 */
	private function create_org( int $owner, array $members ): int {
		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Org ' . $owner,
			)
		);

		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $owner );

		foreach ( $members as $member ) {
			add_post_meta( $org_id, Org_Repository::META_MEMBER_USER, $member );
		}

		return $org_id;
	}

	/**
	 * Creates a campaign belonging to an organization.
	 *
	 * @param int $org_id Owning organization.
	 * @param int $author Authoring user.
	 * @return int
	 */
	private function create_campaign( int $org_id, int $author ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => \LAAO_Advertiser_Portal\Core\Post_Statuses::DRAFT,
				'post_author' => $author,
				'post_title'  => 'Campaign for org ' . $org_id,
			)
		);

		update_post_meta( $campaign_id, Org_Repository::META_ORG_ID, $org_id );

		return $campaign_id;
	}

	/**
	 * Drops memoized membership between arrangement and assertion.
	 *
	 * @return void
	 */
	private function flush_ownership_cache(): void {
		\LAAO_Advertiser_Portal\Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * The filter is actually attached.
	 *
	 * A test that only calls the method proves the method is correct. It does
	 * not prove the method runs — and a refactor that drops the add_filter
	 * leaves every behavioural test green with the guard entirely absent.
	 *
	 * @return void
	 */
	public function test_the_filter_is_registered(): void {
		$this->assertSame(
			10,
			has_filter(
				'map_meta_cap',
				array( \LAAO_Advertiser_Portal\Plugin::instance()->container()->get( Ownership::class ), 'map' )
			)
		);
	}

	/**
	 * An advertiser may edit their own organization's campaign.
	 *
	 * @return void
	 */
	public function test_a_member_may_edit_their_own_campaign(): void {
		wp_set_current_user( $this->user_a );

		$this->assertTrue( current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ) );
	}

	/**
	 * **A different member of the same organization may edit it too.**
	 *
	 * This is the assertion that proves ownership is organizational rather than
	 * accidentally author-scoped. A pure-denial suite passes just as happily
	 * against a broken single-user implementation, because the only thing it
	 * ever checks is that strangers are refused — and core's author comparison
	 * refuses them correctly, for the wrong reason.
	 *
	 * @return void
	 */
	public function test_a_co_member_may_edit_a_campaign_they_did_not_author(): void {
		$this->assertNotSame( $this->user_a, $this->user_a2 );
		$this->assertSame( $this->user_a, (int) get_post_field( 'post_author', $this->campaign_a ) );

		wp_set_current_user( $this->user_a2 );

		$this->assertTrue(
			current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ),
			'A co-member cannot edit their organization\'s campaign; ownership has collapsed to the author.'
		);
	}

	/**
	 * An advertiser may not touch another organization's campaign.
	 *
	 * @param string $capability The meta capability under test.
	 * @return void
	 *
	 * @dataProvider data_object_capabilities
	 */
	public function test_an_advertiser_is_denied_on_another_org_campaign( string $capability ): void {
		wp_set_current_user( $this->user_a );

		$this->assertFalse(
			current_user_can( $capability, $this->campaign_b ),
			"{$capability} was granted across organizations."
		);
	}

	/**
	 * The three meta capabilities.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_object_capabilities(): array {
		return array(
			'edit'   => array( 'edit_laao_ads_campaign' ),
			'read'   => array( 'read_laao_ads_campaign' ),
			'delete' => array( 'delete_laao_ads_campaign' ),
		);
	}

	/**
	 * The denial is symmetric — B cannot reach A either.
	 *
	 * @return void
	 */
	public function test_the_denial_is_symmetric(): void {
		wp_set_current_user( $this->user_b );

		$this->assertFalse( current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ) );
		$this->assertTrue( current_user_can( 'edit_laao_ads_campaign', $this->campaign_b ) );
	}

	/**
	 * A reviewer reaches every organization, which is what a queue requires.
	 *
	 * @return void
	 */
	public function test_a_reviewer_may_reach_any_organization(): void {
		wp_set_current_user( $this->reviewer );

		$this->assertTrue( current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ) );
		$this->assertTrue( current_user_can( 'edit_laao_ads_campaign', $this->campaign_b ) );
		$this->assertTrue( current_user_can( 'read_laao_ads_campaign', $this->campaign_b ) );
	}

	/**
	 * A deleted object denies rather than defaulting.
	 *
	 * If the object cannot be loaded, falling through to core means comparing
	 * $post->post_author on a null post — a comparison that can grant.
	 *
	 * @return void
	 */
	public function test_a_deleted_object_is_denied(): void {
		wp_set_current_user( $this->user_a );

		wp_delete_post( $this->campaign_a, true );
		$this->flush_ownership_cache();

		$this->assertFalse( current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ) );
	}

	/**
	 * An id that never existed denies too.
	 *
	 * @return void
	 */
	public function test_a_nonexistent_object_is_denied(): void {
		wp_set_current_user( $this->user_a );

		$this->assertFalse( current_user_can( 'edit_laao_ads_campaign', 999999 ) );
		$this->assertFalse( current_user_can( 'edit_laao_ads_campaign', 0 ) );
	}

	/**
	 * A creative is authorized by its own organization, like everything else.
	 *
	 * Worth knowing while reading this: a meta capability is resolved by the
	 * **object's** post type, not by the name used to ask. core looks the
	 * custom name up in $post_type_meta_caps and recurses with the generic
	 * 'edit_post', at which point the requested type is gone — so
	 * current_user_can( 'edit_laao_ads_campaign', $creative_id ) authorizes the
	 * creative. That is core's behaviour and it is not a bypass: the check is
	 * against the object, and the object is checked correctly.
	 *
	 * @return void
	 */
	public function test_a_creative_is_authorized_by_its_own_organization(): void {
		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $creative_id, Org_Repository::META_ORG_ID, $this->org_a );
		$this->flush_ownership_cache();

		wp_set_current_user( $this->user_a );
		$this->assertTrue( current_user_can( 'edit_laao_ads_creative', $creative_id ) );

		wp_set_current_user( $this->user_a2 );
		$this->assertTrue(
			current_user_can( 'edit_laao_ads_creative', $creative_id ),
			'A co-member cannot edit their organization\'s creative.'
		);

		wp_set_current_user( $this->user_b );
		$this->assertFalse(
			current_user_can( 'edit_laao_ads_creative', $creative_id ),
			'Another organization reached this creative.'
		);
		$this->assertFalse( current_user_can( 'read_laao_ads_creative', $creative_id ) );
	}

	/**
	 * An organization authorizes itself: its own post id is its org id.
	 *
	 * @return void
	 */
	public function test_an_organization_is_its_own_owner(): void {
		wp_set_current_user( $this->user_a );

		$this->assertTrue( current_user_can( 'read_laao_ads_org', $this->org_a ) );
		$this->assertFalse( current_user_can( 'read_laao_ads_org', $this->org_b ) );
	}

	/**
	 * An advertiser can read the shared configuration they need.
	 *
	 * Placements and packages carry no owning organization. Resolving them
	 * through an org comparison denies every advertiser — including on the
	 * wizard screen whose entire job is choosing among them.
	 *
	 * @return void
	 */
	public function test_an_advertiser_can_read_shared_configuration(): void {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( $this->user_a );

		$this->assertTrue( current_user_can( 'read_laao_ads_placement', $placement_id ) );
		$this->assertTrue( current_user_can( 'read_laao_ads_package', $package_id ) );
	}

	/**
	 * Reading shared configuration is not editing it.
	 *
	 * Remapping a placement publishes ads into a different slot on a public
	 * site, so it takes the management capability — which neither advertisers
	 * nor reviewers hold.
	 *
	 * @return void
	 */
	public function test_nobody_but_an_administrator_may_edit_shared_configuration(): void {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( $this->user_a );
		$this->assertFalse( current_user_can( 'edit_laao_ads_placement', $placement_id ) );

		wp_set_current_user( $this->reviewer );
		$this->assertFalse( current_user_can( 'edit_laao_ads_placement', $placement_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'edit_laao_ads_placement', $placement_id ) );
	}

	/**
	 * An advertiser holds no primitive that would reach another organization.
	 *
	 * The deny gate in the filter and the role matrix are two independent
	 * layers over the same risk. This asserts the second one directly, because
	 * a test that only exercises the gate cannot tell you whether removing it
	 * would be survivable — and for organizations specifically, it would not.
	 *
	 * @return void
	 */
	public function test_an_advertiser_holds_no_cross_org_read_primitive(): void {
		$user = get_userdata( $this->user_a );

		$this->assertNotFalse( $user );
		$this->assertArrayNotHasKey( 'read_private_laao_ads_orgs', array_filter( $user->allcaps ) );
		$this->assertArrayNotHasKey( 'read_private_laao_ads_campaigns', array_filter( $user->allcaps ) );
		$this->assertArrayNotHasKey( 'read_private_laao_ads_creatives', array_filter( $user->allcaps ) );
	}

	/**
	 * A broad capability granted from outside does not cross organizations.
	 *
	 * This is the layer the role matrix cannot provide. Another plugin, a
	 * bulk-role editor, or a future role of our own can grant
	 * read_private_laao_ads_campaigns to somebody — and the moment that
	 * happens, "they do not hold the primitive" stops being the thing keeping
	 * one customer out of another's data. The membership gate runs *before*
	 * primitives are consulted, so it denies regardless.
	 *
	 * Threat-model surface 11: a user holding a staff-ish capability from an
	 * unrelated plugin must not see the whole queue.
	 *
	 * @return void
	 */
	public function test_a_broad_capability_from_elsewhere_does_not_cross_organizations(): void {
		$user = get_user_by( 'id', $this->user_a );
		$this->assertNotFalse( $user );

		// Something outside this plugin grants the cross-organization
		// primitives directly.
		$user->add_cap( 'read_private_laao_ads_campaigns' );
		$user->add_cap( 'edit_others_laao_ads_campaigns' );

		wp_set_current_user( $this->user_a );

		$this->assertTrue(
			current_user_can( 'read_private_laao_ads_campaigns' ),
			'Test precondition: the capability was not actually granted.'
		);

		$this->assertFalse(
			current_user_can( 'read_laao_ads_campaign', $this->campaign_b ),
			'A capability granted outside the plugin reached another organization\'s campaign.'
		);
		$this->assertFalse(
			current_user_can( 'edit_laao_ads_campaign', $this->campaign_b ),
			'A capability granted outside the plugin allowed editing another organization\'s campaign.'
		);

		// Still fine on their own.
		$this->assertTrue( current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ) );
	}

	/**
	 * Invoked directly with one of our own capability names, the filter still
	 * answers — and still refuses a mismatched post type.
	 *
	 * WordPress never dispatches this path: it recurses with the generic
	 * 'edit_post' instead, so the branch is unreachable through
	 * current_user_can(). It is kept because a dispatch change would otherwise
	 * silently return the filter to being inert, which is the exact defect this
	 * class was written with. Kept code in a security class gets tested, or it
	 * rots into a protection nobody has ever seen work.
	 *
	 * @return void
	 */
	public function test_the_filter_answers_when_called_with_our_own_capability_name(): void {
		$ownership = \LAAO_Advertiser_Portal\Plugin::instance()->container()->get( Ownership::class );

		$granted = $ownership->map(
			array( 'edit_laao_ads_campaign' ),
			'edit_laao_ads_campaign',
			$this->user_a,
			array( $this->campaign_a )
		);

		$this->assertSame( array( 'edit_laao_ads_campaigns' ), $granted );

		$cross_org = $ownership->map(
			array( 'edit_laao_ads_campaign' ),
			'edit_laao_ads_campaign',
			$this->user_b,
			array( $this->campaign_a )
		);

		$this->assertSame( array( 'do_not_allow' ), $cross_org );

		// A campaign capability aimed at an organization's id.
		$mismatched = $ownership->map(
			array( 'edit_laao_ads_campaign' ),
			'edit_laao_ads_campaign',
			$this->user_a,
			array( $this->org_a )
		);

		$this->assertSame( array( 'do_not_allow' ), $mismatched );
	}

	/**
	 * A logged-out visitor is denied everything.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_is_denied(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ) );
		$this->assertFalse( current_user_can( 'read_laao_ads_campaign', $this->campaign_a ) );
	}

	/**
	 * The filter is inert for capabilities it does not own.
	 *
	 * It runs on every capability check in WordPress, core's included. A filter
	 * that returned anything for an unrelated capability would be a
	 * site-breaking bug reported as "the media library stopped working".
	 *
	 * @return void
	 */
	public function test_the_filter_is_inert_for_unrelated_capabilities(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$post_id = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
		$this->assertTrue( current_user_can( 'upload_files' ) );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
	}

	/**
	 * Adding a member mid-request takes effect immediately.
	 *
	 * Memberships are memoized because map_meta_cap fires dozens of times per
	 * render. A memo that is never invalidated answers with a stale membership
	 * set for the rest of the request.
	 *
	 * @return void
	 */
	public function test_membership_changes_are_visible_within_the_request(): void {
		wp_set_current_user( $this->user_b );

		$this->assertFalse( current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ) );

		add_post_meta( $this->org_a, Org_Repository::META_MEMBER_USER, $this->user_b );

		$this->assertTrue(
			current_user_can( 'edit_laao_ads_campaign', $this->campaign_a ),
			'A membership added during the request was not visible; the memo is not invalidated.'
		);
	}
}
