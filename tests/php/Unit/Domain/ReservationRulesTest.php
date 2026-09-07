<?php
/**
 * Which claims consume inventory, and which ones give it back.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Reservation_Rules;
use PHPUnit\Framework\TestCase;

/**
 * One question decides whether reservations work at all: which states count
 * against capacity. Too generous and the same inventory is promised to every
 * advertiser who asks, with the shortfall appearing only when the window runs.
 * Too strict and released inventory is never sellable again, so a publisher's
 * capacity ratchets downward with every cancellation.
 *
 * The lifecycle is asserted exhaustively rather than by example, because a
 * transition table with a hole in it fails in exactly one direction and
 * nowhere else.
 */
final class ReservationRulesTest extends TestCase {

	public function test_a_hold_consumes_capacity(): void {
		$this->assertTrue(
			Reservation_Rules::consumes( Reservation_Rules::HELD ),
			'A hold that consumed nothing would let the same inventory be promised to everybody who asked, and every check would pass.'
		);
	}

	public function test_a_confirmation_consumes_capacity(): void {
		$this->assertTrue( Reservation_Rules::consumes( Reservation_Rules::CONFIRMED ) );
	}

	public function test_giving_inventory_back_returns_it_to_the_pool(): void {
		$this->assertFalse(
			Reservation_Rules::consumes( Reservation_Rules::RELEASED ),
			'Capacity that never comes back ratchets downward with every cancelled booking until the placement refuses everything.'
		);
		$this->assertFalse( Reservation_Rules::consumes( Reservation_Rules::EXPIRED ) );
	}

	public function test_every_status_is_decided_one_way_or_the_other(): void {
		foreach ( Reservation_Rules::all() as $status ) {
			$this->assertIsBool(
				Reservation_Rules::consumes( $status ),
				"Status {$status} has no answer about capacity, which means a row nobody counted."
			);
		}

		$this->assertCount( 2, Reservation_Rules::consuming() );
		$this->assertCount( 4, Reservation_Rules::all() );
	}

	public function test_an_invented_status_is_not_storable(): void {
		$this->assertFalse( Reservation_Rules::is_status( 'pending' ) );
		$this->assertFalse( Reservation_Rules::consumes( 'pending' ) );
	}

	public function test_no_status_is_longer_than_the_column(): void {
		foreach ( Reservation_Rules::all() as $status ) {
			$this->assertLessThanOrEqual( Reservation_Rules::MAX_LENGTH, strlen( $status ) );
		}
	}

	public function test_a_hold_may_be_committed_released_or_left_to_expire(): void {
		$this->assertSame(
			array(
				Reservation_Rules::CONFIRMED,
				Reservation_Rules::RELEASED,
				Reservation_Rules::EXPIRED,
			),
			Reservation_Rules::next_from( Reservation_Rules::HELD )
		);
	}

	public function test_a_commitment_may_only_be_given_back(): void {
		$this->assertSame(
			array( Reservation_Rules::RELEASED ),
			Reservation_Rules::next_from( Reservation_Rules::CONFIRMED )
		);
		$this->assertFalse(
			Reservation_Rules::may_move( Reservation_Rules::CONFIRMED, Reservation_Rules::HELD ),
			'A hold is the weaker claim. Letting a commitment decay into one silently would have a publisher believe inventory was spoken for when nobody had committed to it.'
		);
	}

	public function test_released_and_expired_are_the_end(): void {
		foreach ( array( Reservation_Rules::RELEASED, Reservation_Rules::EXPIRED ) as $terminal ) {
			$this->assertSame( array(), Reservation_Rules::next_from( $terminal ) );

			foreach ( Reservation_Rules::all() as $target ) {
				$this->assertFalse(
					Reservation_Rules::may_move( $terminal, $target ),
					"Moving from {$terminal} to {$target} would restore a claim without re-checking capacity."
				);
			}
		}
	}

	public function test_no_status_may_move_to_itself(): void {
		foreach ( Reservation_Rules::all() as $status ) {
			$this->assertFalse(
				Reservation_Rules::may_move( $status, $status ),
				"A {$status} reservation moving to {$status} is a write with no change, and an audit row claiming one."
			);
		}
	}

	public function test_a_transition_out_of_an_invented_status_goes_nowhere(): void {
		$this->assertSame( array(), Reservation_Rules::next_from( 'pending' ) );
	}

	public function test_a_reservation_for_nothing_is_refused(): void {
		$this->assertFalse(
			Reservation_Rules::is_quantity( 0 ),
			'A row claiming no inventory reads to every later reader as a booking that was somehow lost.'
		);
		$this->assertFalse( Reservation_Rules::is_quantity( -5 ) );
		$this->assertTrue( Reservation_Rules::is_quantity( 1 ) );
	}
}
