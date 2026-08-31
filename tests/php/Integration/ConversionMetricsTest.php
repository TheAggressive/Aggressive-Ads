<?php
/**
 * What the refusal counter will and will not store.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Conversion_Attribution;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Workflow\Conversion_Metrics;
use WP_UnitTestCase;

/**
 * The counter's own rules. That a refusal actually reaches it is asserted in
 * `ConversionRecorderTest`, through the production recorder — a counter tested
 * only by calling it directly is arithmetic, which is the shape frequency
 * capping shipped in and capped nobody.
 *
 * What is here is everything the recorder cannot show: that the option is
 * bounded, that "since" means what it says, and that a poisoned or outdated
 * stored value cannot reach a screen.
 */
final class ConversionMetricsTest extends WP_UnitTestCase {

	/**
	 * Counter under test.
	 *
	 * @var Conversion_Metrics
	 */
	private Conversion_Metrics $metrics;

	public function set_up(): void {
		parent::set_up();

		$this->metrics = Plugin::instance()->container()->get( Conversion_Metrics::class );
		$this->metrics->reset();
	}

	/**
	 * Refuses one reason and ends the request, as production does.
	 *
	 * @param string $reason Reason code.
	 * @param int    $times  How many refusals.
	 */
	private function refuse( string $reason, int $times = 1 ): void {
		foreach ( range( 1, $times ) as $ignored ) {
			$this->metrics->record_refusal( $reason );
		}

		$this->metrics->flush();
	}

	/**
	 * Ends the request the way production does, minus core's output flushing.
	 *
	 * `wp_ob_end_flush_all` is hooked to `shutdown` by core and closes the
	 * output buffers PHPUnit is holding, which makes every test that fires this
	 * action risky. Dropping that one handler leaves the hook this service
	 * actually listens to intact, which is the thing being proven.
	 */
	private function end_request(): void {
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		do_action( 'shutdown' );
	}

	/**
	 * **The hook is what makes a refusal durable, so the hook is asserted.**
	 *
	 * Everything else in this file calls `flush()` directly, which would go on
	 * passing if `Plugin::service_init_order()` lost this service and no request
	 * ever wrote anything again. This is the test that would fail.
	 */
	public function test_the_shutdown_hook_is_what_writes_the_count(): void {
		$this->metrics->record_refusal( Conversion_Attribution::NO_INTERACTION );

		$this->assertSame( array(), $this->metrics->refusal_counts(), 'A buffered count must not be readable as a durable one.' );

		$this->end_request();

		$this->assertSame(
			array( Conversion_Attribution::NO_INTERACTION => 1 ),
			$this->metrics->refusal_counts(),
			'Nothing wrote the count, so this service is not initialised.'
		);
	}

	/**
	 * **The write does not get more expensive the more a request refused.**
	 *
	 * Asserted as one cost against another rather than against a number: what
	 * matters is that the buffer collapses a request's refusals into a single
	 * write, and a literal here would break the day WordPress changes how many
	 * queries `update_option()` takes, which would say nothing about this class.
	 */
	public function test_the_write_cost_does_not_grow_with_the_refusals(): void {
		global $wpdb;

		// The row has to exist first, or the two measurements below differ by
		// an INSERT rather than by what is being measured.
		$this->refuse( Conversion_Attribution::NO_DEFINITION );

		$before = $wpdb->num_queries;
		$this->refuse( Conversion_Attribution::NO_DEFINITION );
		$one = $wpdb->num_queries - $before;

		$before = $wpdb->num_queries;
		$this->refuse( Conversion_Attribution::NO_DEFINITION, 5 );
		$five = $wpdb->num_queries - $before;

		$this->assertGreaterThan( 0, $one, 'Nothing was written, so this measures nothing.' );
		$this->assertSame( $one, $five, 'The write cost grows with the number of refusals.' );
		$this->assertSame( array( Conversion_Attribution::NO_DEFINITION => 7 ), $this->metrics->refusal_counts() );
	}

	/** A request that refused nothing writes nothing at all. */
	public function test_a_quiet_request_writes_nothing(): void {
		global $wpdb;

		$before = $wpdb->num_queries;

		$this->metrics->flush();

		$this->assertSame( $before, $wpdb->num_queries, 'Flushing an empty buffer touched the database.' );
	}

	/** A counted refusal is readable, and counted once per call. */
	public function test_a_refusal_is_counted(): void {
		$this->refuse( Conversion_Attribution::OUT_OF_WINDOW, 2 );

		$this->assertSame(
			array( Conversion_Attribution::OUT_OF_WINDOW => 2 ),
			$this->metrics->refusal_counts()
		);
	}

