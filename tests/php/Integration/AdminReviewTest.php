<?php
/**
 * The staff review workflow against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Review_Data;
use Aggressive\Ads\Admin\Review_Screen;
use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_Error;
use WP_UnitTestCase;

/**
 * Proves staff can review across organizations without exposing the surface to
 * advertisers or leaking the private-storage implementation into the page.
 */
final class AdminReviewTest extends WP_UnitTestCase {

	/**
	 * Review data presenter under test.
	 *
	 * @var Review_Data
	 */
	private Review_Data $data;

	/**
	 * Review screen controller under test.
	 *
	 * @var Review_Screen
	 */
	private Review_Screen $screen;

	/**
	 * Audit repository used for assertions.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Campaign repository used for assertions.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

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
	 * Fixture organization post id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Installs roles and creates one organization.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit     = new Audit_Repository();
		$this->campaigns = new Campaign_Repository();

		( new Installer( $this->audit, new Roles() ) )->install_roles();

		$this->reviewer   = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id     = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$this->data   = Plugin::instance()->container()->get( Review_Data::class );
		$this->screen = Plugin::instance()->container()->get( Review_Screen::class );
	}

	/**
	 * Clears request globals changed by form and render tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Makes a campaign owned by the fixture organization.
	 *
	 * @param string $status Campaign status.
	 * @param string $title  Campaign title.
	 * @return int
	 */
	private function campaign( string $status, string $title = 'Spring launch' ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_title'  => $title,
				'post_author' => $this->advertiser,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $campaign_id;
	}

