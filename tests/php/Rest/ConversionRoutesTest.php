<?php
/**
 * The public conversion endpoint's security surface.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Repository\Conversion_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * This route is reachable by anybody on the internet, from any origin, and it
 * writes to the database. Every assertion here is about what it refuses.
 */
final class ConversionRoutesTest extends WP_UnitTestCase {

	/**
	 * Conversion ledger.
	 *
	 * @var Conversion_Repository
	 */
	private Conversion_Repository $conversions;

	/**
	 * Definition persistence.
	 *
	 * @var Conversion_Definition_Repository
	 */
	private Conversion_Definition_Repository $definitions;

	/**
	 * Event ledger.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Token minting.
	 *
	 * @var Fill_Token
	 */
	private Fill_Token $tokens;

	/**
	 * Settings, for the module gate.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Placement the fill was for.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Campaign owning the fill.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Creative that was served.
	 *
	 * @var int
	 */
	private int $creative_id;

	/**
	 * Organization owning the campaign.
	 *
	 * @var int
	 */
	private int $org_id = 4321;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->conversions = $container->get( Conversion_Repository::class );
		$this->definitions = $container->get( Conversion_Definition_Repository::class );
		$this->events      = $container->get( Event_Repository::class );
		$this->tokens      = $container->get( Fill_Token::class );
		$this->settings    = $container->get( Settings::class );

