<?php
/**
 * What a third-party click tracker may look like.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Click_Macros;
use Aggressive\Ads\Domain\Click_Tracker_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tracker is a third party readers are sent through, so the refusals matter
 * more than the acceptances: every way it could lose, double or leak the
 * landing page is a case here.
 */
final class ClickTrackerRulesTest extends TestCase {

	/**
	 * Trackers that must be accepted.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function accepted(): array {
		return array(
			'nothing is no tracker'  => array( '' ),
			'blank is no tracker'    => array( "  \n" ),
			'cm360 style'            => array( 'https://ad.doubleclick.net/ddm/trackclk/N1.2/B3;dc_dest_url={lpurl}' ),
			'query parameter'        => array( 'https://track.example.com/c?cid={campaign_id}&u={lpurl}' ),
			'upper case macro'       => array( 'https://track.example.com/c?u={LPURL}' ),
			'ip address and port'    => array( 'http://192.0.2.10:8080/c?u={lpurl}' ),
			'punycode host'          => array( 'https://xn--bcher-kva.example/c?u={lpurl}' ),
			'other macros alongside' => array( 'https://t.example.com/c?cb={cachebuster}&slot={placement_id}&u={lpurl}' ),
		);
	}

	/**
	 * Trackers that must be refused, and why.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refused(): array {
		return array(
			'no landing page'        => array( 'https://track.example.com/c?cid=1', Click_Tracker_Rules::ERROR_NO_LANDING_MACRO ),
			'only other macros'      => array( 'https://track.example.com/c?cb={cachebuster}', Click_Tracker_Rules::ERROR_NO_LANDING_MACRO ),
			'landing page twice'     => array( 'https://track.example.com/c?a={lpurl}&b={lpurl}', Click_Tracker_Rules::ERROR_REPEATED_LANDING_MACRO ),
			'twice in another case'  => array( 'https://track.example.com/c?a={lpurl}&b={LpUrl}', Click_Tracker_Rules::ERROR_REPEATED_LANDING_MACRO ),
			'unencoded variant'      => array( 'https://track.example.com/c?u={lpurl_unenc}', Click_Tracker_Rules::ERROR_UNENCODED_LANDING ),
			'unescaped variant'      => array( 'https://track.example.com/c?u={unescapedlpurl}', Click_Tracker_Rules::ERROR_UNENCODED_LANDING ),
			'unenc beside the real'  => array( 'https://track.example.com/c?u={lpurl}&raw={unenc_url}', Click_Tracker_Rules::ERROR_UNENCODED_LANDING ),
			'destination by name'    => array( 'https://track.example.com/c?u={dest_url}', Click_Tracker_Rules::ERROR_UNENCODED_LANDING ),
			'not a link'             => array( 'track.example.com/c?u={lpurl}', Click_Tracker_Rules::ERROR_INVALID ),
			'a script scheme'        => array( 'javascript:alert({lpurl})', Click_Tracker_Rules::ERROR_INVALID ),
			'credentials in the url' => array( 'https://user:pass@track.example.com/c?u={lpurl}', Click_Tracker_Rules::ERROR_INVALID ),
			'a data scheme'          => array( 'data:text/html,{lpurl}', Click_Tracker_Rules::ERROR_INVALID ),
			'spaces in the host'     => array( 'https://not a link at all/c?u={lpurl}', Click_Tracker_Rules::ERROR_INVALID ),
			'the macro as the host'  => array( 'https://{lpurl}/', Click_Tracker_Rules::ERROR_INVALID ),
			'a macro in the host'    => array( 'https://{campaign_id}.track.example.com/c?u={lpurl}', Click_Tracker_Rules::ERROR_INVALID ),
		);
	}

	/**
	 * Every acceptable tracker is accepted.
	 *
	 * @param string $tracker Candidate.
	 */
	#[DataProvider( 'accepted' )]
	public function test_a_usable_tracker_is_accepted( string $tracker ): void {
		$this->assertNull( Click_Tracker_Rules::error_for( $tracker ) );
	}

	/**
	 * Every unacceptable tracker is refused, with its own reason.
	 *
	 * @param string $tracker  Candidate.
	 * @param string $expected The reason.
	 */
	#[DataProvider( 'refused' )]
	public function test_an_unusable_tracker_is_refused_for_the_right_reason( string $tracker, string $expected ): void {
		$this->assertSame( $expected, Click_Tracker_Rules::error_for( $tracker ) );
	}

	public function test_the_cases_are_all_there(): void {
		// A provider that silently shrinks tests nothing.
		$this->assertCount( 8, self::accepted() );
		$this->assertCount( 15, self::refused() );
	}

	public function test_a_tracker_over_the_limit_is_refused_however_valid_it_is(): void {
		$long = 'https://track.example.com/c?u={lpurl}&p=' . str_repeat( 'a', Click_Tracker_Rules::MAX_LENGTH );

		$this->assertSame( Click_Tracker_Rules::ERROR_TOO_LONG, Click_Tracker_Rules::error_for( $long ) );
	}

	public function test_a_tracker_exactly_at_the_limit_is_accepted(): void {
		$base = 'https://track.example.com/c?u={lpurl}&p=';
		$edge = $base . str_repeat( 'a', Click_Tracker_Rules::MAX_LENGTH - strlen( $base ) );

		$this->assertSame( Click_Tracker_Rules::MAX_LENGTH, strlen( $edge ) );
		$this->assertNull( Click_Tracker_Rules::error_for( $edge ) );
	}

	public function test_the_landing_macro_is_not_offered_to_a_destination(): void {
		// Offering `{lpurl}` on the destination field would invite it into a
		// link where it can only ever expand to nothing.
		$this->assertNotContains( Click_Macros::LANDING_PAGE, Click_Macros::names() );
	}
}
