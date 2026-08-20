<?php
/**
 * Staff creating a campaign for an advertiser.
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
use Aggressive\Ads\Workflow\Campaign_Copier;
use Aggressive\Ads\Workflow\Campaign_Editor;
use WP_UnitTestCase;

/**
 * Creation is the one place organization identity comes from input rather than
 * from an object that already carries one, so these tests care most about who
 * may name an organization and what happens when the name is wrong.
 */
final class StaffCreateOnBehalfTest extends WP_UnitTestCase {

	/**
	 * Holds the review capability.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Belongs to the client organization.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * The client organization.
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
	 * Copy path, which allocates a draft the same way.
	 *
	 * @var Campaign_Copier
	 */
	private Campaign_Copier $copier;

	/**
	 * Builds the organization and users.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->editor = $container->get( Campaign_Editor::class );
		$this->copier = $container->get( Campaign_Copier::class );

		$this->reviewer   = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_title'  => 'Blue Ridge Coffee',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Staff may create a draft owned by the named organization.
	 *
	 * @return void
	 */
	public function test_staff_may_create_a_campaign_for_an_advertiser(): void {
		wp_set_current_user( $this->reviewer );

		$campaign_id = $this->editor->create_for_org( $this->org_id, 'Autumn roast' );

		$this->assertIsInt( $campaign_id );

		$campaigns = Plugin::instance()->container()->get( Campaign_Repository::class );

		$this->assertSame(
			$this->org_id,
			$campaigns->org_id( $campaign_id ),
			'The campaign must belong to the advertiser, not the staff member.'
		);
		$this->assertSame( Post_Statuses::DRAFT, $campaigns->status( $campaign_id ) );
	}

	/**
	 * An advertiser cannot name an organization at all.
	 *
	 * This is the assertion that matters most. The REST route accepts an
	 * org_id from anyone; only the editor's capability check stands between
	 * that parameter and a campaign filed under somebody else's organization.
	 *
	 * @return void
	 */
	public function test_an_advertiser_may_not_create_for_an_organization(): void {
		wp_set_current_user( $this->advertiser );

		$created = $this->editor->create_for_org( $this->org_id, 'Mine now' );

		$this->assertWPError( $created );
		$this->assertSame( 'aggr_forbidden', $created->get_error_code() );
	}

	/**
	 * An id that names no organization is refused.
	 *
	 * Otherwise the campaign would be owned by nothing: no org-scoped query
	 * would return it and no advertiser could ever reach it.
	 *
	 * @return void
	 */
	public function test_an_unknown_organization_is_refused(): void {
		wp_set_current_user( $this->reviewer );

		$created = $this->editor->create_for_org( 999999, 'Nowhere' );

		$this->assertWPError( $created );
		$this->assertSame( 'aggr_organization_missing', $created->get_error_code() );
	}

	/**
	 * A campaign id is not an organization id.
	 *
	 * Both are post ids, so a transposed parameter is a plausible mistake
	 * rather than an attack, and it would otherwise create a campaign owned by
	 * another campaign.
	 *
	 * @return void
	 */
	public function test_a_post_of_the_wrong_type_is_refused(): void {
		wp_set_current_user( $this->reviewer );

		$not_an_org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
			)
		);

		$created = $this->editor->create_for_org( $not_an_org, 'Wrong type' );

		$this->assertWPError( $created );
		$this->assertSame( 'aggr_organization_missing', $created->get_error_code() );
	}

	/**
	 * A copy belongs to the organization it was copied from.
	 *
	 * Staff can read a client's campaign, so deriving the target from the
	 * caller filed the copy — snapshot and private creative bytes included —
	 * under whichever organization the staff member belonged to.
	 *
	 * @return void
	 */
	public function test_a_staff_copy_stays_with_the_source_organization(): void {
		$source_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::COMPLETE,
				'post_title'  => 'Spring launch',
				'post_author' => $this->advertiser,
			)
		);
		update_post_meta( $source_id, Campaign_Repository::META_ORG_ID, $this->org_id );

		$staff_org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_title'  => 'House',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $staff_org, Org_Repository::META_OWNER_USER, $this->reviewer );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		wp_set_current_user( $this->reviewer );

		$copy_id = $this->copier->copy( $source_id );

		$this->assertIsInt( $copy_id, 'Staff must be able to copy a client campaign.' );

		$campaigns = Plugin::instance()->container()->get( Campaign_Repository::class );

		$this->assertSame(
			$this->org_id,
			$campaigns->org_id( $copy_id ),
			'The copy was filed under the staff member\'s own organization.'
		);
		$this->assertNotSame( $staff_org, $campaigns->org_id( $copy_id ) );
	}
}
