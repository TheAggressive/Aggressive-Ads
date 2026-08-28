<?php
/**
 * The frequency store that production actually uses.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Frequency_Rules;
use Aggressive\Ads\Workflow\Transient_Frequency_Store;
use WP_UnitTestCase;

/**
 * Every frequency test lived in the unit suite against the in-memory store, so
 * the class that runs on a real site was never executed by anything. That is
 * the same gap that let the missing writer ship: a store nothing exercises
 * cannot report that it does not work.
 */
final class FrequencyStoreTest extends WP_UnitTestCase {

	/**
	 * Store under test.
	 *
	 * @var Transient_Frequency_Store
	 */
	private Transient_Frequency_Store $store;

	/**
	 * A key unique to each test, so one test cannot inherit another's count.
	 *
	 * @var string
	 */
	private string $key;

	public function set_up(): void {
		parent::set_up();

		$this->store = new Transient_Frequency_Store();
		$this->key   = 'line_item:1:day:0:' . wp_generate_password( 12, false );
	}

	public function tear_down(): void {
		$this->store->reset( $this->key );

		parent::tear_down();
	}

	/** An unseen visitor starts at zero rather than at a false value. */
	public function test_an_unknown_key_counts_zero(): void {
		$this->assertSame( 0, $this->store->get_count( $this->key ) );
	}

	/**
	 * Counting up is visible to the next read.
	 *
	 * The round trip the decision path depends on: the read half was correct
	 * all along, and this is the half that had no caller.
	 */
	public function test_increments_are_readable(): void {
		$this->assertSame( 1, $this->store->increment( $this->key, HOUR_IN_SECONDS ) );
		$this->assertSame( 1, $this->store->get_count( $this->key ) );

		$this->assertSame( 2, $this->store->increment( $this->key, HOUR_IN_SECONDS ) );
		$this->assertSame( 2, $this->store->get_count( $this->key ) );
	}

	/** Two visitors are counted apart, or one caps the other. */
	public function test_keys_do_not_share_a_counter(): void {
		$other = $this->key . '_other';

		$this->store->increment( $this->key, HOUR_IN_SECONDS );
		$this->store->increment( $this->key, HOUR_IN_SECONDS );

		$this->assertSame( 0, $this->store->get_count( $other ) );

		$this->store->reset( $other );
	}

	/** Reset clears the count rather than leaving a stale one behind. */
	public function test_reset_clears_the_count(): void {
		$this->store->increment( $this->key, HOUR_IN_SECONDS );
		$this->store->reset( $this->key );

		$this->assertSame( 0, $this->store->get_count( $this->key ) );
	}

	/**
	 * A negative lifetime still counts.
	 *
	 * `set_transient()` given a negative expiry stores a timeout already in the
	 * past, so the next read misses and the cap silently stops working. The
	 * store floors the lifetime at one second.
	 *
	 * A zero is deliberately not the case under test: WordPress reads 0 as "no
	 * expiry", so it round-trips with or without the floor. Asserting on it
	 * proved nothing — a sabotage run removing the floor left it green.
	 */
	public function test_a_negative_lifetime_still_counts(): void {
		$this->assertSame( 1, $this->store->increment( $this->key, -60 ) );
		$this->assertSame(
			1,
			$this->store->get_count( $this->key ),
			'A negative window discarded the count instead of flooring it.'
		);
	}

	/**
	 * The store reaches a cap through the rules that will use it.
	 *
	 * Asserted against `Frequency_Rules` rather than raw integers, because the
	 * defect being fixed was the two halves never meeting.
	 */
	public function test_the_real_store_reaches_a_real_cap(): void {
		$now     = 1_700_000_000;
		$visitor = 'store_visitor_' . wp_generate_password( 8, false );
		$row     = array(
			'id'              => 11,
			'line_item_id'    => 11,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 2,
				'window'          => 'day',
				'level'           => 'line_item',
			),
		);
		$context = new \Aggressive\Ads\Domain\Decision_Context( 1, $now, array( 'visitor_id' => $visitor ) );

		$this->assertNull( Frequency_Rules::evaluate_candidate( $row, $context, $this->store ) );

		Frequency_Rules::record_delivery( $row, $context, $this->store, $now );
		Frequency_Rules::record_delivery( $row, $context, $this->store, $now );

		$this->assertSame(
			\Aggressive\Ads\Domain\Exclusion_Reason::FREQUENCY_CAPPED,
			Frequency_Rules::evaluate_candidate( $row, $context, $this->store ),
			'The production store did not reach a cap the rules should have enforced.'
		);

		$this->store->reset(
			Frequency_Rules::build_key( 'line_item', 11, $visitor, 'day', $now )
		);
	}
}
