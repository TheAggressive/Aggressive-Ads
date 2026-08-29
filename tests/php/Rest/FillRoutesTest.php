<?php
/**
 * Native fill, beacon, and click hop.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Click_Hop;
use Aggressive\Ads\Workflow\Fill_Cache;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Public fill is module-gated, tokens are single-use, prefetch is not a view.
 */
final class FillRoutesTest extends WP_UnitTestCase {

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Fixture placement.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Enables native delivery and registers routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
		( new Installer( new Audit_Repository(), new Roles() ) )->install_delivery_tables();

		$this->settings     = Plugin::instance()->container()->get( Settings::class );
		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_name'   => 'leaderboard',
				'post_status' => 'publish',
				'post_title'  => 'Leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $this->placement_id, Placement_Repository::META_HOUSE_CLICK_URL, 'https://example.com/house' );
		update_post_meta(
			$this->placement_id,
			Placement_Repository::META_HOUSE_ATTACHMENT,
			(int) self::factory()->attachment->create_object(
				array(
					'file'           => 'house.png',
					'post_mime_type' => 'image/png',
				)
			)
		);

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Drops the settings option so later tests see defaults.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );
		unset( $_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_USER_AGENT'] );
		set_query_var( Click_Hop::QUERY_VAR, '' );
		parent::tear_down();
	}

	/**
	 * Fill is public by default. Native delivery is not a staff kill-switch.
	 *
	 * @return void
	 */
	public function test_fill_is_available_without_enabling_a_module(): void {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aggr/v1/fill/leaderboard' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * A known active slot returns JSON and never caches it.
	 *
	 * @return void
	 */
	public function test_fill_returns_a_known_slot_when_the_module_is_on(): void {
		$this->enable_native();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aggr/v1/fill/leaderboard' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? $response->get_headers()['cache-control'] ?? '' );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'leaderboard', $data['slot'] );
		$this->assertSame( '728x90', $data['size'] );
		$this->assertNull( $data['creative'] );
	}

	/**
	 * An unknown slug is a 404.
	 *
	 * @return void
	 */
	public function test_unknown_slot_is_a_404(): void {
		$this->enable_native();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aggr/v1/fill/no-such-slot' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A valid token counts once. A replay is a 409.
	 *
	 * @return void
	 */
	public function test_beacon_accepts_a_token_once(): void {
		$this->enable_native();

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$first = rest_get_server()->dispatch( $request );
		$this->assertSame( 204, $first->get_status() );

		$second = rest_get_server()->dispatch( $request );
		$this->assertSame( 409, $second->get_status() );
	}

	/**
	 * Prefetch is not an impression.
	 *
	 * @return void
	 */
	public function test_beacon_rejects_prefetch(): void {
		$this->enable_native();

		$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$response = rest_get_server()->dispatch( $request );

		unset( $_SERVER['HTTP_SEC_PURPOSE'] );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Garbage is a 400, not a count.
	 *
	 * @return void
	 */
	public function test_beacon_rejects_an_invalid_token(): void {
		$this->enable_native();

		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => 'not-a-token' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A ledger outage is explicit for a beacon and never breaks click-through.
	 *
	 * The impression caller must not receive a false success when the durable
	 * write failed. A paid click has the opposite availability requirement: its
	 * destination remains useful even when measurement is temporarily down.
	 *
	 * @return void
	 */
	public function test_event_ledger_failure_reports_unavailable_without_breaking_the_click(): void {
		global $wpdb;

		$this->enable_native();

		$events  = new Event_Repository();
		$table   = $events->table_name();
		$offline = $table . '_authorization_test_offline';
		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Failure injection against the isolated test ledger; the finally block restores the exact table before this test returns.
		$this->assertTrue( false !== $wpdb->query( "RENAME TABLE {$table} TO {$offline}" ), 'Could not take the test event ledger offline.' );

		try {
			$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
			$request->set_body_params( array( 'token' => $token ) );

			ob_start();
			$response = rest_get_server()->dispatch( $request );
			$output   = (string) ob_get_clean();

			$this->assertSame( 503, $response->get_status() );
			$this->assertSame( 'aggr_beacon_unavailable', $response->get_data()['code'] );
			$this->assertSame( '', $output, 'The ledger failure exposed a raw database error.' );
			$this->assertSame( 'https://example.com/house', $this->hop_location( $token ) );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Restores the table renamed above even when an assertion fails.
			$wpdb->query( "RENAME TABLE {$offline} TO {$table}" );
		}
	}

	/**
	 * A valid click token 302s to the house destination.
	 *
	 * @return void
	 */
	public function test_click_hop_redirects_to_the_house_url(): void {
		$this->enable_native();

		$token = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		set_query_var( Click_Hop::QUERY_VAR, $token );

		$redirected = '';

		add_filter(
			'wp_redirect',
			static function ( string $location ) use ( &$redirected ): string {
				$redirected = $location;
				throw new \RuntimeException( 'redirect' );
			}
		);

		try {
			Plugin::instance()->container()->get( Click_Hop::class )->hop();
			$this->fail( 'Click hop did not redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertSame( 'https://example.com/house', $redirected );
		$this->assertSame( 'no-referrer', Click_Hop::REFERRER_POLICY );
	}

	/**
	 * One token may impression and click. A second click is not counted.
	 *
	 * @return void
	 */
	public function test_one_token_can_impression_and_click_once_each(): void {
		global $wpdb;

		$this->enable_native();

		$token = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$hash  = ( new Fill_Token() )->hash( $token );
		$table = ( new Event_Repository() )->table_name();

		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );
		$this->assertSame( 204, rest_get_server()->dispatch( $request )->get_status() );

		$this->assertSame( 'https://example.com/house', $this->hop_location( $token ) );
		$this->assertSame( 'https://example.com/house', $this->hop_location( $token ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$events = $wpdb->get_col( $wpdb->prepare( "SELECT event FROM {$table} WHERE token_hash = %s ORDER BY event ASC", $hash ) );

		$this->assertSame( array( Event_Repository::TYPE_CLICK, Event_Repository::TYPE_SERVED ), $events );
	}

	/**
	 * A leftover token for a campaign that is no longer live is not a view.
	 *
	 * @return void
	 */
	public function test_beacon_rejects_a_token_that_is_no_longer_live(): void {
		$this->enable_native();

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 99999, 88888 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Prefetch via the older Purpose header is still not an impression.
	 *
	 * @return void
	 */
	public function test_beacon_rejects_purpose_prefetch(): void {
		$this->enable_native();

		$_SERVER['HTTP_PURPOSE'] = 'prefetch';

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	/** A cooperative crawler cannot become a paid impression. */
	public function test_beacon_rejects_an_obvious_bot(): void {
		$this->enable_native();

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Another site cannot mint fill or post beacons.
	 *
	 * @return void
	 */
	public function test_fill_and_beacon_reject_a_cross_origin_browser(): void {
		$this->enable_native();

		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

		$fill = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aggr/v1/fill/leaderboard' ) );
		$this->assertSame( 403, $fill->get_status() );

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Fetch Metadata cross-site is refused even when Origin is missing.
	 *
	 * @return void
	 */
	public function test_beacon_rejects_a_cross_site_fetch(): void {
		$this->enable_native();

		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Public fill JSON does not name internal post ids.
	 *
	 * @return void
	 */
	public function test_fill_json_omits_internal_ids(): void {
		$this->enable_native();

		Plugin::instance()->container()->get( Fill_Cache::class )->delete( $this->placement_id );

		add_filter(
			'wp_get_attachment_image_src',
			static function () {
				return array( 'https://example.org/house.png', 728, 90, false );
			}
		);

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aggr/v1/fill/leaderboard' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['house'] );
		$this->assertArrayNotHasKey( 'placement', $data['house'] );
		$this->assertArrayNotHasKey( 'campaign', $data['house'] );
		$this->assertArrayNotHasKey( 'creative', $data['house'] );
		$this->assertArrayHasKey( 'token', $data['house'] );
		$this->assertArrayHasKey( 'click', $data['house'] );
	}

	/**
	 * Turns native delivery on for this request.
	 */
	private function enable_native(): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;

		$this->assertTrue( $this->settings->save( $document ) );
	}

	/**
	 * Runs the hop and returns the Location, or empty when it did not redirect.
	 *
	 * @param string $token Fill token.
	 */
	private function hop_location( string $token ): string {
		set_query_var( Click_Hop::QUERY_VAR, $token );

		$redirected = '';

		$listener = static function ( string $location ) use ( &$redirected ): string {
			$redirected = $location;
			throw new \RuntimeException( 'redirect' );
		};

		add_filter( 'wp_redirect', $listener );

		try {
			Plugin::instance()->container()->get( Click_Hop::class )->hop();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		remove_filter( 'wp_redirect', $listener );

		return $redirected;
	}

	/**
	 * A view that overtakes its own delivery records both.
	 *
	 * The two beacons are independent fire-and-forget requests and nothing
	 * orders them. Refusing the early one lost it permanently — `sendBeacon`
	 * reports nothing back, so the client cannot know to retry.
	 *
	 * No leverage is granted: the token still has to be ours, a client wanting
	 * to inflate impressions could always beacon the delivery directly, and
	 * each event is spent once against `(token_hash, event)`.
	 *
	 * @return void
	 */
	public function test_a_view_arriving_first_records_its_delivery_too(): void {
		$this->enable_native();

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params(
			array(
				'token' => $token,
				'event' => 'viewable',
			)
		);

		$this->assertSame( 204, rest_get_server()->dispatch( $request )->get_status() );

		$events = Plugin::instance()->container()->get( Event_Repository::class );
		$hash   = ( new Fill_Token() )->hash( $token );

		$this->assertTrue(
			$events->exists( Event_Repository::TYPE_SERVED, $hash ),
			'A view was recorded without the delivery it implies.'
		);
		$this->assertTrue( $events->exists( Event_Repository::TYPE_VIEWABLE, $hash ) );
	}

	/**
	 * After the delivery, the same token may report one view.
	 *
	 * The positive half. A gate that refused every viewable would satisfy the
	 * test above and measure nothing, which is the failure this whole phase is
	 * about.
	 *
	 * @return void
	 */
	public function test_a_view_is_accepted_once_after_its_delivery(): void {
		$this->enable_native();

		$token = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];

		$served = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$served->set_body_params( array( 'token' => $token ) );
		$this->assertSame( 204, rest_get_server()->dispatch( $served )->get_status() );

		$viewable = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$viewable->set_body_params(
			array(
				'token' => $token,
				'event' => 'viewable',
			)
		);

		$this->assertSame(
			204,
			rest_get_server()->dispatch( $viewable )->get_status(),
			'A view was refused for a token that had already been delivered.'
		);

		$this->assertSame(
			409,
			rest_get_server()->dispatch( $viewable )->get_status(),
			'The same token reported a second view, so the count is not once per fill.'
		);
	}

	/**
	 * A delivery and a view are separate rows, not one another.
	 *
	 * The replay key is `(token_hash, event)`. If it were the token alone, the
	 * view above would be refused as a duplicate delivery; if the event were
	 * ignored, a view would overwrite the impression.
	 *
	 * @return void
	 */
	public function test_a_delivery_is_not_consumed_by_its_view(): void {
		$this->enable_native();

		$token = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];

		$served = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$served->set_body_params( array( 'token' => $token ) );
		rest_get_server()->dispatch( $served );

		$viewable = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$viewable->set_body_params(
			array(
				'token' => $token,
				'event' => 'viewable',
			)
		);
		rest_get_server()->dispatch( $viewable );

		$events = Plugin::instance()->container()->get( Event_Repository::class );
		$hash   = ( new Fill_Token() )->hash( $token );

		$this->assertTrue( $events->exists( Event_Repository::TYPE_SERVED, $hash ) );
		$this->assertTrue( $events->exists( Event_Repository::TYPE_VIEWABLE, $hash ) );
	}

	/**
	 * The client may only claim the two events a browser can observe.
	 *
	 * `request`, `fill` and `no_fill` are the server's own account of what it
	 * did, and `conversion` is P12's. A client that could write any of them
	 * could rewrite the funnel it is supposed to be measured by.
	 *
	 * @return void
	 */
	public function test_the_client_cannot_claim_a_server_owned_event(): void {
		$this->enable_native();

		foreach ( array( 'request', 'fill', 'no_fill', 'conversion', 'anything' ) as $event ) {
			$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
			$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
			$request->set_body_params(
				array(
					'token' => $token,
					'event' => $event,
				)
			);

			$this->assertSame(
				400,
				rest_get_server()->dispatch( $request )->get_status(),
				"The beacon accepted {$event}, which no client is entitled to write."
			);
		}
	}

	/**
	 * A page cached with the previous script keeps reporting impressions.
	 *
	 * The compatibility promise the contract makes: absent means `served`.
	 *
	 * @return void
	 */
	public function test_a_beacon_without_an_event_still_records_a_delivery(): void {
		$this->enable_native();

		$token   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$this->assertSame( 204, rest_get_server()->dispatch( $request )->get_status() );

		$events = Plugin::instance()->container()->get( Event_Repository::class );

		$this->assertTrue(
			$events->exists( Event_Repository::TYPE_SERVED, ( new Fill_Token() )->hash( $token ) )
		);
	}

	/**
	 * A view is accepted after the token's window has closed.
	 *
	 * An ad below the fold is delivered at page load and becomes viewable when
	 * somebody scrolls to it, routinely past the five-minute token window.
	 * Refusing those dropped exactly the inventory viewability exists to
	 * measure while the impression stayed in the denominator — a systematic
	 * under-count that reads as "our below-the-fold ads are never seen".
	 *
	 * @return void
	 */
	public function test_a_late_view_is_still_accepted(): void {
		$this->enable_native();

		$tokens = new Fill_Token();
		$token  = $tokens->mint_on_site( get_current_blog_id(), $this->placement_id, 0, 0, -60 )['token'];

		$this->assertNull( $tokens->parse( $token ), 'The fixture token was not actually expired.' );

		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params(
			array(
				'token' => $token,
				'event' => 'viewable',
			)
		);

		$this->assertSame(
			204,
			rest_get_server()->dispatch( $request )->get_status(),
			'A view arriving after the token window was dropped.'
		);
	}

	/**
	 * A delivery gets no such tolerance.
	 *
	 * The negative half, and the one that matters: expiry still bounds how long
	 * a token may report an impression, which is what keeps a harvested token
	 * from counting inventory hours later.
	 *
	 * @return void
	 */
	public function test_a_late_delivery_is_still_refused(): void {
		$this->enable_native();

		$token   = ( new Fill_Token() )->mint_on_site( get_current_blog_id(), $this->placement_id, 0, 0, -60 )['token'];
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $token ) );

		$this->assertSame(
			400,
			rest_get_server()->dispatch( $request )->get_status(),
			'An expired token recorded an impression.'
		);
	}
}