	/**
	 * **`ACCEPTED` is not a refusal and must never be stored.**
	 *
	 * The ledger is the exact record of what was accepted. An approximate
	 * second number beside it is the beginning of two answers to one question,
	 * and this one loses updates under concurrency by design.
	 */
	public function test_an_acceptance_is_not_counted(): void {
		$this->refuse( Conversion_Attribution::ACCEPTED );

		$this->assertSame( array(), $this->metrics->refusal_counts() );
		$this->assertSame( 0, $this->metrics->counting_since() );
	}

	/**
	 * **A code the domain does not define cannot enter the option.**
	 *
	 * This is what keeps the row bounded. Without it a caller inventing a
	 * reason — a typo, a code from a later phase — grows a `wp_options` row
	 * nothing ever prunes.
	 */
	public function test_an_unknown_reason_is_refused(): void {
		$this->refuse( 'not_a_reason' );
		$this->refuse( '' );

		$this->assertSame( array(), $this->metrics->refusal_counts() );
	}

	/**
	 * Every reason the domain does define is storable.
	 *
	 * Asserted as a set rather than one example, so a reason added to
	 * `Conversion_Attribution` and forgotten here fails rather than silently
	 * going uncounted.
	 */
	public function test_every_domain_refusal_reason_is_storable(): void {
		$expected = array();

		foreach ( Conversion_Attribution::reasons() as $reason ) {
			if ( Conversion_Attribution::ACCEPTED === $reason ) {
				continue;
			}

			$this->refuse( $reason );
			$expected[ $reason ] = 1;
		}

		$counts = $this->metrics->refusal_counts();

		ksort( $counts );
		ksort( $expected );

		$this->assertSame( $expected, $counts );
		$this->assertGreaterThan( 5, count( $counts ), 'The domain lost most of its refusal reasons.' );
	}

	/**
	 * **"Since" is stamped on the first refusal and never moves after.**
	 *
	 * A total with no beginning reads as current however old it is. A stamp
	 * that moved with every refusal would answer "since a moment ago" for a
	 * count accumulated over months, which is worse than no stamp at all.
	 */
	public function test_counting_since_is_stamped_once(): void {
		$this->assertSame( 0, $this->metrics->counting_since(), 'Nothing has been refused, so counting has not begun.' );

		$this->refuse( Conversion_Attribution::NO_INTERACTION );

		$first = $this->metrics->counting_since();

		$this->assertGreaterThan( 0, $first );

		$this->refuse( Conversion_Attribution::NO_INTERACTION );

		$this->assertSame( $first, $this->metrics->counting_since() );
	}

	/** The reason to look at first is the one that comes first. */
	public function test_counts_are_ordered_by_size(): void {
		$this->refuse( Conversion_Attribution::OUT_OF_WINDOW );
		$this->refuse( Conversion_Attribution::NO_INTERACTION, 3 );

		$this->assertSame(
			array( Conversion_Attribution::NO_INTERACTION, Conversion_Attribution::OUT_OF_WINDOW ),
			array_keys( $this->metrics->refusal_counts() )
		);
	}

	/**
	 * **A stored value nothing here wrote is dropped on read.**
	 *
	 * An option is a public row: another plugin, a half-finished migration or a
	 * reason removed from the domain can all leave something in it. A reader
	 * that trusted it would print a code Site Health has no label for.
	 */
	public function test_a_poisoned_option_cannot_reach_a_reader(): void {
		update_option(
			Conversion_Metrics::OPTION_REFUSALS,
			array(
				'since'  => -5,
				'counts' => array(
					Conversion_Attribution::OUT_OF_WINDOW => 4,
					'reason_from_another_phase'           => 900,
					Conversion_Attribution::ACCEPTED      => 12,
					'negative'                            => -3,
				),
			),
			false
		);

		$this->assertSame(
			array( Conversion_Attribution::OUT_OF_WINDOW => 4 ),
			$this->metrics->refusal_counts()
		);
		$this->assertSame( 0, $this->metrics->counting_since(), 'A negative stamp is not a date.' );
	}

	/** A total that cannot return to zero stops being read. */
	public function test_reset_forgets_the_counts_and_the_stamp(): void {
		$this->refuse( Conversion_Attribution::NO_INTERACTION );
		$this->metrics->reset();

		$this->assertSame( array(), $this->metrics->refusal_counts() );
		$this->assertSame( 0, $this->metrics->counting_since() );
	}
}
