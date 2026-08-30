<?php
/**
 * The pure campaign rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Campaign_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Value-level rules, with `now` passed in so nothing here behaves differently
 * depending on when the suite runs.
 */
final class CampaignRulesTest extends TestCase {

	/**
	 * Ordinary destination URLs are accepted.
	 *
	 * @param string $url A URL an advertiser would legitimately enter.
	 * @return void
	 */
	#[DataProvider( 'data_valid_urls' )]
	public function test_valid_click_urls_are_accepted( string $url ): void {
		$this->assertTrue( Campaign_Rules::is_valid_click_url( $url ), "Rejected a legitimate URL: {$url}" );
	}

	/**
	 * URLs an advertiser would legitimately enter.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_valid_urls(): array {
		return array(
			'https'            => array( 'https://example.com' ),
			'http'             => array( 'http://example.com' ),
			'path'             => array( 'https://example.com/shows/2026' ),
			'query'            => array( 'https://example.com/?utm_source=laao&utm_medium=banner' ),
			'fragment'         => array( 'https://example.com/page#tickets' ),
			'port'             => array( 'https://example.com:8443/tickets' ),
			'subdomain'        => array( 'https://tickets.example.co.uk/' ),
			'uppercase scheme' => array( 'HTTPS://example.com' ),
			'trailing space'   => array( '  https://example.com  ' ),
		);
	}

	/**
	 * Anything that is not a plain http(s) address is refused.
	 *
	 * The scheme allowlist is what blocks javascript: and data:, which is the
	 * difference between a destination URL and stored XSS on a public page.
	 *
	 * @param string $url A URL that must not be accepted.
	 * @return void
	 */
	#[DataProvider( 'data_invalid_urls' )]
	public function test_invalid_click_urls_are_refused( string $url ): void {
		$this->assertFalse( Campaign_Rules::is_valid_click_url( $url ), "Accepted a URL it should not: {$url}" );
	}

	/**
	 * URLs that must never be accepted.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_invalid_urls(): array {
		return array(
			'empty'                 => array( '' ),
			'whitespace'            => array( '   ' ),
			'javascript'            => array( 'javascript:alert(1)' ),
			'javascript mixed case' => array( 'JaVaScRiPt:alert(1)' ),
			'data'                  => array( 'data:text/html;base64,PHNjcmlwdD4=' ),
			'file'                  => array( 'file:///etc/passwd' ),
			'ftp'                   => array( 'ftp://example.com' ),
			'no scheme'             => array( 'example.com' ),
			'protocol relative'     => array( '//example.com' ),
			'scheme only'           => array( 'https://' ),
			'credentials'           => array( 'https://user:pass@example.com' ),
			'username only'         => array( 'https://user@example.com' ),
			'newline injection'     => array( "https://example.com\r\nSet-Cookie: a=b" ),
			'null byte'             => array( "https://example.com\0.evil.test" ),
			'tab'                   => array( "https://exa\tmple.com" ),
		);
	}

	/**
	 * A size string splits into its two dimensions.
	 *
	 * @return void
	 */
	public function test_sizes_parse(): void {
		$this->assertSame( array( 728, 90 ), Campaign_Rules::parse_size( '728x90' ) );
		$this->assertSame( array( 300, 250 ), Campaign_Rules::parse_size( '300x250' ) );
		$this->assertSame( array( 160, 600 ), Campaign_Rules::parse_size( ' 160x600 ' ) );
	}

	/**
	 * **A multiplication sign is not an `x`.**
	 *
	 * The live ad-group taxonomy contains a term named with U+00D7, and every
	 * other one uses the letter. Accepting it here would let a size that looks
	 * right flow through to a placement that never matches — a bug that reads
	 * as a typo. The stored grammar is ASCII `x`; see docs/architecture.md.
	 *
	 * @return void
	 */
	public function test_a_multiplication_sign_is_not_accepted_as_a_size(): void {
		$this->assertNull( Campaign_Rules::parse_size( "728\u{00D7}90" ) );
		$this->assertFalse( Campaign_Rules::size_matches( 728, 90, "728\u{00D7}90" ) );
	}

	/**
	 * Malformed sizes yield null rather than a partial parse.
	 *
	 * @param string $size A size string that is not one.
	 * @return void
	 */
	#[DataProvider( 'data_invalid_sizes' )]
	public function test_invalid_sizes_are_refused( string $size ): void {
		$this->assertNull( Campaign_Rules::parse_size( $size ) );
	}

	/**
	 * Size strings that are not sizes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_invalid_sizes(): array {
		return array(
			'empty'        => array( '' ),
			'no separator' => array( '72890' ),
			'letters'      => array( 'wide x tall' ),
			// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- A zero-width ad size, not a hexadecimal integer. That it looks like one is the reason this case is here.
			'zero width'   => array( '0x90' ),
			'zero height'  => array( '728x0' ),
			'negative'     => array( '-728x90' ),
			'decimal'      => array( '728.5x90' ),
			'three parts'  => array( '728x90x2' ),
			'trailing'     => array( '728x90px' ),
			'capital x'    => array( '728X90' ),
		);
	}

	/**
	 * Dimensions have to match exactly. Close is a different ad.
	 *
	 * @return void
	 */
	public function test_size_matching_is_exact(): void {
		$this->assertTrue( Campaign_Rules::size_matches( 728, 90, '728x90' ) );

		$this->assertFalse( Campaign_Rules::size_matches( 728, 91, '728x90' ) );
		$this->assertFalse( Campaign_Rules::size_matches( 727, 90, '728x90' ) );
		$this->assertFalse( Campaign_Rules::size_matches( 90, 728, '728x90' ), 'Dimensions were matched transposed.' );
	}

