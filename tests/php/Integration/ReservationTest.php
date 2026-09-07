<?php
/**
 * Booking inventory, giving it back, and refusing to promise it twice.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Reservation_Rules;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The rules `ReservationRulesTest` proves in the abstract, proved against the
 * table that has to implement them — because "held consumes capacity" is a
 * claim about a `SUM` over a `WHERE`, and a repository that asked the domain
 * and then summed a different set would pass every unit test.
 *
 * The negatives carry most of the weight: what a second booking must not be
 * allowed to claim, what a release must not leave consumed, and what expiry
 * must not touch.
 */
final class ReservationTest extends WP_UnitTestCase {

	/**
	 * Ledger under test.
	 *
	 * @var Reservation_Repository
	 */
	private Reservation_Repository $reservations;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$this->reservations = Plugin::instance()->container()->get( Reservation_Repository::class );

		$this->reservations->install_table();
	}

	/**
	 * An active placement.
	 *
	 * @return int
	 */
	private function placement(): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'slot-' . wp_generate_password( 8, false ),
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		return $placement_id;
	}

	/**
	 * A claim against one placement.
	 *
	 * @param int   $placement  Placement post id.
	 * @param int   $quantity   Opportunities wanted.
	 * @param int   $capacity   What the forecast says is available.
	 * @param array<string, mixed> $overrides Anything else to change.
	 * @return array<string, mixed>
	 */
	private function claim( int $placement, int $quantity, int $capacity, array $overrides = array() ): array {
		return array_merge(
			array(
				'placement'        => $placement,
				'opportunity'      => Opportunity::PAGE,
				'from'             => '2026-04-01',
				'to'               => '2026-04-30',
				'campaign'         => 11,
				'org'              => 22,
				'quantity'         => $quantity,
				'capacity'         => $capacity,
				'forecast_version' => 3,
			),
			$overrides
		);
	}

	public function test_a_migration_exists_to_create_the_table(): void {
		$this->assertTrue( $this->reservations->table_exists() );

		$this->assertArrayHasKey(
			28,
			Migration_Map::steps( Plugin::instance()->container() ),
			'Without a registered step a site upgrades its recorded version past a migration that never ran, and the ledger never appears.'
		);
	}

	public function test_a_booking_within_capacity_is_held(): void {
		$placement = $this->placement();

		$result = $this->reservations->claim( $this->claim( $placement, 4000, 10000 ) );

		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( '', $result['refused'] );

		$row = $this->reservations->find( $result['id'] );

		$this->assertIsArray( $row );
		$this->assertSame( Reservation_Rules::HELD, $row['status'] );
		$this->assertSame( 4000, $row['quantity'] );
		$this->assertSame(
			3,
			$row['forecast_version'],
			'An override has to be able to name the figure it overrode, and a reservation that cannot say which forecast it was checked against makes that impossible to reconstruct.'
		);
	}

	public function test_a_hold_consumes_capacity_against_the_next_booking(): void {
		$placement = $this->placement();

		$this->reservations->claim( $this->claim( $placement, 7000, 10000 ) );

		$second = $this->reservations->claim( $this->claim( $placement, 4000, 10000 ) );

		$this->assertSame( 0, $second['id'] );
		$this->assertSame(
			'capacity',
			$second['refused'],
			'A hold that consumed nothing would let the same inventory be promised to everybody who asked, and the shortfall would appear only when the window ran.'
		);
		$this->assertSame( 7000, $this->reservations->committed( $placement, Opportunity::PAGE, '2026-04-01', '2026-04-30' ) );
	}

	public function test_a_booking_that_exactly_fills_the_window_is_allowed(): void {
		$placement = $this->placement();

		$this->reservations->claim( $this->claim( $placement, 6000, 10000 ) );

		$second = $this->reservations->claim( $this->claim( $placement, 4000, 10000 ) );

		$this->assertGreaterThan(
			0,
			$second['id'],
			'Selling the last of the inventory is a sale, not an oversell. An off-by-one here leaves a placement permanently one opportunity short of bookable.'
		);
	}

	public function test_releasing_returns_the_inventory_to_the_pool(): void {
		$placement = $this->placement();

		$first = $this->reservations->claim( $this->claim( $placement, 9000, 10000 ) );

		$this->assertTrue( $this->reservations->move( $first['id'], Reservation_Rules::HELD, Reservation_Rules::RELEASED ) );
		$this->assertSame(
			0,
			$this->reservations->committed( $placement, Opportunity::PAGE, '2026-04-01', '2026-04-30' ),
			'Capacity that never comes back ratchets downward with every cancelled booking until the placement refuses everything.'
		);

		$second = $this->reservations->claim( $this->claim( $placement, 9000, 10000 ) );

		$this->assertGreaterThan( 0, $second['id'] );
	}

	public function test_a_confirmation_still_consumes_capacity(): void {
		$placement = $this->placement();

		$first = $this->reservations->claim( $this->claim( $placement, 9000, 10000 ) );

		$this->assertTrue( $this->reservations->move( $first['id'], Reservation_Rules::HELD, Reservation_Rules::CONFIRMED ) );
		$this->assertSame( 9000, $this->reservations->committed( $placement, Opportunity::PAGE, '2026-04-01', '2026-04-30' ) );
	}

	public function test_a_status_change_the_rules_refuse_does_not_reach_the_table(): void {
		$placement = $this->placement();

		$first = $this->reservations->claim( $this->claim( $placement, 100, 10000 ) );

		$this->reservations->move( $first['id'], Reservation_Rules::HELD, Reservation_Rules::CONFIRMED );

		$this->assertFalse(
			$this->reservations->move( $first['id'], Reservation_Rules::CONFIRMED, Reservation_Rules::HELD ),
			'Letting a commitment decay into a hold would have a publisher believe inventory was spoken for when nobody had committed to it.'
		);

		$row = $this->reservations->find( $first['id'] );

		$this->assertIsArray( $row );
		$this->assertSame( Reservation_Rules::CONFIRMED, $row['status'] );
	}

	public function test_a_move_from_the_wrong_status_changes_nothing(): void {
		$placement = $this->placement();

		$first = $this->reservations->claim( $this->claim( $placement, 100, 10000 ) );

		$this->reservations->move( $first['id'], Reservation_Rules::HELD, Reservation_Rules::CONFIRMED );

		$this->assertFalse(
			$this->reservations->move( $first['id'], Reservation_Rules::HELD, Reservation_Rules::RELEASED ),
			'Reading the status, deciding, and then writing would let two requests both see held and both act — releasing a reservation somebody else had just confirmed.'
		);

		$row = $this->reservations->find( $first['id'] );

		$this->assertIsArray( $row );
		$this->assertSame( Reservation_Rules::CONFIRMED, $row['status'] );
	}

	public function test_a_different_window_is_different_inventory(): void {
		$placement = $this->placement();

		$this->reservations->claim( $this->claim( $placement, 10000, 10000 ) );

		$may = $this->reservations->claim(
			$this->claim(
				$placement,
				10000,
				10000,
				array(
					'from' => '2026-05-01',
					'to'   => '2026-05-31',
				)
			)
		);

		$this->assertGreaterThan(
			0,
			$may['id'],
			'April being sold out says nothing about May, and a check that ignored the window would make a placement sellable exactly once.'
		);
	}

	public function test_a_refresh_booking_does_not_consume_page_inventory(): void {
		$placement = $this->placement();

		$this->reservations->claim( $this->claim( $placement, 10000, 10000, array( 'opportunity' => Opportunity::REFRESH ) ) );

		$page = $this->reservations->claim( $this->claim( $placement, 10000, 10000 ) );

		$this->assertGreaterThan(
			0,
			$page['id'],
			'Page and refresh are separate inventory, and summing them would make selling one kind exhaust the other.'
		);

		// Ten thousand of each, not twenty thousand of one pool.
		$this->assertSame( 10000, $this->reservations->committed( $placement, Opportunity::PAGE, '2026-04-01', '2026-04-30' ) );
		$this->assertSame( 10000, $this->reservations->committed( $placement, Opportunity::REFRESH, '2026-04-01', '2026-04-30' ) );
	}

	public function test_another_placement_is_not_this_ones_inventory(): void {
		$busy = $this->placement();
		$free = $this->placement();

		$this->reservations->claim( $this->claim( $busy, 10000, 10000 ) );

		$this->assertSame( 0, $this->reservations->committed( $free, Opportunity::PAGE, '2026-04-01', '2026-04-30' ) );
	}

	public function test_a_claim_for_nothing_is_refused(): void {
		$result = $this->reservations->claim( $this->claim( $this->placement(), 0, 10000 ) );

		$this->assertSame( 0, $result['id'] );
		$this->assertSame( 'quantity', $result['refused'] );
	}

	public function test_a_reversed_window_is_refused(): void {
		$result = $this->reservations->claim(
			$this->claim(
				$this->placement(),
				100,
				10000,
				array(
					'from' => '2026-04-30',
					'to'   => '2026-04-01',
				)
			)
		);

		$this->assertSame( 0, $result['id'] );
		$this->assertSame( 'window', $result['refused'] );
	}

	public function test_a_day_that_never_happened_is_refused(): void {
		/*
		 * The repositories used to check the *shape* of a date with a regex,
		 * which accepts the thirtieth of February. A claim carrying one ran a
		 * query that matched nothing and reported no problem, so the booking
		 * silently did not exist — indistinguishable from a placement with no
		 * inventory. `Domain\Utc_Day` is now the single reading and it parses.
		 */
		$result = $this->reservations->claim(
			$this->claim(
				$this->placement(),
				100,
				10000,
				array(
					'from' => '2026-02-30',
					'to'   => '2026-03-15',
				)
			)
		);

		$this->assertSame( 0, $result['id'] );
		$this->assertSame( 'window', $result['refused'] );
	}

	public function test_an_unknown_opportunity_is_refused(): void {
		$result = $this->reservations->claim( $this->claim( $this->placement(), 100, 10000, array( 'opportunity' => 'invented' ) ) );

		$this->assertSame( 0, $result['id'] );
		$this->assertSame( 'unknown_placement', $result['refused'] );
	}

	public function test_a_placement_with_no_forecast_can_book_nothing(): void {
		$result = $this->reservations->claim( $this->claim( $this->placement(), 1, 0 ) );

		$this->assertSame(
			0,
			$result['id'],
			'A placement nobody has measured has no capacity to sell, and treating unknown as unlimited is how an oversell starts.'
		);
		$this->assertSame( 'capacity', $result['refused'] );
	}

	public function test_expiry_takes_abandoned_holds_and_leaves_commitments(): void {
		$placement = $this->placement();

		$held      = $this->reservations->claim( $this->claim( $placement, 100, 10000 ) );
		$confirmed = $this->reservations->claim( $this->claim( $placement, 100, 10000 ) );

		$this->reservations->move( $confirmed['id'], Reservation_Rules::HELD, Reservation_Rules::CONFIRMED );

		$expired = $this->reservations->expire_through( '2026-06-01', 50 );

		$this->assertSame( 1, $expired );

		$after_held = $this->reservations->find( $held['id'] );
		$after_conf = $this->reservations->find( $confirmed['id'] );

		$this->assertIsArray( $after_held );
		$this->assertIsArray( $after_conf );
		$this->assertSame( Reservation_Rules::EXPIRED, $after_held['status'] );
		$this->assertSame(
			Reservation_Rules::CONFIRMED,
			$after_conf['status'],
			'A confirmed reservation whose window ended is a delivered booking. Expiring it rewrites history into a claim nobody honoured.'
		);
	}

	public function test_expiry_leaves_a_window_that_has_not_passed(): void {
		$placement = $this->placement();
		$held      = $this->reservations->claim( $this->claim( $placement, 100, 10000 ) );

		$this->assertSame( 0, $this->reservations->expire_through( '2026-04-15', 50 ) );

		$row = $this->reservations->find( $held['id'] );

		$this->assertIsArray( $row );
		$this->assertSame( Reservation_Rules::HELD, $row['status'] );
	}

	public function test_reservations_are_listed_by_organization(): void {
		$placement = $this->placement();

		$this->reservations->claim( $this->claim( $placement, 100, 10000 ) );
		$this->reservations->claim( $this->claim( $placement, 100, 10000, array( 'org' => 99 ) ) );

		$mine = $this->reservations->for_org( 22 );

		$this->assertCount( 1, $mine );
		$this->assertSame( 22, $mine[0]['org_id'] );
		$this->assertSame( array(), $this->reservations->for_org( 12345 ) );
	}

	public function test_the_container_supplies_the_ledger(): void {
		$this->assertInstanceOf(
			Reservation_Repository::class,
			Plugin::instance()->container()->get( Reservation_Repository::class )
		);
	}
}
