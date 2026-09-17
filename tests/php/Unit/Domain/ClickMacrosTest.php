<?php
/**
 * Filling a destination link in at click time.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Click_Macros;
use PHPUnit\Framework\TestCase;

/**
 * What a macro may and may not do to a link.
 *
 * The values come from this plugin, but the text around them comes from an
 * advertiser, so the negatives matter: no macro may add a host, a scheme or a
 * second query string, and nothing unrecognised may reach the landing page.
 */
final class ClickMacrosTest extends TestCase {

	/**
	 * A click on creative 12, placement 3, campaign 7.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return array(
			'campaign_id'  => 7,
			'creative_id'  => 12,
			'placement_id' => 3,
			'click_id'     => 'abcdef0123456789',
			'now'          => 1_800_000_000,
		);
	}

	public function test_it_fills_in_what_the_click_knows(): void {
		$url = 'https://example.com/offer?utm_content={creative_id}&slot={placement_id}&c={campaign_id}';

		$this->assertSame(
			'https://example.com/offer?utm_content=12&slot=3&c=7',
			Click_Macros::expand( $url, $this->context() )
		);
	}

	public function test_a_cachebuster_is_different_on_every_click(): void {
		$url   = 'https://example.com/?cb={cachebuster}';
		$first = Click_Macros::expand( $url, $this->context() );
		$later = Click_Macros::expand(
			$url,
			array( 'click_id' => 'ffffffffffffffff' ) + $this->context()
		);

		$this->assertNotSame( $first, $later );
		$this->assertStringContainsString( '1800000000', $first );
	}

	public function test_the_same_macro_can_be_used_more_than_once(): void {
		$this->assertSame(
			'https://example.com/12/?id=12',
			Click_Macros::expand( 'https://example.com/{creative_id}/?id={creative_id}', $this->context() )
		);
	}

	public function test_case_does_not_matter(): void {
		$this->assertSame(
			'https://example.com/?c=7',
			Click_Macros::expand( 'https://example.com/?c={CAMPAIGN_ID}', $this->context() )
		);
	}

	public function test_an_unknown_macro_is_removed_rather_than_passed_on(): void {
		$this->assertSame(
			'https://example.com/?a=&b=12',
			Click_Macros::expand( 'https://example.com/?a={mistake}&b={creative_id}', $this->context() )
		);
	}

	public function test_a_link_without_macros_is_returned_untouched(): void {
		$url = 'https://example.com/offer?utm_source=laao#top';

		$this->assertSame( $url, Click_Macros::expand( $url, $this->context() ) );
		$this->assertFalse( Click_Macros::has_macros( $url ) );
		$this->assertTrue( Click_Macros::has_macros( 'https://example.com/?c={campaign_id}' ) );
	}

	public function test_a_missing_context_fills_in_zero_not_a_stray_brace(): void {
		$this->assertSame(
			'https://example.com/?c=0&cr=0&p=0',
			Click_Macros::expand( 'https://example.com/?c={campaign_id}&cr={creative_id}&p={placement_id}', array() )
		);
	}

	public function test_a_value_cannot_escape_the_query_it_sits_in(): void {
		// The click id is this plugin's, but encoding is what makes that irrelevant.
		$expanded = Click_Macros::expand(
			'https://example.com/?id={click_id}',
			array( 'click_id' => 'a&redirect=https://evil.test/?x=#frag /slash' )
		);

		$this->assertSame(
			'https://example.com/?id=a%26redirect%3Dhttps%3A%2F%2Fevil.test%2F%3Fx%3D%23frag%20%2Fslash',
			$expanded
		);
		$this->assertStringNotContainsString( 'evil.test/', $expanded );
	}
}