	/**
	 * Adds one private creative to a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array{creative_id: int, private_path: string, private_token: string}
	 */
	private function creative( int $campaign_id ): array {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
				'post_author' => $this->advertiser,
			)
		);

		$private_path  = 'do-not-leak.png';
		$private_token = '0123456789abcdef0123456789abcdef';

		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $creative_id, Creative_Repository::META_PLACEMENT_ID, $placement_id );
		update_post_meta( $creative_id, Creative_Repository::META_SIZE, '728x90' );
		update_post_meta( $creative_id, Creative_Repository::META_KIND, 'image' );
		update_post_meta( $creative_id, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative_id, Creative_Repository::META_HEIGHT, 90 );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, 'https://example.com/tickets' );
		update_post_meta( $creative_id, Creative_Repository::META_ALT_TEXT, 'Spring exhibition advertisement' );
		update_post_meta( $creative_id, Creative_Repository::META_PRIVATE_PATH, $private_path );
		update_post_meta( $creative_id, Creative_Repository::META_PRIVATE_TOKEN, $private_token );

		return array(
			'creative_id'   => $creative_id,
			'private_path'  => $private_path,
			'private_token' => $private_token,
		);
	}

	/**
	 * The menu and both write handlers are actually attached.
	 *
	 * @return void
	 */
	public function test_review_surface_is_wired(): void {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->screen, 'register_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->screen, 'enqueue' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Review_Screen::TRANSITION_ACTION, array( $this->screen, 'handle_transition' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Review_Screen::NOTES_ACTION, array( $this->screen, 'handle_notes' ) ) );
	}

	/**
	 * Reviewers see submissions across organizations, oldest first.
	 *
	 * @return void
	 */
	public function test_queue_is_cross_organization_and_status_scoped(): void {
		$first  = $this->campaign( Post_Statuses::SUBMITTED, 'First submission' );
		$second = $this->campaign( Post_Statuses::REVIEW, 'Claimed submission' );
		$this->campaign( Post_Statuses::DRAFT, 'Not in this queue' );
		$other_org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Another advertiser',
			)
		);
		$cross_org = $this->campaign( Post_Statuses::SUBMITTED, 'Cross-organization submission' );
		update_post_meta( $cross_org, Campaign_Repository::META_ORG_ID, $other_org );

		wp_set_current_user( $this->reviewer );

		$queue = $this->data->queue( 'pending' );
		$ids   = array_column( $queue['rows'], 'id' );

		$this->assertContains( $first, $ids );
		$this->assertContains( $second, $ids );
		$this->assertContains( $cross_org, $ids );
		$this->assertCount( 3, $ids );
	}

	/**
	 * A pending creative is previewed through authenticated streaming only.
	 *
	 * @return void
	 */
	public function test_campaign_data_uses_an_authenticated_private_preview(): void {
		$campaign = $this->campaign( Post_Statuses::SUBMITTED );
		$creative = $this->creative( $campaign );

		wp_set_current_user( $this->reviewer );

		$data = $this->data->campaign( $campaign );

		$this->assertIsArray( $data );
		$this->assertCount( 1, $data['creatives'] );

		$url = (string) $data['creatives'][0]['preview'];

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $parameters );

		$this->assertSame( '/aggr/v1/creatives/' . $creative['creative_id'] . '/file', $parameters['rest_route'] ?? '' );
		$this->assertNotEmpty( $parameters['_wpnonce'] ?? '' );
		$this->assertStringNotContainsString( $creative['private_path'], $url );
		$this->assertStringNotContainsString( $creative['private_token'], $url );
	}

	/**
	 * The rendered detail retains its controls without exposing storage data.
	 *
	 * @return void
	 */
	public function test_campaign_detail_renders_the_review_surface_without_private_data(): void {
		$campaign = $this->campaign( Post_Statuses::SUBMITTED, 'Rendered campaign' );
		$creative = $this->creative( $campaign );

		wp_set_current_user( $this->reviewer );

		$_GET['campaign'] = (string) $campaign;

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1 class="aggr-title">Rendered campaign</h1>', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'Start review', $html );
		$this->assertStringNotContainsString( $creative['private_path'], $html );
		$this->assertStringNotContainsString( $creative['private_token'], $html );
	}

	/**
	 * Audit history remains behind its independent read capability.
	 *
	 * @return void
	 */
	public function test_review_capability_alone_does_not_expose_the_audit_timeline(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );
		$this->audit->insert( new Audit_Event( event: 'private-event', object_type: 'campaign', object_id: $campaign, org_id: $this->org_id ) );

		$role = get_role( Roles::REVIEWER );
		$this->assertNotNull( $role );
		$role->remove_cap( Capabilities::VIEW_AUDIT_LOG );

		wp_set_current_user( $this->reviewer );

		$data = $this->data->campaign( $campaign );

		$this->assertIsArray( $data );
		$this->assertFalse( $data['can_view_audit'] );
		$this->assertSame( array(), $data['audit'] );
	}

	/**
	 * Object-aware capability checks keep every valid staff action visible.
	 *
	 * @return void
	 */
	public function test_scheduled_campaign_exposes_pause_and_cancel_actions(): void {
		$campaign = $this->campaign( Post_Statuses::SCHEDULED );

		wp_set_current_user( $this->reviewer );

		$targets = array_column( $this->data->actions_for( $campaign, Post_Statuses::SCHEDULED ), 'to' );

		$this->assertContains( Post_Statuses::PAUSED, $targets );
		$this->assertContains( Post_Statuses::CANCELLED, $targets );
	}

	/**
	 * Claiming through the screen still funnels through the state machine.
	 *
	 * @return void
	 */
	public function test_reviewer_can_claim_a_submitted_campaign(): void {
		$campaign = $this->campaign( Post_Statuses::SUBMITTED );

		wp_set_current_user( $this->reviewer );

		$this->assertTrue( $this->screen->process_transition( $campaign, Post_Statuses::REVIEW ) );
		$this->assertSame( Post_Statuses::REVIEW, $this->campaigns->status( $campaign ) );
		$this->assertSame( $this->reviewer, $this->campaigns->reviewed_by( $campaign ) );
	}

	/**
	 * Internal notes are staff-only and every write enters the audit timeline.
	 *
	 * @return void
	 */
	public function test_internal_notes_are_authorized_and_audited(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );

		wp_set_current_user( $this->reviewer );

		$this->assertTrue( $this->screen->process_notes( $campaign, 'Confirm destination with sales.' ) );
		$this->assertSame( 'Confirm destination with sales.', $this->campaigns->internal_notes( $campaign ) );

		$events = $this->audit->for_object( 'campaign', $campaign, $this->org_id );

		$this->assertSame( 'campaign.internal_notes_updated', $events[0]['event'] );

		wp_set_current_user( $this->advertiser );

		$denied = $this->screen->process_notes( $campaign, 'Advertiser overwrite' );

		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'aggr_forbidden', $denied->get_error_code() );
		$this->assertSame( 'Confirm destination with sales.', $this->campaigns->internal_notes( $campaign ) );
	}

	/**
	 * Audit reads are object-scoped in SQL and newest first.
	 *
	 * @return void
	 */
	public function test_audit_timeline_is_scoped_and_ordered(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );
		$other    = $this->campaign( Post_Statuses::REVIEW, 'Another campaign' );

		$this->audit->insert( new Audit_Event( event: 'first', object_type: 'campaign', object_id: $campaign, org_id: $this->org_id, message: 'First' ) );
		$this->audit->insert( new Audit_Event( event: 'other', object_type: 'campaign', object_id: $other, org_id: $this->org_id, message: 'Other' ) );
		$this->audit->insert( new Audit_Event( event: 'second', object_type: 'campaign', object_id: $campaign, org_id: $this->org_id, message: 'Second' ) );
		$this->audit->insert( new Audit_Event( event: 'wrong-org', object_type: 'campaign', object_id: $campaign, org_id: $this->org_id + 1, message: 'Wrong org' ) );

		$events = $this->audit->for_object( 'campaign', $campaign, $this->org_id );

		$this->assertSame( array( 'second', 'first' ), array_column( $events, 'event' ) );
		$this->assertNotContains( 'other', array_column( $events, 'event' ) );
		$this->assertNotContains( 'wrong-org', array_column( $events, 'event' ) );
	}

	/**
	 * **The review screen itself refuses anyone without the capability.**
	 *
	 * This is the screen that renders another organization's unapproved
	 * creative, the staff-only internal notes and the audit timeline. In
	 * production `add_submenu_page()` gates the callback, so the in-method check
	 * is the second lock — and it could be deleted with all 661 tests green,
	 * which means nobody had ever seen it work.
	 *
	 * @return void
	 */
	public function test_render_refuses_a_user_without_the_review_capability(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );

		$this->campaigns->set_internal_notes( $campaign, 'Never shown to an advertiser.' );

		wp_set_current_user( $this->advertiser );

		$query    = array( 'campaign' => (string) $campaign );
		$_GET     = $query;
		$_REQUEST = $query;

		$this->expectException( 'WPDieException' );

		ob_start();

		try {
			$this->screen->render();
		} finally {
			$output = (string) ob_get_clean();

			$this->assertStringNotContainsString( 'Never shown to an advertiser.', $output );
		}
	}

	/**
	 * A logged-out visitor is refused the same way.
	 *
	 * @return void
	 */
	public function test_render_refuses_a_logged_out_visitor(): void {
		wp_set_current_user( 0 );

		$this->expectException( 'WPDieException' );
		$this->screen->render();
	}

	/**
	 * **The review capability alone is not a licence over every organization.**
	 *
	 * `save_internal_notes()` checks the capability *and* `edit_aggr_campaign`
	 * against the specific campaign, and only the first half was tested: the
	 * object check could be deleted with the suite green, because the reviewer
	 * role holds the cross-organization primitive and every existing test uses
	 * that role.
	 *
	 * The configuration that separates them is the one `OwnershipTest` covers
	 * for reads — somebody handed the review capability to an advertiser so they
	 * could help work the queue. That user is staff enough to pass the first
	 * check and holds no `edit_others_aggr_campaigns`, so the object check is
	 * the only thing keeping them out of another tenant's campaign.
	 *
	 * @return void
	 */
	public function test_the_review_capability_alone_cannot_write_notes_on_another_organization(): void {
		$other_org      = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Second tenant',
			)
		);
		$other_campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::REVIEW,
				'post_title'  => 'Another tenant flight',
			)
		);

		update_post_meta( $other_campaign, Campaign_Repository::META_ORG_ID, $other_org );

		$helper = get_user_by( 'id', $this->advertiser );

		$this->assertInstanceOf( \WP_User::class, $helper );

		$helper->add_cap( Capabilities::REVIEW_CAMPAIGNS );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		// The fixture is only real if the first check passes and the second is
		// the one left to deny. Asserting against a user who never became staff
		// would prove nothing about the object check.
		$this->assertTrue( user_can( $this->advertiser, Capabilities::REVIEW_CAMPAIGNS ) );
		$this->assertFalse( user_can( $this->advertiser, 'edit_others_aggr_campaigns' ) );

		wp_set_current_user( $this->advertiser );

		$denied = $this->screen->process_notes( $other_campaign, 'Reaching into another tenant.' );

		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'aggr_forbidden', $denied->get_error_code() );
		$this->assertSame( '', $this->campaigns->internal_notes( $other_campaign ) );
	}

	/**
	 * **A button is only offered to somebody who holds every capability it needs.**
	 *
	 * Approval requires two capabilities and a reviewer may hold only one, so
	 * the filter is per-edge rather than per-screen. Dropping it leaves a screen
	 * offering an action the state machine will refuse — and dropping it left
	 * the suite green.
	 *
	 * @return void
	 */
	public function test_actions_omit_edges_the_user_cannot_complete(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );

		wp_set_current_user( $this->reviewer );

		$targets = array_column( $this->data->actions_for( $campaign, Post_Statuses::REVIEW ), 'to' );

		$this->assertContains( Post_Statuses::APPROVED, $targets, 'A full reviewer should be offered approval.' );

		// Revoked through the capability pipeline rather than remove_cap():
		// PUBLISH_TO_ADSANITY comes from the reviewer role, and remove_cap()
		// only touches user-level grants, so the role would hand it straight
		// back and this test would assert nothing.
		$revoke = static function ( array $allcaps ): array {
			unset( $allcaps[ Capabilities::PUBLISH_TO_ADSANITY ] );

			return $allcaps;
		};

		add_filter( 'user_has_cap', $revoke, 10 );

		try {
			$this->assertFalse( current_user_can( Capabilities::PUBLISH_TO_ADSANITY ) );

			$reduced = array_column( $this->data->actions_for( $campaign, Post_Statuses::REVIEW ), 'to' );

			$this->assertNotContains( Post_Statuses::APPROVED, $reduced );

			// The edges that need only the review capability are untouched, so
			// this is a per-edge filter and not the whole screen going dark.
			$this->assertContains( Post_Statuses::CHANGES, $reduced );
		} finally {
			remove_filter( 'user_has_cap', $revoke, 10 );
		}
	}

	/**
	 * Missing nonces die before a transition is attempted.
	 *
	 * @return void
	 */
	public function test_transition_handler_rejects_a_missing_nonce(): void {
		$campaign = $this->campaign( Post_Statuses::SUBMITTED );

		wp_set_current_user( $this->reviewer );

		$_POST = array(
			'campaign_id' => (string) $campaign,
			'to'          => Post_Statuses::REVIEW,
		);

		$this->expectException( 'WPDieException' );
		$this->screen->handle_transition();
	}

	/**
	 * A nonce from another campaign cannot authorize this one.
	 *
	 * @return void
	 */
	public function test_transition_handler_rejects_a_forged_nonce(): void {
		$campaign = $this->campaign( Post_Statuses::SUBMITTED );

		wp_set_current_user( $this->reviewer );

		$_POST = array(
			'campaign_id' => (string) $campaign,
			'to'          => Post_Statuses::REVIEW,
			'_wpnonce'    => wp_create_nonce( Review_Screen::nonce_action( $campaign + 1 ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->screen->handle_transition();
	}

	/**
	 * **Capability denial happens before a valid nonce can authorize a write.**
	 *
	 * `$_REQUEST` is set deliberately, and it is the whole point of this test.
	 * `check_admin_referer()` reads the nonce from `$_REQUEST`, which PHP does
	 * not populate from `$_POST` under CLI — so a test that sets only `$_POST`
	 * presents *no* nonce however carefully it built one, dies at
	 * `wp_nonce_ays()`, and proves nothing beyond what the missing-nonce test
	 * above already proved. This one was written that way and passed with both
	 * capability gates on the transition path deleted.
	 *
	 * With the nonce genuinely valid, the only thing left to deny an advertiser
	 * is the capability check, which is what this now asserts.
	 *
	 * @return void
	 */
	public function test_transition_handler_rejects_an_advertiser_with_a_valid_nonce(): void {
		$campaign = $this->campaign( Post_Statuses::SUBMITTED );

		wp_set_current_user( $this->advertiser );

		$fields = array(
			'campaign_id' => (string) $campaign,
			'to'          => Post_Statuses::REVIEW,
			'_wpnonce'    => wp_create_nonce( Review_Screen::nonce_action( $campaign ) ),
		);

		$_POST    = $fields;
		$_REQUEST = $fields;

		$this->expectException( 'WPDieException' );
		$this->screen->handle_transition();
	}

	/**
	 * The notes handler dies without a nonce, exactly as the transition one does.
	 *
	 * Two `admin_post` handlers write on this screen. Only one of them had any
	 * CSRF coverage, and `check_admin_referer()` could be deleted from this one
	 * with the whole suite green.
	 *
	 * @return void
	 */
	public function test_notes_handler_rejects_a_missing_nonce(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );

		wp_set_current_user( $this->reviewer );

		$fields   = array(
			'campaign_id'    => (string) $campaign,
			'internal_notes' => 'Written by a forged request.',
		);
		$_POST    = $fields;
		$_REQUEST = $fields;

		try {
			$this->screen->handle_notes();
			$this->fail( 'The notes handler accepted a request with no nonce.' );
		} catch ( \WPDieException ) {
			$this->assertSame( '', $this->campaigns->internal_notes( $campaign ) );
		}
	}

	/**
	 * A nonce minted for another campaign cannot authorize this one.
	 *
	 * @return void
	 */
	public function test_notes_handler_rejects_a_forged_nonce(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );

		wp_set_current_user( $this->reviewer );

		$fields   = array(
			'campaign_id'    => (string) $campaign,
			'internal_notes' => 'Written by a forged request.',
			'_wpnonce'       => wp_create_nonce( Review_Screen::notes_nonce_action( $campaign + 1 ) ),
		);
		$_POST    = $fields;
		$_REQUEST = $fields;

		try {
			$this->screen->handle_notes();
			$this->fail( 'The notes handler accepted a nonce minted for another campaign.' );
		} catch ( \WPDieException ) {
			$this->assertSame( '', $this->campaigns->internal_notes( $campaign ) );
		}
	}

	/**
	 * **An advertiser with a valid nonce still cannot write internal notes.**
	 *
	 * Internal notes are the field staff write candidly in, on the explicit
	 * promise that the advertiser never sees them. A valid nonce is not
	 * authorization, and this is the assertion that says so.
	 *
	 * @return void
	 */
	public function test_notes_handler_rejects_an_advertiser_with_a_valid_nonce(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );

		wp_set_current_user( $this->advertiser );

		$fields   = array(
			'campaign_id'    => (string) $campaign,
			'internal_notes' => 'Advertiser overwrite.',
			'_wpnonce'       => wp_create_nonce( Review_Screen::notes_nonce_action( $campaign ) ),
		);
		$_POST    = $fields;
		$_REQUEST = $fields;

		try {
			$this->screen->handle_notes();
			$this->fail( 'An advertiser wrote internal notes with a valid nonce.' );
		} catch ( \WPDieException ) {
			$this->assertSame( '', $this->campaigns->internal_notes( $campaign ) );
		}
	}
}
