<?php
/**
 * The REST read paths.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Listing and reading campaigns, and listing placements.
 *
 * The two things worth proving: an advertiser sees only their own
 * organization's campaigns, and the response carries nothing internal.
 */
final class ReadRoutesTest extends WP_UnitTestCase {

	/**
	 * An advertiser.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * An advertiser in another organization.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * The owner's campaign.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * The stranger's campaign.
	 *
	 * @var int
	 */
	private int $other_campaign_id;

	/**
	 * Builds two organizations, each with a campaign.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->campaign_id       = $this->campaign_for( $this->owner, 'Spring Season' );
		$this->other_campaign_id = $this->campaign_for( $this->stranger, 'Someone Else' );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Creates an organization and a campaign for a user.
	 *
	 * @param int    $user_id Owning user.
	 * @param string $title   Campaign title.
	 * @return int
	 */
	private function campaign_for( int $user_id, string $title ): int {
		$org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org, Org_Repository::META_OWNER_USER, $user_id );

		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_title'  => $title,
				'post_author' => $user_id,
			)
		);

		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, $org );
		update_post_meta( $campaign, Campaign_Repository::META_REVIEW_NOTES, 'Visible feedback' );
		update_post_meta( $campaign, '_aggr_internal_notes', 'Staff only, never shown' );
		add_post_meta( $campaign, Campaign_Repository::META_ADSANITY_ID, 4242 );

		return $campaign;
	}

	/**
	 * Dispatches a GET.
	 *
	 * @param string $route Route path.
	 * @return \WP_REST_Response
	 */
	private function get( string $route ): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aggr/v1' . $route ) );
	}

	/**
	 * The read routes exist.
	 *
	 * @return void
	 */
	public function test_the_read_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/aggr/v1/campaigns', $routes );
		$this->assertArrayHasKey( '/aggr/v1/campaigns/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/aggr/v1/placements', $routes );
		$this->assertArrayHasKey( '/aggr/v1/packages', $routes );
		$this->assertArrayHasKey( '/laao-advertiser-portal/v1/campaigns', $routes );
		$this->assertArrayHasKey( '/laao-advertiser-portal/v1/campaigns/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/laao-advertiser-portal/v1/placements', $routes );
		$this->assertArrayHasKey( '/laao-advertiser-portal/v1/packages', $routes );
	}

	/**
	 * **A listing contains only the caller's own organization's campaigns.**
	 *
	 * @return void
	 */
	public function test_a_listing_is_scoped_to_the_callers_organization(): void {
		wp_set_current_user( $this->owner );

		$data = $this->get( '/campaigns' )->get_data();

		$ids = array_column( $data['campaigns'], 'id' );

		$this->assertContains( $this->campaign_id, $ids );
		$this->assertNotContains( $this->other_campaign_id, $ids, "Another organization's campaign was listed." );
		$this->assertSame( 1, $data['total'] );
	}

	/**
	 * A user in no organization sees nothing, not everything.
	 *
	 * @return void
	 */
	public function test_a_user_with_no_organization_sees_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$data = $this->get( '/campaigns' )->get_data();

		$this->assertSame( array(), $data['campaigns'] );
		$this->assertSame( 0, $data['total'] );
	}

	/**
	 * A campaign detail is readable by its owner.
	 *
	 * @return void
	 */
	public function test_the_owner_can_read_their_campaign(): void {
		wp_set_current_user( $this->owner );

		$response = $this->get( '/campaigns/' . $this->campaign_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Spring Season', $response->get_data()['title'] );
		$this->assertArrayHasKey( 'creatives', $response->get_data() );
	}

	/**
	 * **Another organization's campaign is 404, not 403.**
	 *
	 * @return void
	 */
	public function test_another_organizations_campaign_is_not_found(): void {
		wp_set_current_user( $this->owner );

		$forbidden = $this->get( '/campaigns/' . $this->other_campaign_id );
		$missing   = $this->get( '/campaigns/999999' );

		$this->assertSame( 404, $forbidden->get_status() );
		$this->assertSame(
			wp_json_encode( $forbidden->get_data() ),
			wp_json_encode( $missing->get_data() ),
			'A forbidden campaign answers differently from one that does not exist.'
		);
	}

	/**
	 * **Nothing internal reaches the advertiser.**
	 *
	 * Internal notes, the reviewer's identity and provider ad ids all live on
	 * the campaign this route just returned.
	 *
	 * @return void
	 */
	public function test_the_response_carries_nothing_internal(): void {
		wp_set_current_user( $this->owner );

		$body = (string) wp_json_encode( $this->get( '/campaigns/' . $this->campaign_id )->get_data() );

		foreach (
			array(
				'Staff only, never shown',
				'internal_notes',
				'adsanity',
				'adgroup',
				'4242',
				'reviewed_by',
			) as $secret
		) {
			$this->assertStringNotContainsString( $secret, $body, "Leaked: {$secret}" );
		}

		// Advertiser-visible feedback is the point of that field, and does show.
		$this->assertStringContainsString( 'Visible feedback', $body );
	}

	/**
	 * Campaign detail exposes safe readiness guidance without validator context.
	 *
	 * @return void
	 */
	public function test_campaign_detail_exposes_safe_review_readiness(): void {
		wp_set_current_user( $this->owner );

		$data      = $this->get( '/campaigns/' . $this->campaign_id )->get_data();
		$readiness = $data['readiness'];

		$this->assertFalse( $readiness['ready'] );
		$this->assertNotSame( array(), $readiness['problems'] );
		$this->assertSame( array( 'code', 'message', 'step', 'target' ), array_keys( $readiness['problems'][0] ) );
		$this->assertStringNotContainsString( 'context', (string) wp_json_encode( $readiness ) );
		$this->assertStringNotContainsString( 'https://', (string) wp_json_encode( $readiness ) );
	}

	/**
	 * The actions list reflects what this advertiser could actually do.
	 *
	 * @return void
	 */
	public function test_actions_reflect_the_transition_table(): void {
		wp_set_current_user( $this->owner );

		$data = $this->get( '/campaigns/' . $this->campaign_id )->get_data();

		$this->assertContains( Post_Statuses::SUBMITTED, $data['actions'] );
		$this->assertNotContains( Post_Statuses::APPROVED, $data['actions'] );
		$this->assertTrue( $data['editable'] );
	}

	/**
	 * Placements list, without any AdSanity terminology.
	 *
	 * @return void
	 */
	public function test_placements_never_expose_the_ad_group(): void {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);
		update_post_meta( $placement, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $placement, Placement_Repository::META_ADGROUP_TERM, 99 );

		wp_set_current_user( $this->owner );

		$response = $this->get( '/placements' );
		$body     = (string) wp_json_encode( $response->get_data() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Homepage Leaderboard', $body );
		$this->assertStringNotContainsString( 'adgroup', $body );
		$this->assertStringNotContainsString( '99', $body );

		$first = $response->get_data()['placements'][0];

		$this->assertSame( 728, $first['width'] );
		$this->assertSame( 90, $first['height'] );
		$this->assertNotSame( '', $first['slug'] );
	}

	/**
	 * Theme editors placing the slot block may read the catalogue without
	 * holding the advertiser portal cap.
	 *
	 * @return void
	 */
	public function test_editors_can_list_placements_for_the_block(): void {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Sidebar',
				'post_name'   => 'sidebar-300x250',
			)
		);
		update_post_meta( $placement, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement, Placement_Repository::META_SIZE, '300x250' );

		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$response = $this->get( '/placements' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'sidebar-300x250', $data['placements'][0]['slug'] );
		$this->assertFalse( user_can( $editor, Capabilities::ACCESS_PORTAL ) );
	}

	/**
	 * An inactive placement is not offered.
	 *
	 * @return void
	 */
	public function test_inactive_placements_are_not_listed(): void {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Retired Slot',
			)
		);
		update_post_meta( $placement, Placement_Repository::META_IS_ACTIVE, 0 );

		wp_set_current_user( $this->owner );

		$this->assertStringNotContainsString(
			'Retired Slot',
			(string) wp_json_encode( $this->get( '/placements' )->get_data() )
		);
	}

	/**
	 * Packages expose catalogue details but no provider mapping or malformed row.
	 *
	 * @return void
	 */
	public function test_packages_expose_only_valid_advertiser_facing_data(): void {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);
		update_post_meta( $placement, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $placement, Placement_Repository::META_ADGROUP_TERM, 9876 );

		$package = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Launch package',
			)
		);
		add_post_meta( $package, Package_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $package, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $package, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $package, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $package, Package_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $package, Package_Repository::META_IS_DEFAULT, 1 );

		$malformed = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Broken package',
			)
		);
		update_post_meta( $malformed, Package_Repository::META_IS_ACTIVE, 1 );

		wp_set_current_user( $this->owner );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$response = $this->get( '/packages' );
		$data     = $response->get_data();
		$body     = (string) wp_json_encode( $data );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data['packages'] );
		$this->assertSame( $package, $data['packages'][0]['id'] );
		$this->assertSame( 45000, $data['packages'][0]['price_cents'] );
		$this->assertTrue( $data['packages'][0]['is_default'] );
		$this->assertFalse( $data['packages'][0]['custom_duration'] );
		$this->assertStringNotContainsString( 'Broken package', $body );
		$this->assertStringNotContainsString( 'adgroup', $body );
		$this->assertStringNotContainsString( '9876', $body );
	}

	/**
	 * Logged-out visitors reach none of it.
	 *
	 * @return void
	 */
	public function test_logged_out_visitors_are_refused(): void {
		wp_set_current_user( 0 );

		foreach ( array( '/campaigns', '/campaigns/' . $this->campaign_id, '/placements', '/packages' ) as $route ) {
			$this->assertContains( $this->get( $route )->get_status(), array( 401, 403 ), $route );
		}
	}
}
