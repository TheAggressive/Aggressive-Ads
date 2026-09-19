<?php
/**
 * Changing the link every ad of a running campaign goes to.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Link_Checker;
use WP_UnitTestCase;

/**
 * The edit flow draws creation's Destination card, so changing it has to mean
 * what the card says: every ad that uses the shared link moves with it, and an
 * ad with a link of its own keeps it. Asserted through the proposal a reviewer
 * approves, and what approval writes — including what it must not touch.
 */
final class CampaignChangeLinkTest extends WP_UnitTestCase {

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
	 * A running campaign with two ads: one on the shared link, one on its own.
	 *
	 * @return array{0: int, 1: int, 2: int} Campaign, the following ad, the ad with its own link.
	 */
	private function campaign_with_two_links(): array {
		$campaign_id = $this->running_campaign();
		$creatives   = Plugin::instance()->container()->get( Creative_Repository::class );
		$following   = (int) $creatives->for_campaign( $campaign_id )[0]['id'];

		update_post_meta( $campaign_id, Campaign_Repository::META_DEFAULT_CLICK_URL, 'https://example.com/exhibition' );

		$own = $creatives->create(
			$campaign_id,
			$this->org_id,
			$this->placement_id,
			array(
				'kind'      => 'image',
				'click_url' => 'https://example.com/tickets',
				'alt_text'  => 'Tickets',
				'size'      => '728x90',
			)
		);

		return array( $campaign_id, $following, $own );
	}

	public function test_the_shared_link_moves_the_ads_that_use_it_and_no_others(): void {
		wp_set_current_user( $this->advertiser );

		list( $campaign_id, $following, $own ) = $this->campaign_with_two_links();

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );

		$staged = $this->changes->stage( $campaign_id, array( 'default_click_url' => 'https://example.com/autumn' ) );

		$this->assertIsArray( $staged );
		$this->assertSame( 'https://example.com/autumn', $staged['default_click_url'] ?? null );
		$this->assertSame( array( $following => 'https://example.com/autumn' ), $staged['click_urls'] ?? null, 'The proposal moved the wrong ads.' );

		$this->assertTrue( $this->changes->submit( $campaign_id ) );

		$creatives = Plugin::instance()->container()->get( Creative_Repository::class );

		// Nothing moves while it waits.
		$this->assertSame( 'https://example.com/exhibition', $creatives->details( $following )['click_url'] ?? null );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertTrue( $this->changes->approve( $campaign_id ) );

		$this->assertSame( 'https://example.com/autumn', $creatives->details( $following )['click_url'] ?? null );
		$this->assertSame( 'https://example.com/tickets', $creatives->details( $own )['click_url'] ?? null, 'An ad with its own link was moved.' );
		$this->assertSame( 'https://example.com/autumn', ( new Campaign_Repository() )->default_click_url( $campaign_id ) );
	}

	public function test_resaving_the_card_unchanged_proposes_nothing(): void {
		wp_set_current_user( $this->advertiser );

		list( $campaign_id ) = $this->campaign_with_two_links();

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );

		$this->assertSame( array(), $this->changes->stage( $campaign_id, array( 'default_click_url' => 'https://example.com/exhibition ' ) ) );
	}

	public function test_the_shared_link_is_off_with_its_switch(): void {
		wp_set_current_user( $this->advertiser );

		list( $campaign_id, $following ) = $this->campaign_with_two_links();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );

		$this->changes->stage( $campaign_id, array( 'default_click_url' => 'https://example.com/autumn' ) );

		$this->assertSame( array(), $this->requests->pending_edits( $campaign_id ), 'A switched-off link was staged.' );
		$this->assertSame( 'https://example.com/exhibition', Plugin::instance()->container()->get( Creative_Repository::class )->details( $following )['click_url'] ?? null );
	}

	/**
	 * The chip on the edit screen is about the staged link, and the campaign
	 * page's is about the serving one; one stored result cannot answer for
	 * both.
	 *
	 * @return void
	 */
	public function test_a_check_result_belongs_to_the_link_it_checked(): void {
		wp_set_current_user( $this->advertiser );

		list( $campaign_id ) = $this->campaign_with_two_links();

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );
		$this->changes->stage( $campaign_id, array( 'default_click_url' => 'https://example.com/autumn' ) );

		$serving = array(
			'url'        => 'https://example.com/exhibition',
			'status'     => 200,
			'outcome'    => 'works',
			'checked_at' => time(),
		);

		( new Campaign_Repository() )->set_link_check( $campaign_id, $serving );
		$this->requests->set_proposed_link_check(
			$campaign_id,
			array(
				'url'        => 'https://example.com/autumn',
				'status'     => 404,
				'outcome'    => 'missing',
				'checked_at' => time(),
			)
		);

		$checker = Plugin::instance()->container()->get( Link_Checker::class );

		$this->assertSame( 'missing', $checker->last( $campaign_id, true )['outcome'] ?? null );

		// The serving link keeps its own result: checking a proposal used to erase it.
		$this->assertSame( 'works', $checker->last( $campaign_id )['outcome'] ?? null, 'A check of the proposed link replaced the serving link\'s.' );

		// The proposal's result goes with the proposal.
		$this->requests->clear_pending_edits( $campaign_id );
		$this->assertNull( $this->requests->proposed_link_check( $campaign_id ) );
		$this->assertSame( 'works', $checker->last( $campaign_id )['outcome'] ?? null );
	}
}
