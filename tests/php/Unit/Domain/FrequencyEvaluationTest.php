<?php
/**
 * Frequency capping unit tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Array_Frequency_Store;
use Aggressive\Ads\Domain\Decision_Candidate;
use Aggressive\Ads\Domain\Decision_Context;
use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Domain\Decision_Request;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Domain\Frequency_Rules;
use Aggressive\Ads\Domain\Frequency_Stage;
use PHPUnit\Framework\TestCase;

/**
 * Proves frequency capping across campaign, line-item, and creative levels and session, hourly, daily, and custom windows.
 */
final class FrequencyEvaluationTest extends TestCase {

	public function test_candidate_without_frequency_rules_passes(): void {
		$store   = new Array_Frequency_Store();
		$context = new Decision_Context( 1, 1_700_000_000, array( 'visitor_id' => 'user_123' ) );
		$row     = array( 'id' => 1 );

		$this->assertNull( Frequency_Rules::evaluate_candidate( $row, $context, $store ) );
	}

	public function test_missing_visitor_id_fails_open_without_capping(): void {
		$store   = new Array_Frequency_Store( array( 'line_item:1:day:' . hash( 'sha256', '' ) => 10 ) );
		$context = new Decision_Context( 1, 1_700_000_000, array() ); // No visitor_id or session_id.
		$row     = array(
			'id'              => 1,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 3,
				'window'          => 'day',
				'level'           => 'line_item',
			),
		);

