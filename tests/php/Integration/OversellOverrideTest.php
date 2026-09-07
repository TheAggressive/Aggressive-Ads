<?php
/**
 * Selling past a forecast is allowed, unrecorded is not.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Availability;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Supply_Forecast;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Booking_Service;
use WP_Error;
use WP_UnitTestCase;

/**
 * The contract asks for one behaviour that reads as a contradiction until the
 * reason is stated: *oversell warns and logs the override rather than silently
 * blocking staff.* A forecast is the twentieth percentile of observed days, so
 * a publisher who knows their inventory better than the model is often right to
 * sell past it — what must not happen is selling past it unrecorded.
 *
 * So most of these assert what the log holds afterwards, not whether the
 * booking succeeded. An override that worked and left no trace is the failure.
 */
final class OversellOverrideTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Booking_Service
	 */
	private Booking_Service $bookings;

	/**
	 * Snapshot storage.
	 *
	 * @var Forecast_Repository
	 */
	private Forecast_Repository $forecasts;

	/**
	 * Reservation ledger.
	 *
	 * @var Reservation_Repository
	 */
	private Reservation_Repository $reservations;

	/**
	 * Audit log.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$container          = Plugin::instance()->container();
		$this->bookings     = $container->get( Booking_Service::class );
		$this->forecasts    = $container->get( Forecast_Repository::class );
		$this->reservations = $container->get( Reservation_Repository::class );
		$this->audit        = $container->get( Audit_Repository::class );

		$this->forecasts->install_table();
		$this->reservations->install_table();
		$this->audit->install_table();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * An active placement.
	 *
	 * @return int
	 */
	private function placement(): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'slot-' . wp_generate_password( 8, false ),
			)
		);

		update_post_meta( $id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $id, Placement_Repository::META_SIZE, '728x90' );

		return $id;
	}

	/**
	 * Stores a forecast for the window every test books against.
	 *
	 * @param int $placement Placement post id.
	 * @param int $estimate  What the window is forecast to supply.
	 * @return int Version stored.
	 */
	private function forecast( int $placement, int $estimate ): int {
		return $this->forecasts->record(
			$placement,
			Opportunity::PAGE,
			'2026-06-01',
			'2026-06-30',
			array(
				'estimate'      => $estimate,
				'optimistic'    => $estimate * 2,
				'confidence'    => Supply_Forecast::CONFIDENCE_HIGH,
				'days_observed' => 90,
				'days_forecast' => 30,
			)
		);
	}

	/**
	 * A claim against the forecast window.
	 *
	 * @param int $placement Placement post id.
	 * @param int $quantity  Opportunities wanted.
	 * @return array<string, mixed>
	 */
	private function claim( int $placement, int $quantity ): array {
		return array(
			'placement'   => $placement,
			'opportunity' => Opportunity::PAGE,
			'from'        => '2026-06-01',
			'to'          => '2026-06-30',
			'campaign'    => 41,
			'org'         => 42,
			'quantity'    => $quantity,
		);
	}

	/**
	 * Audit rows for one event name.
	 *
	 * @param string $event Event name.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $event ): array {
		global $wpdb;

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading this plugin's own audit table in a test.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event = %s", $event ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function test_a_booking_within_the_forecast_needs_no_reason(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 10000 );

		$result = $this->bookings->book( $this->claim( $placement, 4000 ) );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( Availability::AVAILABLE, $result['verdict'] );
		$this->assertSame( array(), $this->rows( 'reservation.oversold' ) );
	}

	public function test_an_oversell_without_a_reason_is_refused(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 1000 );

		$result = $this->bookings->book( $this->claim( $placement, 5000 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_booking_oversell', $result->get_error_code() );
		$this->assertSame(
			4000,
			$result->get_error_data()['shortfall'],
			'A warning has to say by how much, or the person answering it is guessing too.'
		);
		$this->assertSame( array(), $this->reservations->for_org( 42 ), 'Nothing was booked.' );
	}

	public function test_an_oversell_with_a_reason_proceeds(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 1000 );

		$result = $this->bookings->book( $this->claim( $placement, 5000 ), 'Sponsor guaranteed the shortfall in writing.' );

		$this->assertIsArray(
			$result,
			'Blocking a publisher who knows their inventory better than a twentieth-percentile model is the behaviour the contract rules out.'
		);
		$this->assertSame( Availability::OVERSELL, $result['verdict'] );
		$this->assertSame( 4000, $result['shortfall'] );
		$this->assertCount( 1, $this->reservations->for_org( 42 ) );
	}

	public function test_the_override_names_everything_an_investigation_needs(): void {
		$placement = $this->placement();
		$version   = $this->forecast( $placement, 1000 );

		$this->bookings->book( $this->claim( $placement, 5000 ), 'Sponsor guaranteed the shortfall in writing.' );

		$rows = $this->rows( 'reservation.oversold' );

		$this->assertCount( 1, $rows );

		$context = json_decode( (string) $rows[0]['context'], true );

		$this->assertIsArray( $context );
		$this->assertSame( get_current_user_id(), (int) $rows[0]['actor_user_id'], 'Actor.' );
		$this->assertSame( 'Sponsor guaranteed the shortfall in writing.', $context['reason'], 'Reason.' );
		$this->assertSame( $version, $context['forecast_version'], 'Forecast version.' );
		$this->assertSame( 4000, $context['shortfall'], 'Expected impact.' );
		$this->assertSame(
			1000,
			$context['forecast'],
			'The value as well as the version, because a version number stops meaning anything once retention purges the snapshot.'
		);
	}

	public function test_a_user_who_may_not_manage_inventory_is_refused_and_recorded(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 10000 );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$this->assertFalse( current_user_can( Capabilities::MANAGE_PLACEMENTS ) );

		$result = $this->bookings->book( $this->claim( $placement, 100 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_booking_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $this->reservations->for_org( 42 ) );

		$denied = $this->rows( 'reservation.denied' );

		$this->assertCount(
			1,
			$denied,
			'A log that only records successes cannot show an attempt, and absence is not a thing anybody can query for.'
		);
		$this->assertSame( 'denied', $denied[0]['outcome'] );
	}

	public function test_a_reviewer_may_not_book_either(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 10000 );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->assertInstanceOf(
			WP_Error::class,
			$this->bookings->book( $this->claim( $placement, 100 ) ),
			'Reviewing campaigns is a daily job; committing inventory is a commercial one, and the roles are separated on purpose.'
		);
	}

	public function test_an_unforecast_window_is_bookable_without_a_reason(): void {
		$placement = $this->placement();

		$result = $this->bookings->book( $this->claim( $placement, 5000 ) );

		$this->assertIsArray(
			$result,
			'Refusing on the strength of no evidence would make every new placement unsellable until a quarter of history existed.'
		);
		$this->assertSame( Availability::UNKNOWN, $result['verdict'] );
		$this->assertSame( array(), $this->rows( 'reservation.oversold' ), 'Nothing was overridden.' );
	}

	public function test_an_acknowledged_oversell_does_not_disable_the_ledger_guard(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 1000 );

		$this->bookings->book( $this->claim( $placement, 5000 ), 'Agreed with the sponsor.' );

		/*
		 * The override raised the ceiling by exactly its own shortfall. A later
		 * booking must meet a check that now counts the five thousand already
		 * held, rather than one somebody left switched off.
		 */
		$second = $this->bookings->book( $this->claim( $placement, 100 ) );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'aggr_booking_oversell', $second->get_error_code() );
	}

	public function test_availability_reports_what_the_screen_needs(): void {
		$placement = $this->placement();
		$version   = $this->forecast( $placement, 10000 );

		$this->bookings->book( $this->claim( $placement, 4000 ) );

		$view = $this->bookings->availability( $placement, Opportunity::PAGE, '2026-06-01', '2026-06-30', 1000 );

		$this->assertSame( Availability::AVAILABLE, $view['verdict'] );
		$this->assertSame( 10000, $view['capacity'] );
		$this->assertSame( 4000, $view['committed'] );
		$this->assertSame( 6000, $view['remaining'] );
		$this->assertSame( $version, $view['forecast_version'] );
	}

	public function test_a_released_booking_frees_the_forecast_again(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 5000 );

		$first = $this->bookings->book( $this->claim( $placement, 5000 ) );

		$this->assertIsArray( $first );
		$this->assertInstanceOf( WP_Error::class, $this->bookings->book( $this->claim( $placement, 1 ) ) );

		$this->reservations->move( $first['id'], 'held', 'released' );

		$this->assertIsArray(
			$this->bookings->book( $this->claim( $placement, 1 ) ),
			'Capacity that never comes back ratchets downward with every cancellation until the placement refuses everything.'
		);
	}
}
