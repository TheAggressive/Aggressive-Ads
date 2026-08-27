<?php
/**
 * The assignment's own rules, asserted exhaustively.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Assignment_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No WordPress, so every pair can be stated rather than sampled.
 *
 * The window rule earns that treatment. It is the only thing standing between a
 * campaign sold for June and a creative that runs into July because somebody
 * typed a later end date — and a rule that is "probably right" about dates is a
 * rule that is wrong on the boundary nobody tried.
 */
final class AssignmentRulesTest extends TestCase {

	/**
	 * Every ordered pair of statuses, with whether it is a legal edge.
	 *
	 * All 36 are asserted rather than the handful that look interesting: the
	 * pairs nobody thinks to write are where a table quietly permits something
	 * it should not.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function transition_pairs(): array {
		$edges = Assignment_Rules::transitions();
		$cases = array();

		foreach ( Assignment_Rules::statuses() as $from ) {
			foreach ( Assignment_Rules::statuses() as $to ) {
				$legal = $from === $to || in_array( $to, $edges[ $from ], true );

				$cases[ "{$from} to {$to}" ] = array( $from, $to, $legal );
			}
		}

		return $cases;
	}

	#[DataProvider( 'transition_pairs' )]
	public function test_every_status_pair_is_decided( string $from, string $to, bool $legal ): void {
		$this->assertSame( $legal, Assignment_Rules::can_transition( $from, $to ) );
	}

	/** Terminal means terminal: nothing leaves completed or cancelled. */
	public function test_terminal_statuses_have_no_way_out(): void {
		foreach ( array( Assignment_Rules::COMPLETED, Assignment_Rules::CANCELLED ) as $terminal ) {
			foreach ( Assignment_Rules::statuses() as $to ) {
				if ( $to === $terminal ) {
					continue;
				}

				$this->assertFalse(
					Assignment_Rules::can_transition( $terminal, $to ),
					"{$terminal} should not become {$to}"
				);
			}
		}
	}

	/**
	 * Pause and resume are both legal, in both directions.
	 *
	 * The pair the transition table exists for. Asserted explicitly as well as
	 * through the exhaustive provider, because a table that lost this edge
	 * would still be a valid table and the provider would happily agree with
	 * the new, wrong answer.
	 */
	public function test_pause_and_resume_are_both_permitted(): void {
		$this->assertTrue( Assignment_Rules::can_transition( Assignment_Rules::LIVE, Assignment_Rules::PAUSED ) );
		$this->assertTrue( Assignment_Rules::can_transition( Assignment_Rules::PAUSED, Assignment_Rules::LIVE ) );
	}

	/** An unknown status is never a valid state to stay in. */
	public function test_an_unknown_status_cannot_transition_to_itself(): void {
		$this->assertFalse( Assignment_Rules::can_transition( 'made_up', 'made_up' ) );
	}

	/**
	 * Every campaign status maps to one this vocabulary recognises.
	 *
	 * The defect this covers shipped in the backfill: campaign statuses were
	 * written straight into the assignment column, producing values like
	 * `aggr_draft` that no transition accepts — so pause, resume and withdrawal
	 * were all refused and the row looked ordinary.
	 *
	 * @return array<string, array{string}>
	 */
	public static function campaign_statuses(): array {
		$cases = array();

		foreach ( \Aggressive\Ads\Core\Post_Statuses::all() as $status ) {
			$cases[ $status ] = array( $status );
		}

		$cases['an unknown status'] = array( 'something_else' );

		return $cases;
	}

	#[DataProvider( 'campaign_statuses' )]
	public function test_every_campaign_status_maps_to_a_real_assignment_status( string $status ): void {
		$mapped = Assignment_Rules::status_for_campaign( $status );

		$this->assertTrue(
			Assignment_Rules::is_status( $mapped ),
			"{$status} mapped to {$mapped}, which is not an assignment status"
		);
	}

	/** A live campaign's creative is live; a draft campaign's is a draft. */
	public function test_the_mapping_preserves_the_obvious_cases(): void {
		$this->assertSame(
			Assignment_Rules::LIVE,
			Assignment_Rules::status_for_campaign( \Aggressive\Ads\Core\Post_Statuses::LIVE )
		);
		$this->assertSame(
			Assignment_Rules::DRAFT,
			Assignment_Rules::status_for_campaign( \Aggressive\Ads\Core\Post_Statuses::DRAFT )
		);
	}

	/**
	 * Weights.
	 *
	 * @return array<string, array{int, bool}>
	 */
	public static function weights(): array {
		return array(
			'zero'           => array( 0, false ),
			'negative'       => array( -1, false ),
			'minimum'        => array( Assignment_Rules::MIN_WEIGHT, true ),
			'ordinary'       => array( 100, true ),
			'maximum'        => array( Assignment_Rules::MAX_WEIGHT, true ),
			'over the top'   => array( Assignment_Rules::MAX_WEIGHT + 1, false ),
			'absurdly large' => array( PHP_INT_MAX, false ),
		);
	}

	#[DataProvider( 'weights' )]
	public function test_weight_bounds( int $weight, bool $valid ): void {
		$this->assertSame( $valid, Assignment_Rules::is_weight( $weight ) );
	}

	/**
	 * Windows, against a parent running 100 to 200.
	 *
	 * @return array<string, array{int, int, bool}>
	 */
	public static function windows(): array {
		return array(
			'inherits both ends'          => array( 0, 0, true ),
			'inherits the end'            => array( 150, 0, true ),
			'inherits the start'          => array( 0, 150, true ),
			'exactly the parent'          => array( 100, 200, true ),
			'narrower on both ends'       => array( 120, 180, true ),
			'starts before the parent'    => array( 99, 180, false ),
			'ends after the parent'       => array( 120, 201, false ),
			'wider on both ends'          => array( 99, 201, false ),
			'end before start'            => array( 180, 120, false ),
			'end equal to start'          => array( 150, 150, false ),
			'starts when the parent ends' => array( 200, 0, false ),
			'ends when the parent starts' => array( 0, 100, false ),
			'negative start'              => array( -1, 150, false ),
			'negative end'                => array( 120, -1, false ),
		);
	}

	#[DataProvider( 'windows' )]
	public function test_window_must_fit_inside_the_parent( int $start, int $end, bool $fits ): void {
		$this->assertSame( $fits, Assignment_Rules::window_fits( $start, $end, 100, 200 ) );
	}

	/**
	 * An unbounded parent constrains only the ordering.
	 *
	 * A campaign with no end date is the ordinary case for an evergreen house
	 * slot, and an assignment under one must still be allowed a window.
	 */
	public function test_an_unbounded_parent_still_permits_a_window(): void {
		$this->assertTrue( Assignment_Rules::window_fits( 120, 180, 0, 0 ) );
		$this->assertTrue( Assignment_Rules::window_fits( 120, 0, 100, 0 ) );
		$this->assertFalse( Assignment_Rules::window_fits( 180, 120, 0, 0 ) );
	}

	/**
	 * Widening is refused, not clamped.
	 *
	 * Silently storing a different date than the one submitted is how an
	 * advertiser discovers months later that their creative never ran when they
	 * thought. The answer has to be "that is outside the campaign".
	 */
	public function test_a_wider_window_is_refused_rather_than_narrowed(): void {
		$this->assertFalse( Assignment_Rules::window_fits( 50, 250, 100, 200 ) );
	}
}
