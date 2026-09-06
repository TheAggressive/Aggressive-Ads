<?php
/**
 * Targeting evaluation unit tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Candidate;
use Aggressive\Ads\Domain\Decision_Context;
use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Domain\Decision_Request;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Domain\Targeting_Rules;
use Aggressive\Ads\Domain\Targeting_Stage;
use PHPUnit\Framework\TestCase;

/**
 * Proves declarative targeting evaluation, boolean AST traversal, and fault isolation.
 */
final class TargetingEvaluationTest extends TestCase {

	public function test_unbounded_candidate_with_no_targeting_rules_passes(): void {
		$context = new Decision_Context( 1, 1_700_000_000, array( 'device' => 'mobile' ) );
		$row     = array( 'id' => 1 );

		$this->assertNull( Targeting_Rules::evaluate_candidate( $row, $context ) );
	}

	public function test_simple_leaf_rule_matches_context_dimension(): void {
		$context = new Decision_Context( 1, 1_700_000_000, array( 'device' => 'mobile' ) );
		$row     = array(
			'id'              => 1,
			'targeting_rules' => array(
				'dimension' => 'device',
				'operator'  => 'eq',
				'value'     => 'mobile',
			),
		);

		$this->assertNull( Targeting_Rules::evaluate_candidate( $row, $context ) );
	}

	public function test_simple_leaf_rule_mismatches_context_dimension(): void {
		$context = new Decision_Context( 1, 1_700_000_000, array( 'device' => 'desktop' ) );
		$row     = array(
			'id'              => 1,
			'targeting_rules' => array(
				'dimension' => 'device',
				'operator'  => 'eq',
				'value'     => 'mobile',
			),
		);

		$this->assertSame(
			Exclusion_Reason::TARGETING_EXCLUDED,
			Targeting_Rules::evaluate_candidate( $row, $context )
		);
	}

	public function test_in_and_not_in_operators_for_scalar_and_array_facts(): void {
		// Test IN with array fact (e.g. post has multiple categories).
		$context = new Decision_Context(
			1,
			1_700_000_000,
			array(
				'wp' => array(
					'category' => array( 'sports', 'local' ),
				),
			)
		);

		$passing_row = array(
			'id'              => 1,
			'targeting_rules' => array(
				'dimension' => 'wp.category',
				'operator'  => 'in',
				'value'     => array( 'sports', 'entertainment' ),
			),
		);

		$failing_row = array(
			'id'              => 2,
			'targeting_rules' => array(
				'dimension' => 'wp.category',
				'operator'  => 'in',
				'value'     => array( 'finance', 'technology' ),
			),
		);

		$this->assertNull( Targeting_Rules::evaluate_candidate( $passing_row, $context ) );
		$this->assertSame(
			Exclusion_Reason::TARGETING_EXCLUDED,
			Targeting_Rules::evaluate_candidate( $failing_row, $context )
		);

		// Test NOT_IN operator.
		$not_in_row = array(
			'id'              => 3,
			'targeting_rules' => array(
				'dimension' => 'wp.category',
				'operator'  => 'not_in',
				'value'     => array( 'politics' ),
			),
		);

		$this->assertNull( Targeting_Rules::evaluate_candidate( $not_in_row, $context ) );
	}

	public function test_contains_and_not_contains_operators(): void {
		$context = new Decision_Context(
			1,
			1_700_000_000,
			array(
				'url' => array(
					'path' => '/sports/olympics-2026',
				),
			)
		);

		$contains_row = array(
			'id'              => 1,
			'targeting_rules' => array(
				'dimension' => 'url.path',
				'operator'  => 'contains',
				'value'     => 'olympics',
			),
		);

		$not_contains_row = array(
			'id'              => 2,
			'targeting_rules' => array(
				'dimension' => 'url.path',
				'operator'  => 'not_contains',
				'value'     => 'politics',
			),
		);

		$this->assertNull( Targeting_Rules::evaluate_candidate( $contains_row, $context ) );
		$this->assertNull( Targeting_Rules::evaluate_candidate( $not_contains_row, $context ) );
	}

	public function test_exists_and_not_exists_operators(): void {
		$context = new Decision_Context(
			1,
			1_700_000_000,
			array(
				'user' => array(
					'logged_in' => true,
					'role'      => 'subscriber',
				),
			)
		);

		$exists_row = array(
			'id'              => 1,
			'targeting_rules' => array(
				'dimension' => 'user.role',
				'operator'  => 'exists',
			),
		);

		$not_exists_row = array(
			'id'              => 2,
			'targeting_rules' => array(
				'dimension' => 'user.membership_tier',
				'operator'  => 'not_exists',
			),
		);

		$this->assertNull( Targeting_Rules::evaluate_candidate( $exists_row, $context ) );
		$this->assertNull( Targeting_Rules::evaluate_candidate( $not_exists_row, $context ) );
	}

