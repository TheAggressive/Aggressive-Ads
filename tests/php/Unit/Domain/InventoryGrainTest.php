<?php
/**
 * The inventory grain, and the policy that bounds it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Refresh_Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every input here arrives from a browser or from post meta, so every case is
 * reachable without a bug anywhere.
 *
 * The ones that matter are the readings that would be invisible if wrong. A
 * refresh counted as supply does not error, does not log, and produces a
 * forecast built on a `setInterval` — the number looks fine and means nothing.
 */
final class InventoryGrainTest extends TestCase {

	/**
	 * Sequence zero is the page's own fill; anything after it is a refresh.
	 *
	 * @param mixed  $sequence What the client declared.
	 * @param string $expected The kind it describes.
	 */
	#[DataProvider( 'sequences' )]
	public function test_a_sequence_says_which_kind_of_opportunity_it_is( mixed $sequence, string $expected ): void {
		$this->assertSame( $expected, Opportunity::from_sequence( $sequence ) );
	}

	/**
	 * Declared sequences and the kind each describes.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public static function sequences(): array {
		return array(
			'the first fill'     => array( 0, Opportunity::PAGE ),
			'the first rotation' => array( 1, Opportunity::REFRESH ),
			'a later rotation'   => array( 99, Opportunity::REFRESH ),
			'a numeric string'   => array( '3', Opportunity::REFRESH ),
			'zero as a string'   => array( '0', Opportunity::PAGE ),

			/*
			 * Everything unreadable is a page opportunity, for rollout rather
			 * than for caution. Every fill from a page cached before this
			 * shipped arrives with no sequence, and reading those as refreshes
			 * would collapse measured supply to near zero while the cache
			 * drains. The cost is real and runs the other way: over-counted
			 * supply understates utilisation, which is the oversell direction,
			 * and P16's conservative fallback is what has to absorb it.
			 */
			'absent'             => array( null, Opportunity::PAGE ),
			'an empty string'    => array( '', Opportunity::PAGE ),
			'a word'             => array( 'first', Opportunity::PAGE ),
			'an array'           => array( array( 2 ), Opportunity::PAGE ),
			'a negative'         => array( -5, Opportunity::PAGE ),
			'a boolean'          => array( true, Opportunity::PAGE ),
		);
	}

	/** The stored vocabulary is closed, and the column has to hold it. */
	public function test_the_stored_vocabulary_fits_the_column(): void {
		$this->assertSame( array( 'page', 'refresh' ), Opportunity::all() );

		foreach ( Opportunity::all() as $kind ) {
			$this->assertTrue( Opportunity::is_valid( $kind ) );
			$this->assertLessThanOrEqual(
				Opportunity::MAX_LENGTH,
				strlen( $kind ),
				'A kind is longer than the column that stores it, which truncates rather than errors.'
			);
		}

		$this->assertFalse( Opportunity::is_valid( 'rotation' ) );
		$this->assertFalse( Opportunity::is_valid( '' ) );
	}

	/**
	 * **An unconfigured placement does not refresh.**
	 *
	 * Defaulting refresh on would multiply every existing publisher's supply at
	 * upgrade without anybody asking for it, and the symptom — inventory that
	 * looks larger than it is — is one nobody reads as a bug.
	 */
	public function test_a_placement_nobody_configured_does_not_refresh(): void {
		$policy = Refresh_Policy::defaults();

		$this->assertFalse( $policy->enabled );
		$this->assertFalse( $policy->permits_sequence( 1 ) );

		// And its first fill is still served.
		$this->assertTrue( $policy->permits_sequence( 0 ) );
	}

	/**
	 * **The policy bounds the block's request, never the reverse.**
	 *
	 * This is the invariant the whole phase rests on: an editor laying out a
	 * page must not be the person who decides how much inventory exists.
	 */
	public function test_the_policy_bounds_the_block_rather_than_the_other_way_round(): void {
		$policy = Refresh_Policy::from_stored( true, 30, 6 );

		// A block asking to rotate faster than permitted gets the placement's number.
		$this->assertSame( 30, $policy->interval_for( 1 ) );
		$this->assertSame( 30, $policy->interval_for( 29 ) );

		// One asking to go slower is honoured: fewer impressions is the safer setting.
		$this->assertSame( 45, $policy->interval_for( 45 ) );
		$this->assertSame( 30, $policy->interval_for( 30 ) );
	}

	/**
	 * A forbidden placement refuses every refresh, whatever the block asked.
	 *
	 * @return void
	 */
	public function test_a_forbidden_placement_refuses_every_refresh(): void {
		$policy = Refresh_Policy::from_stored( false, 5, 100 );

		$this->assertTrue( $policy->permits_sequence( 0 ), 'The first fill must still be served.' );
		$this->assertFalse( $policy->permits_sequence( 1 ) );
		$this->assertFalse( $policy->permits_sequence( 50 ) );
	}

	/**
	 * The per-view cap is a bound the server can apply without the client.
	 *
	 * A browser claiming refresh four hundred on a placement capped at six is
	 * discardable, which is what turns "we trust the count" into "we trust it
	 * inside a bound the publisher set".
	 *
	 * @return void
	 */
	public function test_a_sequence_past_the_cap_is_refused(): void {
		$policy = Refresh_Policy::from_stored( true, 30, 6 );

		$this->assertTrue( $policy->permits_sequence( 6 ), 'The cap itself is permitted.' );
		$this->assertFalse( $policy->permits_sequence( 7 ) );
		$this->assertFalse( $policy->permits_sequence( 400 ) );
	}

	/**
	 * A cap of zero refuses refreshes without discarding the rest of the policy.
	 *
	 * @return void
	 */
	public function test_a_cap_of_zero_is_not_the_same_as_refresh_being_off(): void {
		$policy = Refresh_Policy::from_stored( true, 45, 0 );

		$this->assertTrue( $policy->enabled );
		$this->assertSame( 45, $policy->interval_seconds );
		$this->assertFalse( $policy->permits_sequence( 1 ) );
	}

	/**
	 * A publisher may be stricter than the floor and may not be looser.
	 *
	 * @param mixed $stored   What the placement recorded.
	 * @param int   $expected The interval it resolves to.
	 */
	#[DataProvider( 'intervals' )]
	public function test_an_interval_is_floored_but_never_capped( mixed $stored, int $expected ): void {
		$this->assertSame( $expected, Refresh_Policy::from_stored( true, $stored, 6 )->interval_seconds );
	}

	/**
	 * Stored intervals and what they resolve to.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function intervals(): array {
		return array(
			'zero'             => array( 0, Refresh_Policy::MIN_INTERVAL_SECONDS ),
			'negative'         => array( -30, Refresh_Policy::MIN_INTERVAL_SECONDS ),
			'the floor'        => array( 1, 1 ),
			'a normal value'   => array( 30, 30 ),
			'an hour'          => array( 3600, 3600 ),
			'a numeric string' => array( '45', 45 ),
			'not a number'     => array( 'often', Refresh_Policy::DEFAULT_INTERVAL_SECONDS ),
			'absent'           => array( null, Refresh_Policy::DEFAULT_INTERVAL_SECONDS ),
			'an array'         => array( array( 5 ), Refresh_Policy::DEFAULT_INTERVAL_SECONDS ),
		);
	}

	/**
	 * Post meta arrives as strings, and "0" is the one PHP gets wrong.
	 *
	 * @param mixed $stored  What the placement recorded.
	 * @param bool  $enabled Whether refresh is permitted.
	 */
	#[DataProvider( 'switches' )]
	public function test_a_stored_switch_means_what_was_saved( mixed $stored, bool $enabled ): void {
		$this->assertSame( $enabled, Refresh_Policy::from_stored( $stored, 30, 6 )->enabled );
	}

	/**
	 * Stored switches and their readings.
	 *
	 * @return array<string, array{mixed, bool}>
	 */
	public static function switches(): array {
		return array(
			'the string zero'  => array( '0', false ),
			'the string false' => array( 'false', false ),
			'a real false'     => array( false, false ),
			'absent'           => array( null, false ),
			'an empty string'  => array( '', false ),
			'the string one'   => array( '1', true ),
			'a real true'      => array( true, true ),
			'the number one'   => array( 1, true ),
		);
	}

	/**
	 * The context states every setting rather than omitting defaults.
	 *
	 * @return void
	 */
	public function test_the_context_states_every_setting(): void {
		$this->assertSame(
			array(
				'refreshEnabled'    => false,
				'refreshSeconds'    => Refresh_Policy::DEFAULT_INTERVAL_SECONDS,
				'refreshMaxPerView' => Refresh_Policy::DEFAULT_MAX_PER_VIEW,
			),
			Refresh_Policy::defaults()->to_context()
		);
	}

	/**
	 * The shipped defaults are stricter than the client's own hard stops.
	 *
	 * The client caps rotation at 100 per view and floors the interval at one
	 * second. A publisher default that merely restated those would be no policy
	 * at all — it has to be a number somebody chose.
	 *
	 * @return void
	 */
	public function test_the_defaults_are_stricter_than_the_client_hard_stops(): void {
		$this->assertGreaterThan( Refresh_Policy::MIN_INTERVAL_SECONDS, Refresh_Policy::DEFAULT_INTERVAL_SECONDS );
		$this->assertLessThan( 100, Refresh_Policy::DEFAULT_MAX_PER_VIEW );
	}
}
