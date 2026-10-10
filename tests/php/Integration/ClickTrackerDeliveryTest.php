<?php
/**
 * Where a click goes when the campaign has a third-party tracker.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Workflow\Click_Hop;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * The hop's composition and its reading of storage, through the real service.
 *
 * `ClickTrackerRulesTest` proves what is accepted. This proves the click path
 * *uses* what was stored: a tracker validated and saved by the editor that the
 * hop never read would pass every other test and be applied to nobody.
 */
final class ClickTrackerDeliveryTest extends WP_UnitTestCase {

	private const TRACKER = 'https://track.example.com/c?cid={campaign_id}&u={lpurl}';
	private const LANDING = 'https://example.com/offer?a=1&b=2';

	/**
	 * A campaign with a tracker in storage.
	 *
	 * @param string $tracker Raw stored value, as if imported.
	 * @return int Campaign ID.
	 */
	private function campaign_with_tracker( string $tracker ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
			)
		);

		update_post_meta( $id, Campaign_Repository::META_CLICK_TRACKER, $tracker );

		return $id;
	}

	/**
	 * A parsed token for a campaign.
	 *
	 * @param int $campaign_id Campaign, or 0 for a house creative.
	 * @return array{placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string}
	 */
	private function parsed( int $campaign_id ): array {
		return array(
			'placement_id' => 3,
			'campaign_id'  => $campaign_id,
			'creative_id'  => 12,
			'exp'          => time() + 3600,
			'nonce'        => 'nonce',
		);
	}

	/**
	 * What the hop would use for this click, through the real service.
	 *
	 * @param array{placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string} $parsed Token.
	 * @return string
	 */
	private function tracker_the_hop_reads( array $parsed ): string {
		$hop    = Plugin::instance()->container()->get( Click_Hop::class );
		$method = new ReflectionMethod( $hop, 'tracker_for' );

		return (string) $method->invoke( $hop, $parsed );
	}

	/**
	 * The query parameters of a URL.
	 *
	 * @param string $url A URL.
	 * @return array<string, string>
	 */
	private function query( string $url ): array {
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
	}

	public function test_with_no_tracker_the_visitor_goes_straight_to_the_landing_page(): void {
		$parsed = $this->parsed( 7 );

		$this->assertSame(
			Click_Hop::landing_url( self::LANDING, $parsed, 'click-id', 'token-value' ),
			Click_Hop::target_url( self::LANDING, '', $parsed, 'click-id', 'token-value' )
		);
	}

	public function test_with_a_tracker_the_visitor_goes_to_the_tracker_first(): void {
		$target = Click_Hop::target_url( self::LANDING, self::TRACKER, $this->parsed( 7 ), 'click-id', 'token-value' );

		$this->assertStringStartsWith( 'https://track.example.com/c?', $target );
		$this->assertSame( '7', $this->query( $target )['cid'] );
	}

	public function test_the_whole_landing_page_survives_inside_the_tracker(): void {
		$parsed = $this->parsed( 7 );
		$query  = $this->query( Click_Hop::target_url( self::LANDING, self::TRACKER, $parsed, 'click-id', 'token-value' ) );

		// Decoded once, it is the landing page — its `&b=2` neither ended the
		// tracker's parameter nor became one of the tracker's own.
		$this->assertSame(
			Click_Hop::landing_url( self::LANDING, $parsed, 'click-id', 'token-value' ),
			$query['u']
		);
		$this->assertArrayNotHasKey( 'b', $query, 'The landing page leaked a parameter into the tracker.' );
		$this->assertArrayNotHasKey( 'a', $query );
	}

	public function test_the_landing_page_inside_the_tracker_still_carries_this_clicks_token(): void {
		$parsed = $this->parsed( 7 );
		$query  = $this->query( Click_Hop::target_url( self::LANDING, self::TRACKER, $parsed, 'click-id', 'token-value' ) );

		$this->assertSame( 'token-value', $this->query( $query['u'] )[ Click_Hop::TOKEN_PARAM ] );
	}

	public function test_a_tracker_cannot_hand_on_a_token_that_is_not_this_clicks(): void {
		$target = Click_Hop::target_url(
			self::LANDING,
			'https://track.example.com/c?aggr_ct=forged&u={lpurl}',
			$this->parsed( 7 ),
			'click-id',
			'token-value'
		);

		$this->assertSame( 'token-value', $this->query( $target )[ Click_Hop::TOKEN_PARAM ] );
		$this->assertSame( 1, substr_count( $target, Click_Hop::TOKEN_PARAM . '=' ), 'Exactly one token, and it is ours.' );
		$this->assertStringNotContainsString( 'forged', $target );
	}

	public function test_a_landing_page_cannot_close_the_tracker_and_add_a_host(): void {
		// An advertiser who controls the landing text controls the macro's input.
		$target = Click_Hop::target_url(
			'https://example.com/?x=1&redirect=https://evil.test/',
			self::TRACKER,
			$this->parsed( 7 ),
			'click-id',
			'token-value'
		);

		$this->assertSame( 'track.example.com', wp_parse_url( $target, PHP_URL_HOST ) );
		$this->assertArrayNotHasKey( 'redirect', $this->query( $target ) );
		$this->assertStringNotContainsString( '=https://evil.test', $target );
	}

	public function test_the_hop_reads_the_tracker_the_editor_stored(): void {
		$id = $this->campaign_with_tracker( self::TRACKER );

		$this->assertSame( self::TRACKER, $this->tracker_the_hop_reads( $this->parsed( $id ) ) );
	}

	public function test_a_stored_tracker_that_no_longer_passes_is_not_used(): void {
		// Meta can be imported or edited by hand; the hop trusts the rules, not
		// the fact that something is stored.
		$bad = array(
			'no landing page'  => 'https://track.example.com/c?cid=1',
			'repeated landing' => 'https://track.example.com/c?a={lpurl}&b={lpurl}',
			'unencoded'        => 'https://track.example.com/c?u={lpurl_unenc}',
			'not http'         => 'javascript:alert({lpurl})',
			'credentials'      => 'https://user:pass@track.example.com/c?u={lpurl}',
		);

		foreach ( $bad as $label => $tracker ) {
			$id = $this->campaign_with_tracker( $tracker );

			$this->assertSame( '', $this->tracker_the_hop_reads( $this->parsed( $id ) ), $label );
		}

		$this->assertCount( 5, $bad );
	}

	public function test_a_house_creative_has_no_tracker(): void {
		// Another campaign's tracker must never apply to a click with no campaign.
		$this->campaign_with_tracker( self::TRACKER );

		$this->assertSame( '', $this->tracker_the_hop_reads( $this->parsed( 0 ) ) );
	}

	public function test_a_campaign_with_no_tracker_has_none(): void {
		$id = $this->campaign_with_tracker( '' );

		$this->assertSame( '', $this->tracker_the_hop_reads( $this->parsed( $id ) ) );
	}
}
