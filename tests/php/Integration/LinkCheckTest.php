<?php
/**
 * The one server-side fetch, and everything that stops it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Link_Checker;
use WP_UnitTestCase;

/**
 * Assert the negatives: what this must never fetch.
 *
 * Every refusal below is checked by counting requests, not by reading the
 * answer. A rule that refuses the URL but has already opened the connection
 * has failed in the only way that matters, and an assertion on the returned
 * error would pass over it.
 */
final class LinkCheckTest extends WP_UnitTestCase {

	/**
	 * The service under test.
	 *
	 * @var Link_Checker
	 */
	private Link_Checker $checker;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * The advertiser's own campaign.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * URLs the check asked for, newest last.
	 *
	 * @var array<int, string>
	 */
	private array $requested = array();

	/**
	 * Arguments of the last request.
	 *
	 * @var array<string, mixed>
	 */
	private array $args = array();

	/**
	 * What `pre_http_request` should answer with.
	 *
	 * @var array<string, mixed>|\WP_Error
	 */
	private $answer;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container       = Plugin::instance()->container();
		$this->checker   = $container->get( Link_Checker::class );
		$this->campaigns = $container->get( Campaign_Repository::class );

		$advertiser_id = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$org_id        = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Link check org',
			)
		);

		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $advertiser_id );
		wp_set_current_user( $advertiser_id );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_author' => $advertiser_id,
				'post_status' => 'aggr_draft',
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $org_id );

		$this->answer = array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '',
			'headers'  => array(),
			'cookies'  => array(),
		);

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tear_down();
	}

	/**
	 * Stands in for the network, and records what was asked for.
	 *
	 * @param mixed                $preempt Short-circuit value.
	 * @param array<string, mixed> $args    Request arguments.
	 * @param string               $url     Requested URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->requested[] = (string) $url;
		$this->args        = (array) $args;

		return $this->answer;
	}

	/**
	 * Stores a destination link without going through validation, so a test
	 * can put a URL on the campaign that the save path would never accept.
	 *
	 * @param string $url Candidate link.
	 * @return void
	 */
	private function store( string $url ): void {
		update_post_meta( $this->campaign_id, Campaign_Repository::META_DEFAULT_CLICK_URL, $url );
	}

	/**
	 * Addresses and names that must never be fetched.
	 *
	 * @return array<string, array{string}>
	 */
	public static function forbidden(): array {
		return array(
			'loopback'        => array( 'http://127.0.0.1/wp-admin/' ),
			'loopback name'   => array( 'http://localhost/' ),
			'private network' => array( 'http://10.0.0.1/' ),
			'metadata'        => array( 'http://169.254.169.254/latest/meta-data/' ),
			'ipv6 loopback'   => array( 'http://[::1]/' ),
			'internal name'   => array( 'https://git.internal/' ),
			'other port'      => array( 'https://example.com:6379/' ),
			'credentials'     => array( 'https://root:toor@example.com/' ),
			'file'            => array( 'file:///etc/passwd' ),
			'gopher'          => array( 'gopher://example.com/' ),
		);
	}

	/**
	 * Nothing leaves the server for a URL the rules refuse.
	 *
	 * The WordPress suites run PHPUnit 9.6, which reads the annotation rather
	 * than the attribute the unit suite uses; see docs/testing-strategy.md.
	 *
	 * @dataProvider forbidden
	 *
	 * @param string $url A URL that must be refused before any connection.
	 */
	public function test_it_makes_no_request_at_all_for_a_refused_url( string $url ): void {
		$this->store( $url );

		$result = $this->checker->check( $this->campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_link_not_checkable', $result->get_error_code(), $url );
		$this->assertSame( array(), $this->requested, 'A refused URL still reached the network: ' . $url );
		$this->assertNull( $this->campaigns->link_check( $this->campaign_id ), 'A refused URL was recorded as a result.' );
	}

	public function test_it_checks_a_public_link_and_records_what_it_found(): void {
		$this->store( 'https://example.com/offer' );

		$result = $this->checker->check( $this->campaign_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'works', $result['outcome'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'https://example.com/offer' ), $this->requested );
		$this->assertSame( $result, $this->campaigns->link_check( $this->campaign_id ) );
	}

	public function test_the_request_carries_the_safe_arguments(): void {
		$this->store( 'https://example.com/offer' );
		$this->checker->check( $this->campaign_id );

		$this->assertTrue( $this->args['reject_unsafe_urls'], 'WordPress must re-validate the URL and its redirects.' );
		$this->assertTrue( $this->args['sslverify'] );
		$this->assertSame( 'HEAD', $this->args['method'], 'The body is never read, so nothing should ask for one.' );
		$this->assertLessThanOrEqual( 5, $this->args['timeout'] );
		$this->assertLessThanOrEqual( 3, $this->args['redirection'] );
		$this->assertSame( array(), $this->args['cookies'], 'No cookie of this site may travel to a third party.' );
	}

	public function test_a_site_that_refuses_head_is_asked_again_with_get(): void {
		$this->store( 'https://example.com/offer' );
		$this->answer['response']['code'] = 405;

		$result = $this->checker->check( $this->campaign_id );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $this->requested );
		$this->assertSame( 'GET', $this->args['method'] );
		$this->assertSame( 2048, $this->args['limit_response_size'], 'A GET must not read a whole page.' );
	}

	public function test_a_missing_page_is_reported_as_missing(): void {
		$this->store( 'https://example.com/gone' );
		$this->answer['response']['code'] = 404;

		$result = $this->checker->check( $this->campaign_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'missing', $result['outcome'] );
		$this->assertSame( 404, $result['status'] );
	}

	public function test_a_network_failure_is_unreachable_not_an_error(): void {
		$this->store( 'https://example.com/offer' );
		$this->answer = new \WP_Error( 'http_request_failed', 'Connection refused' );

		$result = $this->checker->check( $this->campaign_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'unreachable', $result['outcome'] );
		$this->assertSame( 0, $result['status'] );
	}

	/**
	 * One ad's own link is checked, not the campaign's.
	 *
	 * The ad's destination dialog draws the campaign's own field now, check
	 * included, and an ad may link somewhere the campaign does not.
	 *
	 * @return void
	 */
	public function test_an_ads_own_link_is_what_gets_checked(): void {
		$this->store( 'https://example.com/campaign' );

		$creative_id = $this->creative( $this->campaign_id, 'https://example.com/this-ad' );
		$result      = $this->checker->check( $this->campaign_id, false, $creative_id );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'https://example.com/this-ad' ), $this->requested, "The campaign's link was checked instead of the ad's." );
	}

	/**
	 * An ad id from another campaign fetches nothing of that campaign's.
	 *
	 * **The route takes no URL for a reason**: it is the one place this site
	 * fetches an address somebody else chose, and it only ever fetches a link
	 * the caller's own campaign stores. An id belonging to somebody else
	 * falls back to this campaign's link rather than reaching theirs.
	 *
	 * @return void
	 */
	public function test_an_ad_from_another_campaign_is_not_fetched(): void {
		$this->store( 'https://example.com/campaign' );

		$other  = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
			)
		);
		$theirs = $this->creative( $other, 'https://not-mine.example.com/secret' );
		$result = $this->checker->check( $this->campaign_id, false, $theirs );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'https://example.com/campaign' ), $this->requested );
		$this->assertNotContains( 'https://not-mine.example.com/secret', $this->requested );
	}

	public function test_a_campaign_with_no_link_is_not_checked(): void {
		$result = $this->checker->check( $this->campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_link_missing', $result->get_error_code() );
		$this->assertSame( array(), $this->requested );
	}

	public function test_another_advertisers_campaign_answers_as_if_it_did_not_exist(): void {
		$this->store( 'https://example.com/offer' );

		$stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		wp_set_current_user( $stranger );

		$mine    = $this->checker->check( $this->campaign_id );
		$missing = $this->checker->check( $this->campaign_id + 99_000 );

		$this->assertWPError( $mine );
		$this->assertWPError( $missing );
		$this->assertSame( $missing->get_error_code(), $mine->get_error_code(), 'The answers differ, which tells a stranger the id is real.' );
		$this->assertSame( $missing->get_error_message(), $mine->get_error_message() );
		$this->assertSame( array(), $this->requested );
	}

	public function test_a_signed_out_visitor_checks_nothing(): void {
		$this->store( 'https://example.com/offer' );
		wp_set_current_user( 0 );

		$this->assertWPError( $this->checker->check( $this->campaign_id ) );
		$this->assertSame( array(), $this->requested );
	}

	public function test_the_stored_result_goes_stale_when_the_link_changes(): void {
		$this->store( 'https://example.com/offer' );
		$this->checker->check( $this->campaign_id );

		$this->assertIsArray( $this->checker->last( $this->campaign_id ) );

		$this->store( 'https://example.com/other' );

		$this->assertNull( $this->checker->last( $this->campaign_id ), 'A result about the old link was shown for the new one.' );
	}

	public function test_the_limiter_stops_a_run_of_checks(): void {
		$this->store( 'https://example.com/offer' );

		$refused = null;

		for ( $attempt = 0; $attempt < 40; $attempt++ ) {
			$result = $this->checker->check( $this->campaign_id );

			if ( is_wp_error( $result ) ) {
				$refused = $result;
				break;
			}
		}

		$this->assertNotNull( $refused, 'Forty checks in a row were all allowed.' );
		$this->assertSame( 'aggr_rate_limited', $refused->get_error_code() );
		$this->assertLessThanOrEqual( 30, count( $this->requested ) );
	}

	/**
	 * A creative on one campaign, with a link of its own.
	 *
	 * @param int    $campaign_id Owning campaign.
	 * @param string $click_url   Where it goes.
	 * @return int
	 */
	private function creative( int $campaign_id, string $click_url ): int {
		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $creative_id, Creative_Repository::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, Creative_Repository::META_CLICK_URL, $click_url );

		return $creative_id;
	}
}
