<?php
/**
 * Staff editing a campaign on an advertiser's behalf.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Edit_Window;
use WP_UnitTestCase;

/**
 * Widening the edit window is the kind of change that quietly widens more than
 * intended, so these tests spend most of their effort on what must NOT have
 * changed: an advertiser's window, and organization isolation.
 */
final class StaffEditOnBehalfTest extends WP_UnitTestCase {

	/**
	 * Holds the review capability.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Owns the campaign.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * An advertiser in a different organization.
	 *
	 * @var int
	 */
	private int $outsider;

	/**
	 * The owning organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Subject.
	 *
	 * @var Campaign_Editor
	 */
	private Campaign_Editor $editor;

	/**
	 * Window under test.
	 *
	 * @var Edit_Window
	 */
	private Edit_Window $window;

	/**
	 * Builds two organizations and three users.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->editor = $container->get( Campaign_Editor::class );
		$this->window = $container->get( Edit_Window::class );

		$this->reviewer   = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->outsider   = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_title'  => 'Blue Ridge Coffee',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$other_org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_title'  => 'Somebody else',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other_org, Org_Repository::META_OWNER_USER, $this->outsider );

		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * A campaign owned by the advertiser's organization.
	 *
	 * @param string $status Campaign status.
	 * @return int
	 */
	private function campaign( string $status ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_title'  => 'Spring launch',
				'post_author' => $this->advertiser,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $campaign_id;
	}

	/**
	 * Staff may edit a live campaign; the advertiser may not.
	 *
	 * The two halves are one test because the whole change is the difference
	 * between them. Asserting only the staff half would stay green if the
	 * window had been widened for everybody.
	 *
	 * @return void
	 */
	public function test_staff_may_edit_a_live_campaign_and_the_advertiser_may_not(): void {
		$campaign_id = $this->campaign( Post_Statuses::LIVE );

		wp_set_current_user( $this->advertiser );
		$this->assertFalse(
			$this->window->allows( $campaign_id ),
			'An advertiser must not be able to edit a live campaign.'
		);

		wp_set_current_user( $this->reviewer );
		$this->assertTrue(
			$this->window->allows( $campaign_id ),
			'Staff must be able to edit a live campaign on the client\'s behalf.'
		);
	}

	/**
	 * The advertiser's own window is unchanged.
	 *
	 * @return void
	 */
	public function test_the_advertiser_window_is_still_draft_and_changes(): void {
		wp_set_current_user( $this->advertiser );

		foreach ( Post_Statuses::all() as $status ) {
			$campaign_id = $this->campaign( $status );
			$expected    = in_array( $status, array( Post_Statuses::DRAFT, Post_Statuses::CHANGES ), true );

			$this->assertSame(
				$expected,
				$this->window->allows( $campaign_id ),
				sprintf( 'Advertiser editability changed for %s.', $status )
			);
		}
	}

	/**
	 * A wider window is not a wider reach.
	 *
	 * Staff may edit in any status, but an advertiser from another
	 * organization still may not edit this campaign in any status at all. This
	 * is the assertion that would catch the window being widened by capability
	 * the plugin grants broadly rather than by the review capability.
	 *
	 * @return void
	 */
	public function test_another_organizations_advertiser_is_still_refused(): void {
		wp_set_current_user( $this->outsider );

		foreach ( Post_Statuses::all() as $status ) {
			$campaign_id = $this->campaign( $status );

			$saved = $this->editor->save( $campaign_id, array( 'title' => 'Hijacked' ), 0 );

			$this->assertWPError( $saved, sprintf( 'An outsider edited a %s campaign.', $status ) );
			$this->assertSame( 'aggr_forbidden', $saved->get_error_code() );
		}
	}

	/**
	 * Staff editing a client's campaign is an on-behalf edit.
	 *
	 * @return void
	 */
	public function test_staff_editing_a_client_campaign_is_on_behalf(): void {
		$campaign_id = $this->campaign( Post_Statuses::LIVE );

		wp_set_current_user( $this->reviewer );
		$this->assertTrue( $this->window->is_on_behalf( $campaign_id ) );

		wp_set_current_user( $this->advertiser );
		$this->assertFalse(
			$this->window->is_on_behalf( $campaign_id ),
			'An advertiser editing their own campaign is not acting on anyone\'s behalf.'
		);
	}

	/**
	 * A staff member who genuinely belongs to the organization is not.
	 *
	 * Membership decides, not capability — otherwise the timeline would record
	 * an outsider reaching in every time somebody with review rights edited
	 * their own organization's work.
	 *
	 * @return void
	 */
	public function test_membership_not_capability_decides_on_behalf(): void {
		$member_reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		update_post_meta( $this->org_id, Org_Repository::META_MEMBER_USER, $member_reviewer );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$campaign_id = $this->campaign( Post_Statuses::LIVE );

		wp_set_current_user( $member_reviewer );

		$this->assertTrue( $this->window->allows( $campaign_id ) );
		$this->assertFalse(
			$this->window->is_on_behalf( $campaign_id ),
			'A reviewer inside the organization is editing their own work.'
		);
	}
}
