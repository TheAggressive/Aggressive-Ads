<?php
/**
 * Room, short of room, and never measured are three different answers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Availability;
use PHPUnit\Framework\TestCase;

/**
 * The distinction this exists for is the third verdict. Treating a placement
 * nobody has forecast as unlimited is how an oversell starts; treating it as
 * zero makes every new placement unsellable until a quarter of history exists.
 * Both readings are wrong, and a two-valued answer forces one of them.
 */
final class AvailabilityTest extends TestCase {

	public function test_a_request_that_fits_is_available(): void {
		$decision = Availability::decide( 10000, 4000, 5000 );

		$this->assertSame( Availability::AVAILABLE, $decision['verdict'] );
		$this->assertSame( 6000, $decision['remaining'] );
		$this->assertSame( 0, $decision['shortfall'] );
	}

	public function test_taking_exactly_what_is_left_is_a_sale(): void {
		$decision = Availability::decide( 10000, 4000, 6000 );

		$this->assertSame(
			Availability::AVAILABLE,
			$decision['verdict'],
			'Selling the last of the inventory is a sale, and an off-by-one here leaves a placement permanently one opportunity short of bookable.'
		);
	}

	public function test_one_more_than_is_left_is_an_oversell(): void {
		$decision = Availability::decide( 10000, 4000, 6001 );

		$this->assertSame( Availability::OVERSELL, $decision['verdict'] );
		$this->assertSame( 1, $decision['shortfall'] );
	}

	public function test_the_shortfall_is_what_the_forecast_cannot_cover(): void {
		$decision = Availability::decide( 10000, 9000, 5000 );

		$this->assertSame( 1000, $decision['remaining'] );
		$this->assertSame(
			4000,
			$decision['shortfall'],
			'"We oversold" is not actionable. "We sold four thousand more than we expect to have" is, and it is what an override records as its expected impact.'
		);
	}

	public function test_an_unforecast_window_is_unknown_rather_than_full(): void {
		$decision = Availability::decide( null, 0, 5000 );

		$this->assertSame( Availability::UNKNOWN, $decision['verdict'] );
		$this->assertNull(
			$decision['remaining'],
			'A placement nobody has measured has not been measured as full, and a screen showing nought would say it is sold out.'
		);
		$this->assertSame(
			0,
			$decision['shortfall'],
			'Reporting the whole request as an overrun would put a number on a screen that means "we have no idea", and a number is read as knowledge.'
		);
	}

	public function test_an_already_oversold_window_reports_no_headroom(): void {
		$decision = Availability::decide( 1000, 4000, 100 );

		$this->assertSame(
			0,
			$decision['remaining'],
			'Negative headroom added to the next request would quietly reduce the shortfall it is about to warn about.'
		);
		$this->assertSame( Availability::OVERSELL, $decision['verdict'] );
		$this->assertSame( 100, $decision['shortfall'] );
	}

	public function test_a_forecast_of_nothing_is_not_the_same_as_no_forecast(): void {
		$measured = Availability::decide( 0, 0, 1 );

		$this->assertSame( Availability::OVERSELL, $measured['verdict'] );
		$this->assertSame( 0, $measured['remaining'] );

		$unmeasured = Availability::decide( null, 0, 1 );

		$this->assertSame( Availability::UNKNOWN, $unmeasured['verdict'] );
		$this->assertNull( $unmeasured['remaining'] );
	}

	public function test_an_advertiser_is_told_yes_or_no_and_nothing_else(): void {
		$this->assertTrue( Availability::bookable( Availability::AVAILABLE ) );
		$this->assertFalse( Availability::bookable( Availability::OVERSELL ) );
		$this->assertTrue(
			Availability::bookable( Availability::UNKNOWN ),
			'Refusing on the strength of no evidence would make every new placement unsellable until a quarter of history existed.'
		);
	}

	/**
	 * **The ceiling is what the ledger re-checks against, and it is per claim.**
	 *
	 * Its value is only observable under concurrency — if another booking lands
	 * between the reading and the claim, the ledger's own total exceeds this
	 * and the second claim is refused rather than quietly doubling the window.
	 * The PHP suites are single-connection and cannot stage that race, which is
	 * exactly why the rule lives here: asserted directly rather than inferred
	 * from a scenario nobody can reproduce.
	 *
	 * @return void
	 */
	public function test_the_ledger_ceiling_admits_this_claim_and_no_more(): void {
		$this->assertSame(
			9000,
			Availability::ceiling( 4000, 5000 ),
			'Enough for what is held plus what is being asked, so a claim that arrives in between is refused instead of accommodated.'
		);
		$this->assertSame( 5000, Availability::ceiling( 0, 5000 ) );
	}

	public function test_the_ceiling_is_never_unbounded(): void {
		foreach ( array( array( 0, 1 ), array( 4000, 5000 ), array( 999999, 1 ) ) as $case ) {
			$ceiling = Availability::ceiling( $case[0], $case[1] );

			$this->assertSame(
				$case[0] + $case[1],
				$ceiling,
				'An unbounded ceiling passes every concurrent claim, and the oversell appears only when the window runs.'
			);
			$this->assertLessThan( PHP_INT_MAX, $ceiling );
		}
	}

	public function test_the_ceiling_refuses_to_be_talked_below_what_is_held(): void {
		$this->assertSame(
			5000,
			Availability::ceiling( -100, 5000 ),
			'A negative committed total is a broken ledger, and letting it lower the ceiling would let one claim take more than it asked for.'
		);
		$this->assertSame( 4000, Availability::ceiling( 4000, -1 ) );
	}

	public function test_every_verdict_has_an_advertiser_answer(): void {
		foreach ( Availability::all() as $verdict ) {
			$this->assertIsBool( Availability::bookable( $verdict ) );
		}

		$this->assertCount( 3, Availability::all() );
	}
}
