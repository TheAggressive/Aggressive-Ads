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
		$key     = Frequency_Rules::build_key( 'line_item', 42, $visitor, 'day' );

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
		$key     = Frequency_Rules::build_key( 'campaign', 99, $visitor, 'hour' );

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
		$key     = Frequency_Rules::build_key( 'creative', 505, $visitor, 'custom' );

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
		$key     = Frequency_Rules::build_key( 'line_item', 2, $visitor, 'day' );
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
		$key     = Frequency_Rules::build_key( 'line_item', 1, $visitor, 'day' );
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
		$this->assertSame( Exclusion_Reason::NO_FILL, $decision['result']->reason );

		$this->assertNotEmpty( $decision['trace']->entries );
		$this->assertSame( 'frequency', $decision['trace']->entries[0]['stage'] );
		$this->assertSame( Exclusion_Reason::FREQUENCY_CAPPED, $decision['trace']->entries[0]['reason'] );
	}
}