		// Without visitor identifier, it fails open to prevent unintentional dropping of inventory.
		$this->assertNull( Frequency_Rules::evaluate_candidate( $row, $context, $store ) );
	}

	public function test_line_item_level_daily_frequency_cap_enforced(): void {
		$visitor = 'visitor_abc';
		$key     = Frequency_Rules::build_key( 'line_item', 42, $visitor, 'day', 1_700_000_000 );

		$store   = new Array_Frequency_Store( array( $key => 3 ) );
		$context = new Decision_Context( 1, 1_700_000_000, array( 'visitor_id' => $visitor ) );

		$row = array(
			'id'              => 42,
			'line_item_id'    => 42,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 3,
				'window'          => 'day',
				'level'           => 'line_item',
			),
		);

		// 3 impressions served >= max 3 -> Capped.
		$this->assertSame(
			Exclusion_Reason::FREQUENCY_CAPPED,
			Frequency_Rules::evaluate_candidate( $row, $context, $store )
		);

		// Under cap (2 impressions) -> Passes.
		$under_store = new Array_Frequency_Store( array( $key => 2 ) );
		$this->assertNull( Frequency_Rules::evaluate_candidate( $row, $context, $under_store ) );
	}

	public function test_campaign_level_hourly_frequency_cap_enforced(): void {
		$visitor = 'visitor_xyz';
		$key     = Frequency_Rules::build_key( 'campaign', 99, $visitor, 'hour', 1_700_000_000 );

		$store   = new Array_Frequency_Store( array( $key => 5 ) );
		$context = new Decision_Context( 1, 1_700_000_000, array( 'visitor_id' => $visitor ) );

		$row = array(
			'id'              => 1,
			'campaign_id'     => 99,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 5,
				'window'          => 'hour',
				'level'           => 'campaign',
			),
		);

		$this->assertSame(
			Exclusion_Reason::FREQUENCY_CAPPED,
			Frequency_Rules::evaluate_candidate( $row, $context, $store )
		);
	}

	public function test_creative_level_custom_window_frequency_cap_enforced(): void {
		$visitor = 'visitor_777';
		$key     = Frequency_Rules::build_key( 'creative', 505, $visitor, 'custom', 1_700_000_000, array( 'window_seconds' => 1800 ) );

		$store   = new Array_Frequency_Store( array( $key => 1 ) );
		$context = new Decision_Context( 1, 1_700_000_000, array( 'visitor_id' => $visitor ) );

		$row = array(
			'id'              => 1,
			'revision_id'     => 505,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 1,
				'window'          => 'custom',
				'window_seconds'  => 1800,
				'level'           => 'creative',
			),
		);

		$this->assertSame(
			Exclusion_Reason::FREQUENCY_CAPPED,
			Frequency_Rules::evaluate_candidate( $row, $context, $store )
		);
	}

	public function test_session_window_frequency_cap_enforced(): void {
		$session_id = 'sess_qwerty';
		$key        = Frequency_Rules::build_key( 'line_item', 10, $session_id, 'session' );

		$store   = new Array_Frequency_Store( array( $key => 2 ) );
		$context = new Decision_Context( 1, 1_700_000_000, array( 'session_id' => $session_id ) );

		$row = array(
			'id'              => 10,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 2,
				'window'          => 'session',
				'level'           => 'line_item',
			),
		);

		$this->assertSame(
			Exclusion_Reason::FREQUENCY_CAPPED,
			Frequency_Rules::evaluate_candidate( $row, $context, $store )
		);
	}

	public function test_array_frequency_store_increment_and_reset(): void {
		$store = new Array_Frequency_Store();
		$this->assertSame( 0, $store->get_count( 'test_key' ) );

		$new_count = $store->increment( 'test_key', 3600 );
		$this->assertSame( 1, $new_count );
		$this->assertSame( 1, $store->get_count( 'test_key' ) );

		$store->increment( 'test_key', 3600 );
		$this->assertSame( 2, $store->get_count( 'test_key' ) );

		$store->reset( 'test_key' );
		$this->assertSame( 0, $store->get_count( 'test_key' ) );
	}

	public function test_frequency_stage_evaluates_and_isolates_candidates(): void {
		$visitor = 'visitor_1';
		$key     = Frequency_Rules::build_key( 'line_item', 2, $visitor, 'day', 1_700_000_000 );
		$store   = new Array_Frequency_Store( array( $key => 5 ) );

		$stage   = new Frequency_Stage( $store );
		$context = new Decision_Context( 1, 1_700_000_000, array( 'visitor_id' => $visitor ) );

		$candidates = array(
			new Decision_Candidate(
				array(
					'id'              => 1,
					'frequency_rules' => array(
						'enabled'         => true,
						'max_impressions' => 10,
						'window'          => 'day',
					),
				)
			),
			new Decision_Candidate(
				array(
					'id'              => 2,
					'frequency_rules' => array(
						'enabled'         => true,
						'max_impressions' => 5,
						'window'          => 'day',
					),
				)
			),
		);

		$evaluated = $stage->evaluate( $candidates, $context );

		$this->assertCount( 2, $evaluated );
		$this->assertTrue( $evaluated[0]->is_eligible() );
		$this->assertFalse( $evaluated[1]->is_eligible() );
		$this->assertSame( 'frequency', $evaluated[1]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::FREQUENCY_CAPPED, $evaluated[1]->exclusion_reason );
	}

	public function test_pipeline_integration_with_frequency_stage(): void {
		$visitor = 'visitor_target';
		$key     = Frequency_Rules::build_key( 'line_item', 1, $visitor, 'day', 1_700_000_000 );
		$store   = new Array_Frequency_Store( array( $key => 4 ) );

		$pipeline = Decision_Pipeline::standard( $store );

		$rows = array(
			array(
				'id'              => 1,
				'weight'          => 100,
				'attachment_id'   => 501,
				'click_url'       => 'https://example.com/a',
				'frequency_rules' => array(
					'enabled'         => true,
					'max_impressions' => 4,
					'window'          => 'day',
				),
			),
		);

		$request  = new Decision_Request( 1, 1_700_000_000, 100, array( 'visitor_id' => $visitor ) );
		$decision = $pipeline->decide( $rows, $request );

		$this->assertFalse( $decision['result']->has_winner() );

		/*
		 * The slot's reason is the candidate's, because every candidate lost
		 * for it. A generic no-fill here would tell a publisher nothing was
		 * assigned when something was, and send them looking at a campaign that
		 * is working exactly as configured.
		 */
		$this->assertSame( Exclusion_Reason::FREQUENCY_CAPPED, $decision['result']->reason );

		$this->assertNotEmpty( $decision['trace']->entries );
		$this->assertSame( 'frequency', $decision['trace']->entries[0]['stage'] );
		$this->assertSame( Exclusion_Reason::FREQUENCY_CAPPED, $decision['trace']->entries[0]['reason'] );
	}

	/**
	 * Deliveries counted through the production writer reach the cap.
	 *
	 * The test that was missing, and the reason a shipped, `[x]`-complete
	 * frequency stage capped nobody: every other test in this file arranges its
	 * count by hand, so all of them passed while no production code called
	 * `increment()` at all. Reading and writing have to meet somewhere.
	 */
	public function test_recorded_deliveries_are_what_the_cap_counts(): void {
		$now     = 1_700_000_000;
		$store   = new Array_Frequency_Store();
		$context = new Decision_Context( 1, $now, array( 'visitor_id' => 'visitor_roundtrip' ) );
		$row     = array(
			'id'              => 7,
			'line_item_id'    => 7,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 2,
				'window'          => 'day',
				'level'           => 'line_item',
			),
		);

		$this->assertNull( Frequency_Rules::evaluate_candidate( $row, $context, $store ) );

		$this->assertTrue( Frequency_Rules::record_delivery( $row, $context, $store, $now ) );
		$this->assertNull(
			Frequency_Rules::evaluate_candidate( $row, $context, $store ),
			'One delivery against a cap of two should still serve.'
		);

		Frequency_Rules::record_delivery( $row, $context, $store, $now );
		$this->assertSame(
			Exclusion_Reason::FREQUENCY_CAPPED,
			Frequency_Rules::evaluate_candidate( $row, $context, $store ),
			'A second delivery reaches the cap and must exclude the candidate.'
		);
	}

	/**
	 * An hourly window ends on the hour rather than sliding.
	 *
	 * The key used to carry no time bucket, so the window was enforced only by
	 * the store's TTL — which every write refreshed. A visitor who saw an ad at
	 * least once an hour never expired, and an hourly cap of two behaved as a
	 * lifetime cap of two.
	 */
	public function test_an_hourly_cap_resets_in_the_next_hour(): void {
		$now     = 1_700_000_000;
		$store   = new Array_Frequency_Store();
		$visitor = array( 'visitor_id' => 'visitor_hourly' );
		$row     = array(
			'id'              => 3,
			'campaign_id'     => 3,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 1,
				'window'          => 'hour',
				'level'           => 'campaign',
			),
		);

		$first = new Decision_Context( 1, $now, $visitor );
		Frequency_Rules::record_delivery( $row, $first, $store, $now );

		$this->assertSame(
			Exclusion_Reason::FREQUENCY_CAPPED,
			Frequency_Rules::evaluate_candidate( $row, $first, $store ),
			'The cap must hold inside the hour it was reached in.'
		);

		$later = $now + Frequency_Rules::SECONDS_HOUR;

		$this->assertNull(
			Frequency_Rules::evaluate_candidate( $row, new Decision_Context( 1, $later, $visitor ), $store ),
			'The next hour is a new window and must serve again.'
		);
	}

	/**
	 * Continuous traffic does not keep a window alive.
	 *
	 * The negative half of the test above. A delivery late in the window must
	 * not push the boundary out, which is exactly what refreshing a TTL did.
	 */
	public function test_a_late_delivery_does_not_extend_the_window(): void {
		$now     = 1_700_000_000;
		$store   = new Array_Frequency_Store();
		$visitor = array( 'visitor_id' => 'visitor_late' );
		$row     = array(
			'id'              => 4,
			'line_item_id'    => 4,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 1,
				'window'          => 'hour',
				'level'           => 'line_item',
			),
		);

		// Recorded one second before the hour rolls over.
		$boundary = ( intdiv( $now, Frequency_Rules::SECONDS_HOUR ) + 1 ) * Frequency_Rules::SECONDS_HOUR;
		$late     = $boundary - 1;

		Frequency_Rules::record_delivery( $row, new Decision_Context( 1, $late, $visitor ), $store, $late );

		$this->assertNull(
			Frequency_Rules::evaluate_candidate( $row, new Decision_Context( 1, $boundary, $visitor ), $store ),
			'A delivery one second before the boundary extended the window past it.'
		);
	}

	/**
	 * A visitor the engine cannot identify is never counted.
	 *
	 * Matches the read side, which fails open for the same reason: counting
	 * against an empty identifier would pool every anonymous visitor into one
	 * bucket and cap the whole site after `max_impressions` deliveries.
	 */
	public function test_an_unidentified_visitor_is_not_counted(): void {
		$now   = 1_700_000_000;
		$store = new Array_Frequency_Store();
		$row   = array(
			'id'              => 5,
			'line_item_id'    => 5,
			'frequency_rules' => array(
				'enabled'         => true,
				'max_impressions' => 1,
				'window'          => 'day',
				'level'           => 'line_item',
			),
		);

		$this->assertFalse(
			Frequency_Rules::record_delivery( $row, new Decision_Context( 1, $now, array() ), $store, $now )
		);
	}

	/** A candidate with capping switched off is not counted either. */
	public function test_a_candidate_without_capping_is_not_counted(): void {
		$now     = 1_700_000_000;
		$store   = new Array_Frequency_Store();
		$context = new Decision_Context( 1, $now, array( 'visitor_id' => 'visitor_nocap' ) );

		$this->assertFalse(
			Frequency_Rules::record_delivery( array( 'id' => 6 ), $context, $store, $now )
		);
		$this->assertFalse(
			Frequency_Rules::record_delivery(
				array(
					'id'              => 6,
					'frequency_rules' => array(
						'enabled'         => false,
						'max_impressions' => 3,
					),
				),
				$context,
				$store,
				$now
			)
		);
	}
}
