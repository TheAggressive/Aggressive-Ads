<?php
/**
 * Placements on a running campaign: the package's, and changed only with it.
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
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Live_Package_Change;
use WP_UnitTestCase;

/**
 * The package priced the campaign and decides its placements, as creation
 * does. An advertiser cannot change them on their own — there is no switch
 * for it — and a proposal staged before that switch was retired is still
 * held to the package when it is approved.
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
	 * Even with every switch on, a hand-built placement change is not staged:
	 * placements come with a package, never on their own.
	 *
	 * @return void
	 */
	public function test_placements_cannot_be_changed_on_their_own(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$sidebar     = $this->placement( 'Article sidebar', true );

		$this->allow( Settings_Schema::edit_keys() );

		$this->assertSame( array(), $this->changes->stage( $campaign_id, array( 'placement_ids' => array( $sidebar ) ) ) );
		$this->assertSame( array(), $this->requests->pending_edits( $campaign_id ), 'A placement change was staged without a package change.' );

		// A site that had the old switch on keeps nothing of it.
		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();

		$document['live_edits']['placements'] = true;
		$settings->save( $document );

		$this->assertNotContains( 'placement_ids', $this->changes->allowed_fields() );
	}

	/**
	 * A placement change staged before the switch was retired is still held
	 * to the package at approval, not applied because it was already stored.
	 *
	 * @return void
	 */
	public function test_a_placement_change_already_staged_is_still_held_to_the_package(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$unsold      = $this->placement( 'Premium takeover', false );

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->requests->set_pending_edits( $campaign_id, array( 'placement_ids' => array( $this->placement_id, $unsold ) ), $this->advertiser, true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$refused = $this->changes->approve( $campaign_id );

		$this->assertWPError( $refused );
		$this->assertContains(
			Live_Edit_Rules::ERROR_PLACEMENT_NOT_OFFERED,
			array_column( (array) ( $refused->get_error_data()['problems'] ?? array() ), 'code' ),
			'An unsold placement was refused for some other reason, so this proves nothing.'
		);
		$this->assertSame( array( $this->placement_id ), ( new Campaign_Repository() )->placement_ids( $campaign_id ) );
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

		$this->assertContains( $this->placement_id, $this->package_choices( $campaign_id ) );
		$this->assertContains( $replacement, $this->package_choices( $campaign_id ) );
	}

	/**
	 * What a change may choose from, as the rules are given it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int>
	 */
	private function package_choices( int $campaign_id ): array {
		return Plugin::instance()->container()->get( Live_Package_Change::class )->placement_choices( $campaign_id );
	}
}