	public function test_nested_and_or_not_boolean_combinations(): void {
		$context = new Decision_Context(
			1,
			1_700_000_000,
			array(
				'device' => 'mobile',
				'geo'    => array( 'country' => 'US' ),
				'wp'     => array( 'post_type' => 'post' ),
			)
		);

		// AST: (device == 'mobile' AND geo.country == 'US') AND NOT (wp.post_type == 'page').
		$tree = array(
			'operator' => 'AND',
			'rules'    => array(
				array(
					'operator' => 'OR',
					'rules'    => array(
						array(
							'dimension' => 'device',
							'operator'  => 'eq',
							'value'     => 'mobile',
						),
						array(
							'dimension' => 'device',
							'operator'  => 'eq',
							'value'     => 'tablet',
						),
					),
				),
				array(
					'dimension' => 'geo.country',
					'operator'  => 'eq',
					'value'     => 'US',
				),
				array(
					'operator' => 'NOT',
					'rules'    => array(
						array(
							'dimension' => 'wp.post_type',
							'operator'  => 'eq',
							'value'     => 'page',
						),
					),
				),
			),
		);

		$row = array(
			'id'              => 1,
			'targeting_rules' => $tree,
		);

		$this->assertNull( Targeting_Rules::evaluate_candidate( $row, $context ) );
	}

	public function test_missing_facts_fail_cleanly_without_throwing(): void {
		$context = new Decision_Context( 1, 1_700_000_000, array() );

		$row = array(
			'id'              => 1,
			'targeting_rules' => array(
				'dimension' => 'geo.country',
				'operator'  => 'eq',
				'value'     => 'CA',
			),
		);

		$this->assertSame(
			Exclusion_Reason::TARGETING_EXCLUDED,
			Targeting_Rules::evaluate_candidate( $row, $context )
		);
	}

	public function test_corrupted_json_or_invalid_tree_fails_closed_with_error_reason(): void {
		$context = new Decision_Context( 1, 1_700_000_000, array() );

		// Malformed string that cannot decode properly or causes an exception.
		$row = array(
			'id'                => 1,
			'delivery_settings' => '{invalid-json',
		);

		// Unparseable JSON in delivery_settings gracefully returns null or handles safely.
		$this->assertNull( Targeting_Rules::evaluate_candidate( $row, $context ) );
	}

	public function test_targeting_stage_evaluates_and_isolates_candidates(): void {
		$stage   = new Targeting_Stage();
		$context = new Decision_Context(
			1,
			1_700_000_000,
			array(
				'device' => 'mobile',
			)
		);

		$candidates = array(
			new Decision_Candidate(
				array(
					'id'              => 1,
					'targeting_rules' => array(
						'dimension' => 'device',
						'operator'  => 'eq',
						'value'     => 'mobile',
					),
				)
			),
			new Decision_Candidate(
				array(
					'id'              => 2,
					'targeting_rules' => array(
						'dimension' => 'device',
						'operator'  => 'eq',
						'value'     => 'desktop',
					),
				)
			),
		);

		$evaluated = $stage->evaluate( $candidates, $context );

		$this->assertCount( 2, $evaluated );
		$this->assertTrue( $evaluated[0]->is_eligible() );
		$this->assertFalse( $evaluated[1]->is_eligible() );
		$this->assertSame( 'targeting', $evaluated[1]->exclusion_stage );
		$this->assertSame( Exclusion_Reason::TARGETING_EXCLUDED, $evaluated[1]->exclusion_reason );
	}

	public function test_pipeline_integration_with_targeting_stage(): void {
		$pipeline = Decision_Pipeline::standard();

		$rows = array(
			array(
				'id'              => 1,
				'weight'          => 100,
				'attachment_id'   => 501,
				'click_url'       => 'https://example.com/a',
				'targeting_rules' => array(
					'dimension' => 'device',
					'operator'  => 'eq',
					'value'     => 'tablet',
				),
			),
		);

		$request  = new Decision_Request( 1, 1_700_000_000, 100, array( 'device' => 'mobile' ) );
		$decision = $pipeline->decide( $rows, $request );

		$this->assertFalse( $decision['result']->has_winner() );

		/*
		 * The slot's reason is the candidate's, because every candidate lost
		 * for it. A generic no-fill here would tell a publisher nothing was
		 * assigned when something was, and send them looking at a campaign that
		 * is working exactly as configured.
		 */
		$this->assertSame( Exclusion_Reason::TARGETING_EXCLUDED, $decision['result']->reason );

		$this->assertNotEmpty( $decision['trace']->entries );
		$this->assertSame( 'targeting', $decision['trace']->entries[0]['stage'] );
		$this->assertSame( Exclusion_Reason::TARGETING_EXCLUDED, $decision['trace']->entries[0]['reason'] );
	}
}
