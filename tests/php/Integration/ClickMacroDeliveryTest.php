<?php
/**
 * What a click actually lands on.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Workflow\Click_Hop;
use WP_UnitTestCase;

/**
 * The hop's own composition, not the macro rules underneath it.
 *
 * `ClickMacrosTest` proves the substitution. This proves the click path uses
 * it: a destination stored with macros was filled in correctly by a Domain
 * class that nothing on the delivery path called, which is exactly the shape
 * of defect `docs/CLAUDE.md` warns about — a read half and a write half that
 * never meet in a test.
 */
final class ClickMacroDeliveryTest extends WP_UnitTestCase {

	/**
	 * A parsed click token for creative 12, placement 3, campaign 7.
	 *
	 * @return array{placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string}
	 */
	private function parsed(): array {
		return array(
			'placement_id' => 3,
			'campaign_id'  => 7,
			'creative_id'  => 12,
			'exp'          => time() + 3600,
			'nonce'        => 'nonce',
		);
	}

	public function test_a_stored_macro_reaches_the_advertiser_filled_in(): void {
		$landing = Click_Hop::landing_url(
			'https://example.com/offer?utm_source=laao&utm_content={creative_id}&slot={placement_id}',
			$this->parsed(),
			'0123456789abcdef',
			'token-value'
		);

		$this->assertStringContainsString( 'utm_content=12', $landing );
		$this->assertStringContainsString( 'slot=3', $landing );
		$this->assertStringNotContainsString( '{', $landing, 'A brace reached the advertiser.' );
	}

	public function test_the_click_token_survives_the_macros(): void {
		$landing = Click_Hop::landing_url(
			'https://example.com/?c={campaign_id}',
			$this->parsed(),
			'0123456789abcdef',
			'token-value'
		);

		$this->assertSame( 1, substr_count( $landing, Click_Hop::TOKEN_PARAM . '=' ), 'Attribution needs exactly one token.' );
		$this->assertStringContainsString( 'c=7', $landing );
	}

	public function test_a_macro_cannot_send_the_visitor_somewhere_else(): void {
		// The value is this plugin's, so the encoding is what is under test.
		$landing = Click_Hop::landing_url(
			'https://example.com/?id={click_id}',
			$this->parsed(),
			'evil.test/?x=&redirect=https://evil.test/',
			'token-value'
		);

		$this->assertStringStartsWith( 'https://example.com/?', $landing );
		$this->assertStringNotContainsString( '=https://evil.test', $landing );
	}

	public function test_an_ordinary_link_is_left_alone_apart_from_the_token(): void {
		$landing = Click_Hop::landing_url(
			'https://example.com/offer?ref=partner#top',
			$this->parsed(),
			'0123456789abcdef',
			'token-value'
		);

		$this->assertStringContainsString( 'ref=partner', $landing );
		$this->assertStringContainsString( '#top', $landing, 'The fragment has to stay at the end.' );
		$this->assertStringContainsString( Click_Hop::TOKEN_PARAM . '=token-value', $landing );
	}
}