		$this->conversions->install_table();
		$this->definitions->install_table();
		$this->events->install_table();
		$container->get( Rollup_Repository::class )->install_table();

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, '1' );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );

		$this->creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;
		$this->settings->save( $document );

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * One definition's public key.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 */
	private function definition_key( array $overrides = array() ): string {
		$id = $this->definitions->create(
			array_merge(
				array(
					'name'                 => 'Purchase',
					'org_id'               => $this->org_id,
					'window_seconds'       => 2592000,
					'default_value_micros' => 4990000,
					'currency'             => 'USD',
					'allow_s2s'            => false,
					'status'               => Conversion_Definition::STATUS_ACTIVE,
				),
				$overrides
			)
		);

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );

		return $row['public_key'];
	}

	/**
	 * Mints a token and records its click at a chosen distance in the past.
	 *
	 * Relative to now rather than a fixed date, because the controller bounds
	 * the reported occurrence time to a day either side of now — a click
	 * backdated to a literal timestamp could never have a reportable outcome,
	 * and every test would fail as out-of-window for the wrong reason. The
	 * first draft did exactly that.
	 *
	 * @param bool $with_click Whether the token ever clicked.
	 * @param int  $ago        Seconds before now that the click happened.
	 */
	private function clicked_token( bool $with_click = true, int $ago = HOUR_IN_SECONDS ): string {
		$token = $this->tokens->mint( $this->placement_id, $this->campaign_id, $this->creative_id )['token'];

		if ( $with_click ) {
			$hash = $this->tokens->hash( $token );

			$this->assertTrue(
				$this->events->insert( Event_Repository::TYPE_CLICK, $this->placement_id, $this->campaign_id, $this->creative_id, $hash, str_repeat( 'c', 64 ) )
			);

			global $wpdb;
			$table = $this->events->table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture control over an event timestamp.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at_ts = %d WHERE token_hash = %s", time() - $ago, $hash ) );
		}

		return $token;
	}

	/**
	 * Rows in the conversion ledger.
	 */
	private function conversion_count(): int {
		global $wpdb;

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Dispatches one conversion report.
	 *
	 * @param array<string, mixed> $body Request body.
	 */
	private function report( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/aggr/v1/conversions' );
		$request->set_body_params( $body );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A valid report body.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function body( array $overrides = array() ): array {
		return array_merge(
			array(
				'token'           => $this->clicked_token(),
				'definition'      => $this->definition_key(),
				'idempotency_key' => 'order-1099-abcdef',
				'occurred_at'     => time(),
			),
			$overrides
		);
	}

	/**
	 * **An anonymous, cross-origin report is accepted, and that is the design.**
	 *
	 * A conversion is reported by the advertiser's own site. Requiring
	 * same-origin, as the impression beacon does, would refuse every real
	 * report. Nothing is lost: the request carries a signed token it cannot
	 * forge, spends one outcome once against a unique key, and can only credit
	 * a definition the campaign's organization owns.
	 */
	public function test_an_anonymous_report_is_recorded(): void {
		wp_set_current_user( 0 );

		$response = $this->report( $this->body() );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 1, $this->conversion_count() );
	}

	/**
	 * A retried report is success, not a conflict, and counts once.
	 *
	 * A reloaded thank-you page and a retried beacon are the normal way this
	 * endpoint is used. Answering 409 would make every correct integration look
	 * broken in somebody's console.
	 */
	public function test_a_repeated_report_is_success_and_counts_once(): void {
		$body = $this->body();

		$this->assertSame( 201, $this->report( $body )->get_status() );
		$this->assertSame( 200, $this->report( $body )->get_status() );
		$this->assertSame( 1, $this->conversion_count() );
	}

	/**
	 * **The response says nothing about what was credited.**
	 *
	 * The caller is an advertiser's public page. A body naming the campaign,
	 * the value or the definition would tell anyone holding a token what it was
	 * worth.
	 */
	public function test_an_accepted_report_reveals_nothing_about_the_attribution(): void {
		$response = $this->report( $this->body() );
		$data     = $response->get_data();

		$this->assertSame( array( 'ok' => true ), $data );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? '' );
	}

	/**
	 * **The three definition refusals are indistinguishable.**
	 *
	 * Unknown, archived and another organization's are different facts
	 * internally and must be one answer externally, or the endpoint becomes an
	 * oracle for which definitions exist on the site and who owns them.
	 */
	public function test_definition_refusals_are_indistinguishable(): void {
		$unknown  = $this->report( $this->body( array( 'definition' => str_repeat( 'd', 32 ) ) ) );
		$archived = $this->report( $this->body( array( 'definition' => $this->definition_key( array( 'status' => Conversion_Definition::STATUS_ARCHIVED ) ) ) ) );
		$foreign  = $this->report( $this->body( array( 'definition' => $this->definition_key( array( 'org_id' => $this->org_id + 1 ) ) ) ) );

		foreach ( array( $unknown, $archived, $foreign ) as $response ) {
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'aggr_conversion_refused', $response->get_data()['code'] );
		}

		$this->assertSame(
			$unknown->get_data()['message'],
			$foreign->get_data()['message'],
			'An unknown definition and another tenant\'s must read identically.'
		);

		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * A token that never clicked credits nothing, and reads like every other
	 * refusal.
	 */
	public function test_a_token_that_never_clicked_is_refused(): void {
		$response = $this->report( $this->body( array( 'token' => $this->clicked_token( false ) ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aggr_conversion_refused', $response->get_data()['code'] );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * A forged token is refused.
	 */
	public function test_a_forged_token_is_refused(): void {
		$token = $this->clicked_token();
		$parts = explode( '.', $token );

		// Keep the shape, break the signature.
		$parts[6] = str_repeat( '0', 32 );

		$response = $this->report( $this->body( array( 'token' => implode( '.', $parts ) ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **A token minted for another site credits nothing here.**
	 *
	 * Post ids restart on every site in a network, so a token bound to blog 7
	 * naming campaign 42 would otherwise credit whatever campaign 42 is on this
	 * site.
	 */
	public function test_a_token_bound_to_another_site_is_refused(): void {
		$foreign = $this->tokens->mint_on_site( get_current_blog_id() + 1, $this->placement_id, $this->campaign_id, $this->creative_id )['token'];

		$response = $this->report( $this->body( array( 'token' => $foreign ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **An expired token is accepted, and must be.**
	 *
	 * `Fill_Token::TTL_SECONDS` is five minutes and bounds when reporting may
	 * start; an attribution window is days. Refusing an expired token would
	 * refuse every conversion that is not immediate, which is nearly all of
	 * them.
	 */
	public function test_an_expired_token_still_attributes(): void {
		$expired = $this->tokens->mint_on_site( get_current_blog_id(), $this->placement_id, $this->campaign_id, $this->creative_id, -3600 )['token'];
		$hash    = $this->tokens->hash( $expired );

		$this->assertTrue(
			$this->events->insert( Event_Repository::TYPE_CLICK, $this->placement_id, $this->campaign_id, $this->creative_id, $hash, str_repeat( 'c', 64 ) )
		);

		$this->assertNull( $this->tokens->parse( $expired ), 'The fixture token must actually be expired.' );

		$response = $this->report(
			array(
				'token'           => $expired,
				'definition'      => $this->definition_key(),
				'idempotency_key' => 'order-1099-abcdef',
				'occurred_at'     => time(),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 1, $this->conversion_count() );
	}

	/**
	 * A report outside the definition's window is refused.
	 */
	public function test_a_report_past_the_window_is_refused(): void {
		$response = $this->report(
			array(
				// Three hours ago, against the shortest window the domain
				// allows. `MIN_WINDOW_SECONDS` is an hour, so a shorter
				// definition would clamp and prove nothing.
				'token'           => $this->clicked_token( true, 3 * HOUR_IN_SECONDS ),
				'definition'      => $this->definition_key( array( 'window_seconds' => HOUR_IN_SECONDS ) ),
				'idempotency_key' => 'order-1099-abcdef',
				'occurred_at'     => time(),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * And a report inside that same short window is accepted.
	 *
	 * The negative above is only meaningful beside this: without it, a window
	 * check that refused everything would pass.
	 */
	public function test_a_report_inside_a_short_window_is_accepted(): void {
		$response = $this->report(
			array(
				'token'           => $this->clicked_token( true, 60 ),
				'definition'      => $this->definition_key( array( 'window_seconds' => HOUR_IN_SECONDS ) ),
				'idempotency_key' => 'order-1099-abcdef',
				'occurred_at'     => time(),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 1, $this->conversion_count() );
	}

	/**
	 * A nonsense clock is refused rather than stored.
	 *
	 * The only client-supplied value that reaches attribution, bounded so a
	 * device set to 2038 cannot record an occurrence date nothing lines up with.
	 */
	public function test_an_absurd_occurrence_time_is_refused(): void {
		$response = $this->report( $this->body( array( 'occurred_at' => time() + ( 400 * DAY_IN_SECONDS ) ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * Malformed input never reaches the workflow.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function malformed(): array {
		return array(
			'no definition'        => array( array( 'definition' => '' ) ),
			'a short key'          => array( array( 'idempotency_key' => 'abc' ) ),
			'a key with a space'   => array( array( 'idempotency_key' => 'order 1099 abc' ) ),
			'a key with a newline' => array( array( 'idempotency_key' => "order-1099-abcdef\n" ) ),
			'a sql fragment key'   => array( array( 'definition' => "' OR '1'='1" ) ),
			'an empty token'       => array( array( 'token' => '' ) ),
			'a token with markup'  => array( array( 'token' => '<script>' ) ),
		);
	}

	/**
	 * Asserts one malformed report.
	 *
	 * @dataProvider malformed
	 *
	 * @param array<string, mixed> $overrides The one thing wrong with it.
	 */
	public function test_malformed_input_is_refused( array $overrides ): void {
		$response = $this->report( $this->body( $overrides ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 0, $this->conversion_count() );
	}

	/**
	 * **The conversion bucket is not the beacon's.**
	 *
	 * Sharing would couple two unrelated volumes: a visitor browsing a page
	 * full of ads spends the beacon budget, and if conversions drew from the
	 * same pool that visitor's own purchase could go unrecorded.
	 */
	public function test_conversions_have_their_own_rate_limit_bucket(): void {
		$this->assertNotSame(
			\Aggressive\Ads\Security\Rate_Limiter::ACTION_BEACON,
			\Aggressive\Ads\Security\Rate_Limiter::ACTION_CONVERSION
		);

		$this->assertGreaterThan(
			0,
			\Aggressive\Ads\Security\Rate_Limiter::limit_for( \Aggressive\Ads\Security\Rate_Limiter::ACTION_CONVERSION ),
			'An action with no configured limit is an action with no limit at all.'
		);
	}
}
