<?php
/**
 * Which placements a running campaign may move to.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Live_Edit_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Campaign_Request_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Campaign_Editor;
use WP_UnitTestCase;

/**
 * The package priced the campaign, so a live edit may not buy a placement it
 * never sold. Its own file because CampaignChangeTest reached the length gate.
 */
final class CampaignChangePlacementTest extends WP_UnitTestCase {

	use CampaignEditorFixtures;

	private const DAY = 86400;

	/**
	 * The advertiser.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Their organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * The placement the fixture package sells.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * The fixture package.
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * Draft creation.
	 *
	 * @var Campaign_Editor
	 */
	private Campaign_Editor $editor;

	/**
	 * The portal's form handlers.
	 *
	 * @var Campaign_Actions
	 */
	private Campaign_Actions $actions;

	/**
	 * The service under test.
	 *
	 * @var Campaign_Change_Manager
	 */
	private Campaign_Change_Manager $changes;

	/**
	 * Staged edits.
	 *
	 * @var Campaign_Request_Repository
	 */
	private Campaign_Request_Repository $requests;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id     = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Launch package',
			)
		);

		add_post_meta( $this->package_id, Package_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $this->package_id, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $this->package_id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $this->package_id, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $this->package_id, Package_Repository::META_IS_ACTIVE, 1 );

		$container      = Plugin::instance()->container();
		$this->editor   = $container->get( Campaign_Editor::class );
		$this->actions  = $container->get( Campaign_Actions::class );
		$this->changes  = $container->get( Campaign_Change_Manager::class );
		$this->requests = $container->get( Campaign_Request_Repository::class );

		$container->get( Org_Repository::class )->flush_cache();
		$container->get( Ownership::class )->flush_cache();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * Enables placement edits and nothing else.
	 *
	 * @return void
	 */
	private function allow_placements(): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();

		foreach ( Settings_Schema::edit_keys() as $key ) {
			$document['live_edits'][ $key ] = Settings_Schema::EDIT_PLACEMENTS === $key;
		}

		$this->assertTrue( $settings->save( $document ) );
	}

	/**
	 * A campaign already running on the fixture package.
	 *
	 * @return int
	 */
	private function running_campaign(): int {
		$campaign_id = $this->complete_campaign( 'Running flight' );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, time() - self::DAY );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, time() + self::DAY );

		return $campaign_id;
	}

	/**
	 * An active placement, optionally sold by the fixture package.
	 *
	 * @param string $title      Placement name.
	 * @param bool   $in_package Whether the package sells it.
	 * @return int
	 */
	private function placement( string $title, bool $in_package ): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '300x250' );

		if ( $in_package ) {
			add_post_meta( $this->package_id, Package_Repository::META_PLACEMENT_ID, $placement_id );
		}

		return $placement_id;
	}

	/**
	 * A running campaign may move between the placements its package sells,
	 * and nowhere else — refused at the workflow, where a hand-built post
	 * arrives, not only by what the edit screen offers.
	 *
	 * @return void
	 */
	public function test_a_placement_change_is_held_to_the_campaigns_package(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$sidebar     = $this->placement( 'Article sidebar', true );
		$unsold      = $this->placement( 'Premium takeover', false );

		$this->allow_placements();

		$choices = $this->changes->placement_choices( $campaign_id );

		sort( $choices );
		$expected = array( $this->placement_id, $sidebar );
		sort( $expected );

		$this->assertSame( $expected, $choices, 'The choices are not the package\'s placements.' );
		$this->assertNotContains( $unsold, $choices );

		$refused = $this->changes->stage( $campaign_id, array( 'placement_ids' => array( $this->placement_id, $unsold ) ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'aggr_live_edit_invalid', $refused->get_error_code() );
		$this->assertContains(
			Live_Edit_Rules::ERROR_PLACEMENT_NOT_OFFERED,
			array_column( (array) ( $refused->get_error_data()['problems'] ?? array() ), 'code' ),
			'An unsold placement was refused for some other reason, so this proves nothing.'
		);
		$this->assertSame( array(), $this->requests->pending_edits( $campaign_id ), 'A refused change was staged anyway.' );

		// Swapping to a placement the package does sell is an ordinary edit.
		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'placement_ids' => array( $sidebar ) ) ) );
	}

	/**
	 * Nothing already running is taken away by the package changing later.
	 *
	 * @return void
	 */
	public function test_a_placement_the_campaign_runs_on_stays_a_choice(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		// The package stops selling the placement after this campaign bought it.
		delete_post_meta( $this->package_id, Package_Repository::META_PLACEMENT_ID, $this->placement_id );
		$replacement = $this->placement( 'Article sidebar', true );

		$this->assertContains( $this->placement_id, $this->changes->placement_choices( $campaign_id ) );
		$this->assertContains( $replacement, $this->changes->placement_choices( $campaign_id ) );
	}
}
