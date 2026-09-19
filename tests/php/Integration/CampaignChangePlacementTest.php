<?php
/**
 * Which placements a running campaign may move to.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Live_Edit_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use WP_UnitTestCase;

/**
 * The package priced the campaign, so a live edit may not buy a placement it
 * never sold. Its own file because CampaignChangeTest reached the length gate.
 */
final class CampaignChangePlacementTest extends WP_UnitTestCase {

	use RunningCampaignFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_running_campaign();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );

		parent::tear_down();
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

		$this->allow( array( Settings_Schema::EDIT_PLACEMENTS ) );

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