	/**
	 * A campaign starting in the future with a later end is fine.
	 *
	 * @return void
	 */
	public function test_a_future_window_is_valid(): void {
		$now = 1_800_000_000;

		$result = Campaign_Rules::validate_window( $now + 86400, $now + 172800, $now );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * An open-ended campaign is valid; zero is not "before the start".
	 *
	 * @return void
	 */
	public function test_an_open_ended_window_is_valid(): void {
		$now = 1_800_000_000;

		$result = Campaign_Rules::validate_window( $now + 86400, 0, $now );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * A missing start date is reported once, and stops there — there is
	 * nothing useful to say about an end date relative to a start that does
	 * not exist.
	 *
	 * @return void
	 */
	public function test_a_missing_start_is_reported_alone(): void {
		$result = Campaign_Rules::validate_window( 0, 0, 1_800_000_000 );

		$this->assertSame( array( Campaign_Rules::ERROR_START_MISSING ), $result->codes() );
	}

	/**
	 * A start date in the past is refused.
	 *
	 * @return void
	 */
	public function test_a_past_start_is_refused(): void {
		$now = 1_800_000_000;

		$result = Campaign_Rules::validate_window( $now - 1, 0, $now );

		$this->assertTrue( $result->has( Campaign_Rules::ERROR_START_IN_PAST ) );
	}

	/**
	 * **Starting today is allowed**, which it was not before.
	 *
	 * A start has to be midnight in the site timezone, so comparing it against
	 * the current moment refused every start earlier than tomorrow — today's
	 * midnight is always in the past by the time somebody fills in the form.
	 * The bound is the start of the day, so a campaign can begin today.
	 *
	 * @return void
	 */
	public function test_starting_today_is_allowed(): void {
		$midnight = Campaign_Rules::day_start_ts( 1_800_000_000, 'UTC' );

		$this->assertFalse(
			Campaign_Rules::validate_window( $midnight, 0, $midnight )
				->has( Campaign_Rules::ERROR_START_IN_PAST )
		);
	}

	/**
	 * And still allowed once the day is underway.
	 *
	 * The case that matters in practice: somebody submitting at nine in the
	 * morning for a campaign whose start is that day's midnight.
	 *
	 * @return void
	 */
	public function test_starting_today_is_allowed_later_in_the_day(): void {
		$now      = 1_800_000_000;
		$midnight = Campaign_Rules::day_start_ts( $now, 'UTC' );

		$this->assertGreaterThan( $midnight, $now, 'The fixture must be mid-day, or this proves nothing.' );

		$this->assertFalse(
			Campaign_Rules::validate_window( $midnight, 0, $midnight )
				->has( Campaign_Rules::ERROR_START_IN_PAST )
		);
	}

	/**
	 * Yesterday is still the past.
	 *
	 * The negative half. Without it, a rule that accepted every date would pass
	 * the two assertions above.
	 *
	 * @return void
	 */
	public function test_starting_yesterday_is_refused(): void {
		$midnight = Campaign_Rules::day_start_ts( 1_800_000_000, 'UTC' );

		$this->assertTrue(
			Campaign_Rules::validate_window( $midnight - 86400, 0, $midnight )
				->has( Campaign_Rules::ERROR_START_IN_PAST )
		);
	}

	/**
	 * A second before today's midnight is refused, which is where the boundary
	 * actually lives.
	 *
	 * @return void
	 */
	public function test_the_boundary_is_midnight_exactly(): void {
		$midnight = Campaign_Rules::day_start_ts( 1_800_000_000, 'UTC' );

		$this->assertTrue(
			Campaign_Rules::validate_window( $midnight - 1, 0, $midnight )
				->has( Campaign_Rules::ERROR_START_IN_PAST )
		);
		$this->assertFalse(
			Campaign_Rules::validate_window( $midnight, 0, $midnight )
				->has( Campaign_Rules::ERROR_START_IN_PAST )
		);
	}

	/**
	 * Midnight is the site's, not UTC's.
	 *
	 * A publisher in Los Angeles choosing today must get their own midnight;
	 * comparing against UTC's would refuse the whole morning for anybody west
	 * of Greenwich and accept a past day for anybody east of it.
	 *
	 * @return void
	 */
	public function test_the_day_starts_in_the_supplied_timezone(): void {
		/*
		 * Mid-morning in both zones on purpose. The first draft used a round
		 * number that happened to be exactly midnight in Los Angeles, so the
		 * assertion that a day boundary precedes the moment inside it failed on
		 * an equality nothing was testing.
		 */
		$now = 1_800_030_000; // 2027-01-15 16:20 UTC, 08:20 in Los Angeles.

		$utc = Campaign_Rules::day_start_ts( $now, 'UTC' );
		$la  = Campaign_Rules::day_start_ts( $now, 'America/Los_Angeles' );

		$this->assertNotSame( $utc, $la, 'Two timezones produced the same midnight.' );
		$this->assertLessThan( $now, $utc );
		$this->assertLessThan( $now, $la );

		// Los Angeles is behind UTC, so its day started later in absolute time.
		$this->assertGreaterThan( $utc, $la );
	}

	/**
	 * An unusable timezone falls back to UTC rather than throwing.
	 *
	 * This runs during validation of a campaign somebody is trying to submit.
	 * A fatal there would be a worse answer than a boundary an hour out.
	 *
	 * @return void
	 */
	public function test_an_unknown_timezone_falls_back_rather_than_throwing(): void {
		$now = 1_800_000_000;

		$this->assertSame(
			Campaign_Rules::day_start_ts( $now, 'UTC' ),
			Campaign_Rules::day_start_ts( $now, 'Not/AZone' )
		);
	}

	/**
	 * An end date at or before the start is refused.
	 *
	 * @return void
	 */
	public function test_an_end_before_the_start_is_refused(): void {
		$now = 1_800_000_000;

		$before = Campaign_Rules::validate_window( $now + 172800, $now + 86400, $now );
		$this->assertTrue( $before->has( Campaign_Rules::ERROR_END_BEFORE_START ) );

		$equal = Campaign_Rules::validate_window( $now + 86400, $now + 86400, $now );
		$this->assertTrue( $equal->has( Campaign_Rules::ERROR_END_BEFORE_START ) );
	}

	/**
	 * Both window problems are reported together.
	 *
	 * Collecting rather than failing fast is the point: an advertiser who
	 * fixes one date, resubmits, and is then told about the other has been
	 * made to do the work twice.
	 *
	 * @return void
	 */
	public function test_both_window_problems_are_reported_together(): void {
		$now = 1_800_000_000;

		$result = Campaign_Rules::validate_window( $now - 86400, $now - 172800, $now );

		$this->assertTrue( $result->has( Campaign_Rules::ERROR_START_IN_PAST ) );
		$this->assertTrue( $result->has( Campaign_Rules::ERROR_END_BEFORE_START ) );
		$this->assertCount( 2, $result->codes() );
	}

	/**
	 * Whole-day boundaries are evaluated as local wall-clock times, including
	 * the short and long days around daylight-saving transitions.
	 *
	 * @return void
	 */
	public function test_whole_day_boundaries_are_dst_safe(): void {
		$zone = new \DateTimeZone( 'America/New_York' );

		$spring_start = new \DateTimeImmutable( '2027-03-14 00:00:00', $zone );
		$spring_end   = new \DateTimeImmutable( '2027-03-14 23:59:59', $zone );
		$fall_start   = new \DateTimeImmutable( '2027-11-07 00:00:00', $zone );
		$fall_end     = new \DateTimeImmutable( '2027-11-07 23:59:59', $zone );

		$this->assertTrue(
			Campaign_Rules::validate_day_boundaries( $spring_start->getTimestamp(), $spring_end->getTimestamp(), $zone->getName() )->is_valid()
		);
		$this->assertTrue(
			Campaign_Rules::validate_day_boundaries( $fall_start->getTimestamp(), $fall_end->getTimestamp(), $zone->getName() )->is_valid()
		);
		$this->assertSame( 82_799, $spring_end->getTimestamp() - $spring_start->getTimestamp() );
		$this->assertSame( 89_999, $fall_end->getTimestamp() - $fall_start->getTimestamp() );
	}

	/**
	 * Partial-day timestamps cannot enter a submission-grade schedule.
	 *
	 * @return void
	 */
	public function test_partial_day_boundaries_are_refused(): void {
		$zone   = new \DateTimeZone( 'America/Phoenix' );
		$start  = new \DateTimeImmutable( '2027-06-01 00:00:01', $zone );
		$end    = new \DateTimeImmutable( '2027-06-03 00:00:00', $zone );
		$result = Campaign_Rules::validate_day_boundaries( $start->getTimestamp(), $end->getTimestamp(), $zone->getName() );

		$this->assertSame(
			array( Campaign_Rules::ERROR_START_NOT_MIDNIGHT, Campaign_Rules::ERROR_END_NOT_DAY_END ),
			$result->codes()
		);
	}

	/**
	 * Only image creatives may be submitted by an advertiser.
	 *
	 * @return void
	 */
	public function test_only_images_are_advertiser_submittable(): void {
		$this->assertSame( 'image', Campaign_Rules::ADVERTISER_CREATIVE_KIND );
	}

	/**
	 * The scheme allowlist is exactly http and https.
	 *
	 * @return void
	 */
	public function test_the_scheme_allowlist_is_minimal(): void {
		$this->assertSame( array( 'http', 'https' ), Campaign_Rules::ALLOWED_URL_SCHEMES );
	}
}
