<?php
/**
 * Changing a running campaign's click tracker through a reviewed proposal.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Change_Summary;
use WP_UnitTestCase;

/**
 * The hop reads the stored tracker. This proves a running campaign's change
 * goes through storage the same way a destination does: staged, not applied
 * until staff approve, and never when the destination switch is off.
 */
final class CampaignChangeTrackerTest extends WP_UnitTestCase {

	use RunningCampaignFixtures;

	private const TRACKER = 'https://track.example.com/c?u={lpurl}';
	private const OTHER   = 'https://other.example.com/c?u={lpurl}';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_running_campaign();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * A running campaign, optionally already carrying a tracker.
	 *
	 * @param string $tracker Stored tracker, or empty.
	 * @return int
	 */
	private function campaign_with_tracker( string $tracker = '' ): int {
		$campaign_id = $this->running_campaign();

		if ( '' !== $tracker ) {
			update_post_meta( $campaign_id, Campaign_Repository::META_CLICK_TRACKER, $tracker );
		}

		return $campaign_id;
	}

	public function test_a_tracker_is_not_written_until_staff_approve(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->campaign_with_tracker();
		$campaigns   = new Campaign_Repository();

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );

		$staged = $this->changes->stage( $campaign_id, array( 'click_tracker' => self::TRACKER ) );

		$this->assertIsArray( $staged );
		$this->assertSame( self::TRACKER, $staged['click_tracker'] ?? null );
		$this->assertSame( '', $campaigns->click_tracker( $campaign_id ), 'The hop would have started using a tracker nobody approved.' );

		$this->assertTrue( $this->changes->submit( $campaign_id ) );
		$this->assertSame( '', $campaigns->click_tracker( $campaign_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertTrue( $this->changes->approve( $campaign_id ) );
		$this->assertSame( self::TRACKER, $campaigns->click_tracker( $campaign_id ) );
	}

	public function test_a_tracker_can_be_removed(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->campaign_with_tracker( self::TRACKER );
		$campaigns   = new Campaign_Repository();

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );
		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'click_tracker' => '' ) ) );
		$this->assertTrue( $this->changes->submit( $campaign_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertTrue( $this->changes->approve( $campaign_id ) );
		$this->assertSame( '', $campaigns->click_tracker( $campaign_id ) );
	}

	public function test_resaving_the_same_tracker_proposes_nothing(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->campaign_with_tracker( self::TRACKER );

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );

		$this->assertSame( array(), $this->changes->stage( $campaign_id, array( 'click_tracker' => ' ' . self::TRACKER . ' ' ) ) );
	}

	public function test_a_tracker_is_off_with_the_destination_switch(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->campaign_with_tracker();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->changes->stage( $campaign_id, array( 'click_tracker' => self::TRACKER ) );

		$this->assertSame( array(), $this->requests->pending_edits( $campaign_id ), 'A switched-off tracker was staged.' );
		$this->assertSame( '', ( new Campaign_Repository() )->click_tracker( $campaign_id ) );
	}

	public function test_an_unusable_tracker_is_refused_and_the_stored_one_stays(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->campaign_with_tracker( self::TRACKER );

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );

		$result = $this->changes->stage( $campaign_id, array( 'click_tracker' => 'https://track.example.com/c?no-landing-page' ) );

		$this->assertWPError( $result );
		$this->assertSame( self::TRACKER, ( new Campaign_Repository() )->click_tracker( $campaign_id ) );
		$this->assertSame( array(), $this->requests->pending_edits( $campaign_id ), 'A refused tracker was staged anyway.' );
	}

	public function test_the_reviewer_is_shown_both_addresses(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->campaign_with_tracker( self::TRACKER );

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );
		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'click_tracker' => self::OTHER ) ) );
		$this->assertTrue( $this->changes->submit( $campaign_id ) );

		$rows = array();

		foreach ( $this->changes->pending_summary( $campaign_id ) as $row ) {
			if ( 'click_tracker' === ( $row['field'] ?? '' ) ) {
				$rows[] = $row;
			}
		}

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Click tracker', $rows[0]['label'] );
		$this->assertSame( self::TRACKER, $rows[0]['from'] );
		$this->assertSame( self::OTHER, $rows[0]['to'] );
		$this->assertSame( 'Click tracker', Campaign_Change_Summary::field_label( 'click_tracker' ) );
	}

	public function test_staging_a_tracker_does_not_move_the_destination(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->campaign_with_tracker();
		$campaigns   = new Campaign_Repository();

		update_post_meta( $campaign_id, Campaign_Repository::META_DEFAULT_CLICK_URL, 'https://example.com/offer' );

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );
		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'click_tracker' => self::TRACKER ) ) );

		$this->assertArrayNotHasKey( 'default_click_url', $this->requests->pending_edits( $campaign_id ) );
		$this->assertSame( 'https://example.com/offer', $campaigns->default_click_url( $campaign_id ) );
	}
}
