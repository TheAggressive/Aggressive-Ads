<?php
/**
 * Real package, forecast, reservation and campaign writers behind the storefront.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Reservation_Rules;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Date_Input;
use Aggressive\Ads\Portal\Inventory_Actions;
use Aggressive\Ads\Portal\Inventory_Input;
use Aggressive\Ads\Portal\Inventory_View_Data;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Inventory_Availability_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Package_Manager;
use WP_UnitTestCase;

final class InventoryStorefrontTest extends WP_UnitTestCase {

	/**
	 * Advertiser account.
	 *
	 * @var int
	 */
	private int $user;
	/**
	 * Owning organization.
	 *
	 * @var int
	 */
	private int $org;
	/**
	 * Active placement.
	 *
	 * @var int
	 */
	private int $placement;
	/**
	 * Three-day package.
	 *
	 * @var int
	 */
	private int $package;
	/**
	 * Real signed form entry.
	 *
	 * @var Inventory_Actions
	 */
	private Inventory_Actions $actions;
	/**
	 * Shared catalogue/assessment view.
	 *
	 * @var Inventory_View_Data
	 */
	private Inventory_View_Data $view;
	/**
	 * Original external-cache state.
	 *
	 * @var bool
	 */
	private bool $external;

	/** Staff configure an actual package; an actual member browses it. */
	public function set_up(): void {
		parent::set_up();
		$this->external = true === wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );
		update_option( 'timezone_string', 'UTC' );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$placements      = new Placement_Repository();
		$this->placement = $placements->create( 'Storefront banner', 'storefront-banner' );
		$this->assertIsInt( $this->placement );
		$this->assertTrue(
			$placements->save(
				$this->placement,
				array(
					'name'       => 'Storefront banner',
					'slug'       => 'storefront-banner',
					'size'       => '728x90',
					'is_active'  => true,
					'sort_order' => 0,
				)
			)
		);
		$this->package = Plugin::instance()->container()->get( Package_Manager::class )->create(
			array(
				'name'            => 'Storefront package',
				'placement_ids'   => array( $this->placement ),
				'duration_days'   => 3,
				'custom_duration' => false,
				'price_cents'     => 12000,
				'currency'        => 'USD',
				'is_active'       => true,
				'is_default'      => true,
			)
		);
		$this->assertIsInt( $this->package );
		$this->user = (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) );
		$this->org  = ( new Org_Repository() )->create_for_owner( 'Storefront owner ' . $this->user, $this->user );
		$this->assertIsInt( $this->org );
		wp_set_current_user( $this->user );
		$this->actions = Plugin::instance()->container()->get( Inventory_Actions::class );
		$this->view    = Plugin::instance()->container()->get( Inventory_View_Data::class );
	}

	/** Restore request globals and cache mode. */
	public function tear_down(): void {
		$_GET = array();
		wp_using_ext_object_cache( $this->external );
		parent::tear_down();
	}

	/** Snapshot writer receives the signed-in organization and server-derived fixed duration. */
	public function test_request_carries_package_and_dates_without_trusting_prices_or_tenant(): void {
		$before                = $this->counts();
		$input                 = $this->input();
		$input['end_date']     = $this->day( 90 );
		$input['org_id']       = $this->org + 100;
		$input['price_cents']  = 1;
		$input['availability'] = 'available';
		$id                    = $this->actions->process( $input );
		$this->assertIsInt( $id );
		$campaigns = new Campaign_Repository();
		$this->assertSame( $this->org, $campaigns->org_id( $id ) );
		$this->assertSame( $this->package, $campaigns->package_id( $id ) );
		$this->assertSame( $this->placement, $campaigns->placement_ids( $id )[0] );
		$this->assertSame( 12000, $campaigns->budget_cents( $id ) );
		$this->assertSame( 'USD', $campaigns->currency( $id ) );
		$this->assertSame( $this->day( 12 ), wp_date( 'Y-m-d', $campaigns->end_ts( $id ), wp_timezone() ) );
		$this->assertSame( Post_Statuses::DRAFT, $campaigns->status( $id ) );
		$this->assertSame( 1, $campaigns->autosave_revision( $id ) );
		$after = $this->counts();
		$this->assertSame( $before['reservations'], $after['reservations'] );
		$this->assertSame( $before['forecasts'], $after['forecasts'] );
		$this->assertSame( $before['campaigns'] + 1, $after['campaigns'] );
	}

	/** A forged or absent nonce never reaches creation; the real hook is attached. */
	public function test_nonce_and_authorization_are_attached_to_the_real_action(): void {
		$this->assertNotFalse( has_action( 'admin_post_' . Inventory_Actions::CREATE_ACTION, array( $this->actions, 'handle' ) ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_' . Inventory_Actions::CREATE_ACTION ) );
		$before            = $this->counts();
		$input             = $this->input();
		$input['_wpnonce'] = 'forged';
		$this->assertWPError( $this->actions->process( $input ) );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $this->actions->process( $this->input() ) );
		$this->assertWPError( $this->view->view( array() ) );
		$this->assertSame( $before, $this->counts() );
	}

	/** Configuration changed after browsing must be checked again on POST. */
	public function test_deactivated_package_and_bad_dates_create_no_orphan_draft(): void {
		$before = $this->counts();
		$this->assertIsArray( $this->view->view( $this->input() ) );
		update_post_meta( $this->package, Package_Repository::META_IS_ACTIVE, 0 );
		$this->assertWPError( $this->actions->process( $this->input() ) );
		update_post_meta( $this->package, Package_Repository::META_IS_ACTIVE, 1 );
		$input               = $this->input();
		$input['start_date'] = '2027-02-31';
		$this->assertWPError( $this->actions->process( $input ) );
		$input['start_date'] = $this->day( -1 );
		$this->assertWPError( $this->actions->process( $input ) );
		$this->assertTrue( ( new Org_Repository() )->set_state( $this->org, Org_Repository::STATE_SUSPENDED ) );
		$this->assertWPError( $this->actions->process( $this->input() ) );
		$this->assertSame( $before, $this->counts() );
	}

	/** A failed package/window save leaves one named, editable draft and no false success. */
	public function test_failed_save_preserves_a_recoverable_draft(): void {
		$before = $this->counts();
		$fail   = static fn ( $check, int $object_id, string $meta_key ) => Campaign_Repository::META_END_TS === $meta_key ? false : $check;
		add_filter( 'update_post_metadata', $fail, 10, 3 );
		try {
			$result = $this->actions->process( $this->input() );
		} finally {
			remove_filter( 'update_post_metadata', $fail, 10 );
		}
		$this->assertWPError( $result );
		$id = $result->get_error_data()['campaign_id'];
		$this->assertGreaterThan( 0, $id );
		$campaigns = new Campaign_Repository();
		$this->assertSame( $this->org, $campaigns->org_id( $id ) );
		$this->assertSame( Post_Statuses::DRAFT, $campaigns->status( $id ) );
		$this->assertSame( 0, $campaigns->autosave_revision( $id ) );
		$this->assertSame( $before['campaigns'] + 1, $this->counts()['campaigns'] );
		$this->assertSame(
			1,
			Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Campaign_Editor::class )->save(
				$id,
				array(
					'package_id' => $this->package,
					'start_ts'   => (int) Date_Input::parse( $this->day( 10 ), false ),
				),
				0
			)
		);
	}

	/** The new public write surface stops at its per-account draft budget. */
	public function test_creation_rate_limit_is_attached_before_any_draft_is_written(): void {
		$limiter = Plugin::instance()->container()->get( Rate_Limiter::class );
		for ( $i = 0; $i < 20; ++$i ) {
			$this->assertTrue( $limiter->attempt( Rate_Limiter::ACTION_CAMPAIGN_CREATE, $this->user ) );
		}
		$before = $this->counts();
		$result = $this->actions->process( $this->input() );
		$this->assertWPError( $result );
		$this->assertSame( 'aggr_rate_limited', $result->get_error_code() );
		$this->assertWPError( Plugin::instance()->container()->get( \Aggressive\Ads\Portal\Campaign_Actions::class )->process_create() );
		$this->assertWPError( Plugin::instance()->container()->get( \Aggressive\Ads\REST\Campaigns_Controller::class )->create( new \WP_REST_Request( 'POST', '/aggr/v1/campaigns' ) ) );
		$this->assertSame( $before, $this->counts() );
	}

	/** A refused counter claim must stop the actual signed form before creation. */
	public function test_unavailable_creation_counter_refuses_without_writing_a_draft(): void {
		global $wpdb;
		$before     = $this->counts();
		$key        = 'aggr_rl_' . get_current_blog_id() . '_' . Rate_Limiter::ACTION_CAMPAIGN_CREATE . '_' . $this->user;
		$failed     = 0;
		$filter     = static function ( string $query ) use ( $wpdb, $key, &$failed ): string {
			if ( str_starts_with( $query, 'INSERT' ) && str_contains( $query, "'_transient_" . $key . "'" ) ) {
				++$failed;
				return $wpdb->prepare( 'SELECT %i FROM %i', 'aggr_missing_counter_column', $wpdb->options );
			}
			return $query;
		};
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$result = $this->actions->process( $this->input() );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppressed );
		}
		$this->assertSame( 1, $failed );
		$this->assertWPError( $result );
		$this->assertSame( 'aggr_rate_limit_unavailable', $result->get_error_code() );
		$this->assertSame( array( 'status' => 503 ), $result->get_error_data() );
		$this->assertSame( $before, $this->counts() );
		$this->assertIsInt( $this->actions->process( $this->input() ), 'The same form can be retried after storage recovers.' );
	}

	/** The request cannot use a posted organization when membership is absent. */
	public function test_an_unaffiliated_advertiser_cannot_select_an_organization(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'aggr_advertiser' ) ) );
		$before          = $this->counts();
		$input           = $this->input();
		$input['org_id'] = $this->org;
		$this->assertWPError( $this->actions->process( $input ) );
		$this->assertSame( $before, $this->counts() );
	}

	/** Date parsing refuses arrays, impossible dates, and overflowing identifiers. */
	public function test_typed_input_cannot_be_coerced_into_a_different_selection(): void {
		foreach ( array( array( 'package_id', array( '1' ) ), array( 'package_id', '1 OR 1=1' ), array( 'package_id', '9223372036854775808' ), array( 'start_date', array() ), array( 'end_date', '2027-02-31' ), array( 'start_date', str_repeat( 'x', 10000 ) ), array( 'start_date', "2027\0-1-1" ) ) as [ $field, $value ] ) {
			$input           = $this->input();
			$input[ $field ] = $value;
			$this->assertWPError( Inventory_Input::read( $input ) );
		}
	}

	/** Local calendar days survive the DST transition without being forced to 24 hours. */
	public function test_custom_schedule_preserves_site_timezone_and_dst(): void {
		update_option( 'timezone_string', 'America/New_York' );
		update_post_meta( $this->package, Package_Repository::META_CUSTOM_DURATION, 1 );
		$transitions = wp_timezone()->getTransitions( time() + 2 * DAY_IN_SECONDS, time() + 2 * YEAR_IN_SECONDS );
		$this->assertIsArray( $transitions );
		$offset = $transitions[0]['offset'];
		$spring = null;
		foreach ( $transitions as $transition ) {
			if ( $transition['offset'] === $offset + HOUR_IN_SECONDS ) {
				$spring = ( new \DateTimeImmutable( '@' . $transition['ts'] ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
				break;
			}
			$offset = $transition['offset'];
		}
		$this->assertInstanceOf( \DateTimeImmutable::class, $spring );
		$input               = $this->input();
		$input['start_date'] = $spring->modify( '-1 day' )->format( 'Y-m-d' );
		$input['end_date']   = $spring->modify( '+1 day' )->format( 'Y-m-d' );
		$id                  = $this->actions->process( $input );
		$this->assertIsInt( $id );
		$campaigns = new Campaign_Repository();
		$this->assertSame( 3 * DAY_IN_SECONDS - HOUR_IN_SECONDS - 1, $campaigns->end_ts( $id ) - $campaigns->start_ts( $id ) );
		$this->assertSame( $input['start_date'] . ' 00:00:00', wp_date( 'Y-m-d H:i:s', $campaigns->start_ts( $id ), wp_timezone() ) );
		$this->assertSame( $input['end_date'] . ' 23:59:59', wp_date( 'Y-m-d H:i:s', $campaigns->end_ts( $id ), wp_timezone() ) );
		$this->assertGreaterThan(
			0,
			( new Forecast_Repository() )->record(
				$this->placement,
				'page',
				gmdate( 'Y-m-d', $campaigns->start_ts( $id ) ),
				gmdate( 'Y-m-d', $campaigns->end_ts( $id ) ),
				array(
					'estimate'      => 100,
					'optimistic'    => 200,
					'confidence'    => 'high',
					'days_observed' => 30,
					'days_forecast' => 4,
				)
			)
		);
		$this->assertSame( 'limited', ( new Inventory_Availability_Repository() )->assess( array( $this->placement ), $campaigns->start_ts( $id ), $campaigns->end_ts( $id ), time() ) );
	}

	/** Native claims consume and release inventory; refresh inventory is not page inventory. */
	public function test_native_forecast_and_reservation_writers_drive_the_public_answer(): void {
		$this->forecast( 10 );
		$this->assertSame( 'available', $this->state() );
		$id = $this->reserve( 10 );
		$this->assertSame( 'unavailable', $this->state() );
		$this->assertTrue( ( new Reservation_Repository() )->move( $id, Reservation_Rules::HELD, Reservation_Rules::CONFIRMED ) );
		$this->assertSame( 'unavailable', $this->state() );
		$this->assertTrue( ( new Reservation_Repository() )->move( $id, Reservation_Rules::CONFIRMED, Reservation_Rules::RELEASED ) );
		$this->assertSame( 'available', $this->state() );
		$this->reserve( 100, 'refresh' );
		$this->assertSame( 'available', $this->state() );
	}

	/** Other windows cannot be treated as no commitment or prorated without daily facts. */
	public function test_overlapping_windows_require_confirmation_but_disjoint_windows_do_not(): void {
		$this->forecast( 100 );
		$this->reserve( 5, 'page', $this->day( 1 ), $this->day( 9 ) );
		$this->assertSame( 'available', $this->state() );
		$this->reserve( 5, 'page', $this->day( 11 ), $this->day( 15 ) );
		$this->assertSame( 'limited', $this->state() );
	}

	/** A whole-day forecast cannot describe the unelapsed portion of a flight already underway. */
	public function test_started_flights_require_confirmation_even_with_a_matching_fresh_forecast(): void {
		$from = gmdate( 'Y-m-d' );
		$to   = gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS );
		$this->assertGreaterThan(
			0,
			( new Forecast_Repository() )->record(
				$this->placement,
				'page',
				$from,
				$to,
				array(
					'estimate'      => 100,
					'optimistic'    => 200,
					'confidence'    => 'high',
					'days_observed' => 30,
					'days_forecast' => 3,
				)
			)
		);
		$this->assertSame( 'limited', ( new Inventory_Availability_Repository() )->assess( array( $this->placement ), strtotime( $from . ' UTC' ), strtotime( $to . ' 23:59:59 UTC' ), time() ) );
		$input               = $this->input();
		$input['start_date'] = $from;
		$input['check']      = '1';
		$model               = $this->view->view( $input );
		$this->assertIsArray( $model );
		$this->assertIsArray( $model['plan'] );
		$this->assertSame( 'Limited — confirm with the publisher', $model['availability'] );
	}

	/** Missing, stale and future-dated assessments cannot become measured availability. */
	public function test_snapshot_freshness_and_latest_version_are_preserved(): void {
		global $wpdb;
		$this->assertSame( 'limited', $this->state() );
		$this->forecast( 100 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Age the actual writer's snapshot to exercise freshness rather than supplying its public answer.
		$wpdb->update( ( new Forecast_Repository() )->table_name(), array( 'made_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'placement_id' => $this->placement ) );
		$this->assertSame( 'limited', $this->state() );
		$this->forecast( 0 );
		$this->assertSame( 'unavailable', $this->state() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A future-dated writer row must not be treated as a current assessment.
		$wpdb->update( ( new Forecast_Repository() )->table_name(), array( 'made_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), array( 'placement_id' => $this->placement ) );
		$this->assertSame( 'limited', $this->state() );
	}

	/** Database failure is distinct from a new placement with no forecast, and can recover. */
	public function test_failed_read_refuses_an_availability_answer_and_recovers(): void {
		global $wpdb;
		$this->forecast( 100 );
		$fail   = static fn ( string $sql ): string => str_starts_with( $sql, 'SELECT * FROM' ) && str_contains( $sql, 'aggr_forecasts' ) ? 'SELECT * FROM aggr_missing_inventory_test' : $sql;
		$errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$this->assertWPError( $this->state() );
			$input          = $this->input();
			$input['check'] = '1';
			$model          = $this->view->view( $input );
			$this->assertIsArray( $model );
			$this->assertSame( '', $model['availability'] );
			$this->assertStringContainsString( 'could not be checked', $model['error'] );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $errors );
		}
		$this->assertSame( 'available', $this->state() );
	}

	/** Actual HTML escapes translations, omits internal figures and never mutates inventory. */
	public function test_template_escapes_output_and_omits_staff_planning_facts(): void {
		$this->forecast( 123456789 );
		$before        = $this->counts();
		$_GET          = $this->input();
		$_GET['check'] = '1';
		$translate     = static fn ( string $translated, string $text, string $domain ): string => 'aggressive-ads' === $domain && 'Available to request' === $text ? '<b>Availability marker</b>' : $translated;
		add_filter( 'gettext', $translate, 10, 3 );
		ob_start();
		try {
			require AGGR_PLUGIN_DIR . 'templates/portal/screens/inventory.php';
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
			remove_filter( 'gettext', $translate, 10 );
		}
		$this->assertStringContainsString( '&lt;b&gt;Availability marker&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Availability marker</b>', $html );
		foreach ( array( '123456789', 'forecast_version', 'confidence', 'shortfall', 'committed', 'optimistic' ) as $private ) {
			$this->assertStringNotContainsString( $private, $html );
		}
		$this->assertStringContainsString( '728x90', $html );
		$this->assertStringContainsString( 'Prepare campaign request', $html );
		$this->assertSame( $before, $this->counts() );
	}

	/** A staff-selected attachment is still checked before it becomes an advertiser example. */
	public function test_house_examples_exclude_private_media_and_advertiser_creatives(): void {
		$image      = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'publisher-house.png',
				'post_mime_type' => 'image/png',
			)
		);
		$placements = new Placement_Repository();
		$this->assertTrue( $placements->set_house( $this->placement, $image, 'https://example.test', 'Publisher image' ) );
		$this->assertNotSame( '', $placements->public_house_image_url( $this->placement ) );
		update_post_meta( $image, \Aggressive\Ads\Repository\Creative_Repository::META_IS_CREATIVE, 12345 );
		$this->assertSame( '', $placements->public_house_image_url( $this->placement ) );
		$model = $this->view->view( $this->input() );
		$this->assertIsArray( $model );
		$this->assertSame( '', $model['placements'][0]['image'] );
		delete_post_meta( $image, \Aggressive\Ads\Repository\Creative_Repository::META_IS_CREATIVE );
		$private_parent = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);
		wp_update_post(
			array(
				'ID'          => $image,
				'post_parent' => $private_parent,
				'post_status' => 'inherit',
			)
		);
		$this->assertSame( 'publish', get_post_status( $image ), 'Published status alone cannot make a private post type public.' );
		$this->assertFalse( is_post_publicly_viewable( $private_parent ) );
		$this->assertSame( '', $placements->public_house_image_url( $this->placement ) );
		$public_parent = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		wp_update_post(
			array(
				'ID'          => $image,
				'post_parent' => $public_parent,
				'post_status' => 'inherit',
			)
		);
		$this->assertNotSame( '', $placements->public_house_image_url( $this->placement ) );
		wp_update_post(
			array(
				'ID'            => $public_parent,
				'post_password' => 'private-example-password',
			)
		);
		$this->assertSame( '', $placements->public_house_image_url( $this->placement ) );
		wp_update_post(
			array(
				'ID'            => $public_parent,
				'post_password' => '',
			)
		);
		wp_update_post(
			array(
				'ID'          => $image,
				'post_status' => 'private',
			)
		);
		$this->assertSame( '', $placements->public_house_image_url( $this->placement ) );
	}

	/**
	 * Signed selection for a known future flight.
	 *
	 * @return array<string, mixed>
	 */
	private function input(): array {
		return array(
			'package_id' => (string) $this->package,
			'start_date' => $this->day( 10 ),
			'end_date'   => '',
			'_wpnonce'   => wp_create_nonce( Inventory_Actions::CREATE_ACTION ),
		);
	}

	/**
	 * Local calendar date relative to today.
	 *
	 * @param int $offset Local days from today.
	 * @return string
	 */
	private function day( int $offset ): string {
		return ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Actual production availability.
	 *
	 * @return string|\WP_Error
	 */
	private function state(): string|\WP_Error {
		return ( new Inventory_Availability_Repository() )->assess( array( $this->placement ), (int) Date_Input::parse( $this->day( 10 ), false ), (int) Date_Input::parse( $this->day( 12 ), true ), time() );
	}

	/**
	 * Record native page opportunities.
	 *
	 * @param int $quantity Native page opportunities.
	 */
	private function forecast( int $quantity ): void {
		$this->assertGreaterThan(
			0,
			( new Forecast_Repository() )->record(
				$this->placement,
				'page',
				$this->day( 10 ),
				$this->day( 12 ),
				array(
					'estimate'      => $quantity,
					'optimistic'    => $quantity + 100,
					'confidence'    => 'high',
					'days_observed' => 30,
					'days_forecast' => 3,
				)
			)
		);
	}

	/**
	 * Claim through the production ledger; tests never supply a public status.
	 *
	 * @param int $quantity Native opportunities.
	 * @param string $kind Page or refresh.
	 * @param string|null $from Optional different window start.
	 * @param string|null $to Optional different window end.
	 * @return int
	 */
	private function reserve( int $quantity, string $kind = 'page', ?string $from = null, ?string $to = null ): int {
		$result = ( new Reservation_Repository() )->claim(
			array(
				'placement'        => $this->placement,
				'opportunity'      => $kind,
				'from'             => $from ?? $this->day( 10 ),
				'to'               => $to ?? $this->day( 12 ),
				'campaign'         => 0,
				'org'              => $this->org + 200,
				'quantity'         => $quantity,
				'capacity'         => 100000,
				'forecast_version' => 1,
			)
		);
		$this->assertSame( '', $result['refused'] );
		$this->assertGreaterThan( 0, $result['id'] );
		return $result['id'];
	}

	/**
	 * Durable rows before and after a request.
	 *
	 * @return array<string, int>
	 */
	private function counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count real durable effects, not mocked workflow calls.
		return array(
			'campaigns'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type=%s', $wpdb->posts, Post_Types::CAMPAIGN ) ),
			'forecasts'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', ( new Forecast_Repository() )->table_name() ) ),
			'reservations' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', ( new Reservation_Repository() )->table_name() ) ),
		);
	}
}
