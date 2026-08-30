<?php
/**
 * What the portal screens are allowed to see.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
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
	 * **The picker never rejects the campaign's own start date.**
	 *
	 * `min` is enforced by the browser before the form reaches the server, so a
	 * minimum above the stored value makes the field invalid on load: the
	 * person cannot save the step, cannot reach the server's explanation, and
	 * sees only "Value must be … or later" with nothing behind it.
	 *
	 * A campaign drafted a week ago and edited today is exactly that case.
	 *
	 * @return void
	 */
	public function test_the_start_minimum_never_rejects_the_stored_date(): void {
		$mine = $this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Drafted a while ago' );

		$stale = ( new \DateTimeImmutable( '-8 days', wp_timezone() ) )->setTime( 0, 0 );

		update_post_meta( $mine, Campaign_Repository::META_START_TS, $stale->getTimestamp() );

		wp_set_current_user( $this->advertiser_a );

		$campaign = $this->view->campaign( $mine );

		$this->assertIsArray( $campaign );
		$this->assertSame( $stale->format( 'Y-m-d' ), $campaign['start_date'], 'The fixture must actually be stale.' );
		$this->assertSame(
			$campaign['start_date'],
			$campaign['min_start_date'],
			'The picker would have refused the value already in the field.'
		);
	}

	/**
	 * With no start yet, the minimum is today — so a campaign can begin now.
	 *
	 * @return void
	 */
	public function test_a_campaign_with_no_start_may_begin_today(): void {
		$mine = $this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Nothing chosen yet' );

		wp_set_current_user( $this->advertiser_a );

		$campaign = $this->view->campaign( $mine );

		$this->assertIsArray( $campaign );
		$this->assertSame( '', $campaign['start_date'] );
		$this->assertSame(
			(string) wp_date( 'Y-m-d', time(), wp_timezone() ),
			$campaign['min_start_date']
		);
	}

	/**
	 * A future start does not drag the minimum forward with it.
	 *
	 * The third case, and the one that keeps the rule a `min()` rather than
	 * "whatever is stored": somebody who picked next month must still be able
	 * to move it earlier, to today.
	 *
	 * @return void
	 */
	public function test_a_future_start_leaves_the_minimum_at_today(): void {
		$mine = $this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Booked ahead' );

		$ahead = ( new \DateTimeImmutable( '+30 days', wp_timezone() ) )->setTime( 0, 0 );

		update_post_meta( $mine, Campaign_Repository::META_START_TS, $ahead->getTimestamp() );

		wp_set_current_user( $this->advertiser_a );

		$campaign = $this->view->campaign( $mine );

		$this->assertIsArray( $campaign );
		$this->assertSame( $ahead->format( 'Y-m-d' ), $campaign['start_date'] );
		$this->assertSame(
			(string) wp_date( 'Y-m-d', time(), wp_timezone() ),
			$campaign['min_start_date'],
			'A campaign booked ahead could no longer be moved earlier.'
		);
	}

	/**
	 * Review readiness reports every safe, actionable problem on an invalid draft.
	 *
	 * @return void
	 */
	public function test_detail_includes_safe_aggregated_review_problems(): void {
		$mine = $this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Incomplete review' );

		wp_set_current_user( $this->advertiser_a );

		$campaign = $this->view->campaign( $mine );

		$this->assertIsArray( $campaign );
		$this->assertFalse( $campaign['readiness']['ready'] );
		$this->assertSame(
			array( 'package_missing', 'start_date_missing', 'no_placements', 'no_creatives' ),
			array_column( $campaign['readiness']['problems'], 'code' )
		);
		$locations = array();

		foreach ( $campaign['readiness']['problems'] as $problem ) {
			$this->assertSame( array( 'code', 'message', 'step', 'target' ), array_keys( $problem ) );
			$this->assertNotSame( '', $problem['message'] );
			$locations[ $problem['code'] ] = array( $problem['step'], $problem['target'] );
		}

		$this->assertSame(
			array(
				'package_missing'    => array( 'package', 'aggr-packages' ),
				'start_date_missing' => array( 'destination', 'aggr-start-date' ),
				'no_placements'      => array( 'package', 'aggr-packages' ),
				'no_creatives'       => array( 'creative', 'aggr-details-heading' ),
			),
			$locations
		);
		$this->assertStringNotContainsString( 'context', (string) wp_json_encode( $campaign['readiness'] ) );
	}

	/**
	 * A complete campaign has one canonical ready state and no residual issues.
	 *
	 * @return void
	 */
	public function test_detail_marks_a_valid_campaign_ready_for_review(): void {
		$mine         = $this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Ready review' );
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );
		add_post_meta( $mine, Campaign_Repository::META_PLACEMENT_ID, $placement_id );

		$start = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->setTime( 0, 0, 0 );
		$end   = ( new \DateTimeImmutable( '+20 days', wp_timezone() ) )->setTime( 23, 59, 59 );

		update_post_meta( $mine, Campaign_Repository::META_START_TS, $start->getTimestamp() );
		update_post_meta( $mine, Campaign_Repository::META_END_TS, $end->getTimestamp() );

		$this->attach_package( $mine, array( $placement_id ) );

		$creative_id = Plugin::instance()->container()->get( Creative_Repository::class )->create(
			$mine,
			$this->org_a,
			$placement_id,
			array(
				'kind'      => 'image',
				'click_url' => 'https://example.com/exhibition',
				'alt_text'  => 'Visitors viewing an exhibition',
				'size'      => '728x90',
			)
		);
		$this->assertGreaterThan( 0, $creative_id );
		update_post_meta( $creative_id, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative_id, Creative_Repository::META_HEIGHT, 90 );

		wp_set_current_user( $this->advertiser_a );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$campaign = $this->view->campaign( $mine );

		$this->assertIsArray( $campaign );
		$this->assertTrue( $campaign['readiness']['ready'] );
		$this->assertSame( array(), $campaign['readiness']['problems'] );
	}

	/**
	 * The package step receives only valid catalogue options and formatted data.
	 *
	 * @return void
	 */
	public function test_detail_includes_valid_package_catalogue_options(): void {
		$mine         = $this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Package draft' );
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		$package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Launch package',
			)
		);

		add_post_meta( $package_id, Package_Repository::META_PLACEMENT_ID, $placement_id );
		update_post_meta( $package_id, Package_Repository::META_DURATION_DAYS, 0 );
		update_post_meta( $package_id, Package_Repository::META_CUSTOM_DURATION, 1 );
		update_post_meta( $package_id, Package_Repository::META_IS_DEFAULT, 1 );
		update_post_meta( $package_id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $package_id, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $package_id, Package_Repository::META_IS_ACTIVE, 1 );

		wp_set_current_user( $this->advertiser_a );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$campaign = $this->view->campaign( $mine );

		$this->assertIsArray( $campaign );
		$this->assertCount( 1, $campaign['package_options'] );
		$this->assertSame( $package_id, $campaign['package_options'][0]['id'] );
		$this->assertSame( 'USD 450.00', $campaign['package_options'][0]['price'] );
		$this->assertSame( 'Custom schedule', $campaign['package_options'][0]['duration'] );
		$this->assertTrue( $campaign['package_options'][0]['is_default'] );
		$this->assertSame( array( 'Homepage Leaderboard (728x90 px)' ), $campaign['package_options'][0]['placements'] );
	}

	/**
	 * Creative slots contain safe display data and an authenticated file route.
	 *
	 * @return void
	 */
	public function test_detail_includes_safe_creative_upload_slots(): void {
		$mine         = $this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Creative draft' );
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );
		add_post_meta( $mine, Campaign_Repository::META_PLACEMENT_ID, $placement_id );

		$creatives   = Plugin::instance()->container()->get( Creative_Repository::class );
		$creative_id = $creatives->create(
			$mine,
			$this->org_a,
			$placement_id,
			array(
				'kind'      => 'image',
				'click_url' => 'https://example.com/exhibition',
				'alt_text'  => 'Visitors in a gallery',
				'size'      => '728x90',
			)
		);
		$this->assertGreaterThan( 0, $creative_id );

		$creatives->record_upload(
			$creative_id,
			array(
				'path'   => 'private-artwork.png',
				'token'  => 'server-secret-token',
				'sha256' => str_repeat( 'a', 64 ),
				'bytes'  => 2048,
				'mime'   => 'image/png',
				'width'  => 728,
				'height' => 90,
				'name'   => 'fall-gallery.png',
			)
		);

		wp_set_current_user( $this->advertiser_a );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$campaign = $this->view->campaign( $mine );

		$this->assertIsArray( $campaign );
		$this->assertCount( 1, $campaign['creative_slots'] );
		$this->assertSame( $placement_id, $campaign['creative_slots'][0]['id'] );
		$this->assertSame( '728x90', $campaign['creative_slots'][0]['size'] );
		$this->assertCount( 1, $campaign['creative_slots'][0]['creatives'] );

		$creative = $campaign['creative_slots'][0]['creatives'][0];
		$this->assertSame( 'fall-gallery.png', $creative['name'] );
		$this->assertSame( 2048, $creative['bytes'] );
		$this->assertStringContainsString( '/creatives/' . $creative_id . '/file', urldecode( $creative['preview'] ) );
		$this->assertStringContainsString( '_wpnonce=', $creative['preview'] );
		$this->assertArrayNotHasKey( 'path', $creative );

		/*
		 * Once approved the artwork moves to the Media Library and the private
		 * original is deleted, so the authenticated route answers 404 — the
		 * campaign screen has to follow the attachment or every approved
		 * creative renders as a broken image, which is how this was found.
		 */
		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'fall-gallery.png',
				'post_mime_type' => 'image/png',
			)
		);

		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );
		$creatives->clear_private_file( $creative_id );

		$approved = $this->view->campaign( $mine )['creative_slots'][0]['creatives'][0];

		$this->assertTrue( $approved['approved'] );
		$this->assertSame(
			wp_get_attachment_url( $attachment_id ),
			$approved['preview'],
			'An approved creative still pointed at the deleted private original.'
		);
		$this->assertStringNotContainsString(
			'/file',
			$approved['preview'],
			'An approved creative was sent to a route that can only answer 404.'
		);
		$this->assertArrayNotHasKey( 'token', $creative );
		$this->assertStringNotContainsString( 'private-artwork.png', wp_json_encode( $campaign ) );
		$this->assertStringNotContainsString( 'server-secret-token', wp_json_encode( $campaign ) );
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
	 * Metric fields stay absent until both reporting modules are on.
	 *
	 * A row of zeros would look like "nobody saw this ad" while native
	 * delivery is off. The keys must not exist at all.
	 *
	 * @return void
	 */
	public function test_metric_fields_are_absent_by_default(): void {
		$mine = $this->make_campaign( $this->org_a, Post_Statuses::LIVE, 'Running' );

		wp_set_current_user( $this->advertiser_a );

		$list   = $this->view->campaigns();
		$detail = $this->view->campaign( $mine );

		$this->assertFalse( $list['show_metrics'] );
		$this->assertArrayNotHasKey( 'impressions', $list['rows'][0] );
		$this->assertArrayNotHasKey( 'clicks', $list['rows'][0] );
		$this->assertIsArray( $detail );
		$this->assertArrayNotHasKey( 'impressions', $detail );
		$this->assertSame( array(), $this->view->delivery_counts() );
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

	/**
	 * Attaches an active package and its price snapshot to a campaign.
	 *
	 * @param int             $campaign_id   Campaign post id.
	 * @param array<int, int> $placement_ids Placements the package covers.
	 * @return int
	 */
	private function attach_package( int $campaign_id, array $placement_ids ): int {
		$package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Launch package',
			)
		);

		update_post_meta( $package_id, Package_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $package_id, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $package_id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $package_id, Package_Repository::META_CURRENCY, 'USD' );

		foreach ( $placement_ids as $placement_id ) {
			add_post_meta( $package_id, Package_Repository::META_PLACEMENT_ID, $placement_id );
		}

		update_post_meta( $campaign_id, Campaign_Repository::META_PACKAGE_ID, $package_id );
		update_post_meta( $campaign_id, Campaign_Repository::META_BUDGET_CENTS, 45000 );
		update_post_meta( $campaign_id, Campaign_Repository::META_CURRENCY, 'USD' );

		return $package_id;
	}
}
