<?php
/**
 * What the portal screens are allowed to see.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\View_Data;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Roles;
use WP_UnitTestCase;

/**
 * View_Data against real WordPress, real roles and real ownership.
 *
 * The interesting assertions are all negative: what a signed-in advertiser
 * cannot see. Every one of these is a tenant-isolation failure if it regresses,
 * and none of them is visible from the screen itself — the template renders
 * whatever it is handed.
 */
final class PortalViewDataTest extends WP_UnitTestCase {

	/**
	 * The assembler under test.
	 *
	 * @var View_Data
	 */
	private View_Data $view;

	/**
	 * First organization.
	 *
	 * @var int
	 */
	private int $org_a;

	/**
	 * Second organization, with no relationship to the first.
	 *
	 * @var int
	 */
	private int $org_b;

	/**
	 * An advertiser who owns org A.
	 *
	 * @var int
	 */
	private int $advertiser_a;

	/**
	 * An advertiser who owns org B.
	 *
	 * @var int
	 */
	private int $advertiser_b;

	/**
	 * Sets up two organizations that must never see each other.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->view = Plugin::instance()->container()->get( View_Data::class );

		$this->advertiser_a = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->advertiser_b = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_a = $this->make_org( $this->advertiser_a );
		$this->org_b = $this->make_org( $this->advertiser_b );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * An organization owned by one user.
	 *
	 * @param int $owner Owning user id.
	 * @return int
	 */
	private function make_org( int $owner ): int {
		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $owner );

		return $org_id;
	}

	/**
	 * A campaign belonging to an organization.
	 *
	 * @param int    $org_id Owning organization.
	 * @param string $status Campaign status.
	 * @param string $title  Campaign title.
	 * @return int
	 */
	private function make_campaign( int $org_id, string $status, string $title ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $org_id );

		return $campaign_id;
	}

	/**
	 * The campaign list is scoped to the caller's own organization.
	 *
	 * @return void
	 */
	public function test_list_shows_only_the_callers_own_campaigns(): void {
		$mine   = $this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Mine' );
		$theirs = $this->make_campaign( $this->org_b, Post_Statuses::DRAFT, 'Theirs' );

		wp_set_current_user( $this->advertiser_a );

		$ids = array();

		foreach ( $this->view->campaigns()['rows'] as $row ) {
			$ids[] = (int) $row['id'];
		}

		$this->assertContains( $mine, $ids );
		$this->assertNotContains( $theirs, $ids, 'A campaign from another organization reached the list.' );
	}

	/**
	 * A signed-out visitor gets an empty list rather than an error.
	 *
	 * This is about shape, not isolation: the isolation is the org meta_query
	 * in Campaign_Repository::for_org(), which
	 * test_list_shows_only_the_callers_own_campaigns pins. What is asserted
	 * here is that a request with no organization returns the same array shape
	 * as any other, so the template's `count()` and `foreach` have something to
	 * work on instead of a fatal on the login-expired render.
	 *
	 * @return void
	 */
	public function test_a_signed_out_visitor_sees_no_campaigns(): void {
		$this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Mine' );

		wp_set_current_user( 0 );

		$result = $this->view->campaigns();

		$this->assertSame( array(), $result['rows'] );
		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * A campaign detail belonging to another organization is not returned.
	 *
	 * @return void
	 */
	public function test_detail_refuses_another_organizations_campaign(): void {
		$theirs = $this->make_campaign( $this->org_b, Post_Statuses::LIVE, 'Theirs' );

		wp_set_current_user( $this->advertiser_a );

		$this->assertNull( $this->view->campaign( $theirs ) );
	}

	/**
	 * A campaign detail belonging to the caller is returned in full.
	 *
	 * @return void
	 */
	public function test_detail_returns_the_callers_own_campaign(): void {
		$mine = $this->make_campaign( $this->org_a, Post_Statuses::LIVE, 'Spring push' );

		wp_set_current_user( $this->advertiser_a );

		$campaign = $this->view->campaign( $mine );

		$this->assertIsArray( $campaign );
		$this->assertSame( 'Spring push', $campaign['title'] );
		$this->assertSame( Post_Statuses::LIVE, $campaign['status'] );
		$this->assertSame( 'live', $campaign['pill'] );
		$this->assertSame( array(), $campaign['creatives'] );
	}

	/**
	 * Ids that are not campaigns are refused rather than rendered.
	 *
	 * The organization post is the interesting case: the caller genuinely may
	 * read it, so an authorization-only check would let it through and the
	 * screen would render an organization as a campaign.
	 *
	 * @return void
	 */
	public function test_detail_refuses_ids_that_are_not_campaigns(): void {
		wp_set_current_user( $this->advertiser_a );

		$this->assertNull( $this->view->campaign( 0 ) );
		$this->assertNull( $this->view->campaign( -1 ) );
		$this->assertNull( $this->view->campaign( PHP_INT_MAX ) );
		$this->assertNull( $this->view->campaign( $this->org_a ) );
	}

	/**
	 * The dashboard counts describe the caller's own campaigns only.
	 *
	 * @return void
	 */
	public function test_counts_are_scoped_and_classified(): void {
		$this->make_campaign( $this->org_a, Post_Statuses::LIVE, 'Running' );
		$this->make_campaign( $this->org_a, Post_Statuses::SUBMITTED, 'Waiting' );
		$this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Unfinished' );
		$this->make_campaign( $this->org_b, Post_Statuses::LIVE, 'Not theirs to count' );

		wp_set_current_user( $this->advertiser_a );

		$values = array();

		foreach ( $this->view->counts() as $stat ) {
			$values[] = $stat['value'];
		}

		$this->assertSame( array( 1, 1, 1 ), $values );
	}

	/**
	 * The organization name comes from the organization, not the campaign.
	 *
	 * @return void
	 */
	public function test_org_name_and_initials(): void {
		wp_set_current_user( $this->advertiser_a );

		$this->assertSame( 'Bright Angle Media', $this->view->org_name() );
		$this->assertSame( 'BA', $this->view->org_initials() );
	}

	/**
	 * With no organization there is a name to render, not a fatal.
	 *
	 * @return void
	 */
	public function test_a_user_without_an_organization_renders(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$this->assertSame( 0, $this->view->org_id() );
		$this->assertSame( '', $this->view->org_name() );
		$this->assertSame( '—', $this->view->org_initials() );
	}
}
