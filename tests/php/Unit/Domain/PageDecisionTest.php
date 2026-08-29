<?php
/**
 * Page decision coordination unit tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Decision_Candidate;
use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Domain\Page_Coordination_Rules;
use Aggressive\Ads\Domain\Page_Decision_Coordinator;
use Aggressive\Ads\Domain\Weighted_Selection;
use PHPUnit\Framework\TestCase;

/**
 * Proves multi-slot coordination, creative deduplication, and competitive separation.
 */
final class PageDecisionTest extends TestCase {

	public function test_creative_deduplication_excludes_duplicate_asset_when_alternative_exists(): void {
		$candidate = array(
			'id'       => 10,
			'asset_id' => 999,
		);

		$served_assets = array( 999 );

		// Alternative exists -> Excluded as duplicate asset.
		$this->assertSame(
			Exclusion_Reason::PAGE_DUPLICATE_ASSET,
			Page_Coordination_Rules::evaluate_asset_deduplication( $candidate, $served_assets, true )
		);

		// No alternative exists -> Allowed to serve.
		$this->assertNull(
			Page_Coordination_Rules::evaluate_asset_deduplication( $candidate, $served_assets, false )
		);
	}

	public function test_competitive_separation_excludes_conflicting_organizations(): void {
		$candidate = array(
			'id'                => 1,
			'organization_id'   => 100,
			'delivery_settings' => array(
				'competing_orgs' => array( 200, 300 ),
			),
		);

		$served_org_ids = array( 200 ); // Org 200 already served on this page.
		$served_cats    = array();

		$this->assertSame(
			Exclusion_Reason::PAGE_COMPETITIVE_SEPARATION,
			Page_Coordination_Rules::evaluate_competitive_separation( $candidate, $served_org_ids, $served_cats )
		);
	}

	public function test_competitive_separation_excludes_exclusive_categories(): void {
		$candidate = array(
			'id'                => 1,
			'organization_id'   => 100,
			'delivery_settings' => array(
				'category'           => 'automotive',
				'exclusive_category' => true,
			),
		);

		$served_org_ids = array( 50 );
		$served_cats    = array( 'automotive' ); // Automotive already served on this page.

		$this->assertSame(
			Exclusion_Reason::PAGE_COMPETITIVE_SEPARATION,
			Page_Coordination_Rules::evaluate_competitive_separation( $candidate, $served_org_ids, $served_cats )
		);
	}

	public function test_page_decision_coordinator_resolves_multiple_slots(): void {
		$pipeline = Decision_Pipeline::standard();

		$slots_map = array(
			'header'  => array(
				'placement_id' => 1,
				'candidates'   => array(
					array(
						'id'              => 1,
						'asset_id'        => 101,
						'organization_id' => 10,
						'weight'          => 100,
						'attachment_id'   => 501,
						'click_url'       => 'https://example.com/a',
					),
				),
			),
			'sidebar' => array(
				'placement_id' => 2,
				'candidates'   => array(
					array(
						'id'              => 2,
						'asset_id'        => 102,
						'organization_id' => 20,
						'weight'          => 100,
						'attachment_id'   => 502,
						'click_url'       => 'https://example.com/b',
					),
				),
			),
		);

		$results = Page_Decision_Coordinator::coordinate( $slots_map, $pipeline, 1_700_000_000, 12345 );

		$this->assertArrayHasKey( 'header', $results );
		$this->assertArrayHasKey( 'sidebar', $results );

		$this->assertTrue( $results['header']['result']->has_winner() );
		$this->assertSame( 1, (int) $results['header']['result']->winner['id'] );

		$this->assertTrue( $results['sidebar']['result']->has_winner() );
		$this->assertSame( 2, (int) $results['sidebar']['result']->winner['id'] );
	}

	public function test_page_coordination_handles_corrupted_delivery_settings_gracefully(): void {
		$candidate = array(
			'id'                => 1,
			'organization_id'   => 10,
			'delivery_settings' => '{not-valid-json',
		);

		// Should not throw or crash; evaluates cleanly without false positives.
		$this->assertNull(
			Page_Coordination_Rules::evaluate_competitive_separation( $candidate, array( 20 ), array() )
		);
		$this->assertFalse(
			Page_Coordination_Rules::is_roadblock( $candidate )
		);
	}

	public function test_page_decision_coordinator_handles_empty_slots_map(): void {
		$pipeline = Decision_Pipeline::standard();
		$results  = Page_Decision_Coordinator::coordinate( array(), $pipeline, 1_700_000_000, 12345 );

		$this->assertSame( array(), $results );
	}

	public function test_competitive_separation_blocks_subsequent_slot_candidate_when_conflicting(): void {
		$pipeline = Decision_Pipeline::standard();

		$slots_map = array(
			'slot-1' => array(
				'placement_id' => 1,
				'candidates'   => array(
					array(
						'id'                => 1,
						'asset_id'          => 101,
						'organization_id'   => 10,
						'weight'            => 100,
						'attachment_id'     => 501,
						'click_url'         => 'https://example.com/a',
						'delivery_settings' => array(
							'category'           => 'finance',
							'exclusive_category' => true,
						),
					),
				),
			),
			'slot-2' => array(
				'placement_id' => 2,
				'candidates'   => array(
					array(
						'id'                => 2,
						'asset_id'          => 102,
						'organization_id'   => 20, // Different org, but also finance with category exclusivity.
						'weight'            => 100,
						'attachment_id'     => 502,
						'click_url'         => 'https://example.com/b',
						'delivery_settings' => array(
							'category'           => 'finance',
							'exclusive_category' => true,
						),
					),
				),
			),
		);

		$results = Page_Decision_Coordinator::coordinate( $slots_map, $pipeline, 1_700_000_000, 12345 );

		// Slot 1 is won by candidate 1.
		$this->assertTrue( $results['slot-1']['result']->has_winner() );
		$this->assertSame( 1, (int) $results['slot-1']['result']->winner['id'] );

		// Slot 2 candidate 2 is excluded by category competitive separation, yielding no fill.
		$this->assertFalse( $results['slot-2']['result']->has_winner() );
		$this->assertSame( Exclusion_Reason::NO_FILL, $results['slot-2']['result']->reason );

		$trace_entries = $results['slot-2']['trace']->entries;
		$this->assertNotEmpty( $trace_entries );
		$this->assertSame( Exclusion_Reason::PAGE_COMPETITIVE_SEPARATION, $trace_entries[0]['reason'] );
	}

	/**
	 * The coordinator decides a slot with the seed it was handed.
	 *
	 * Asserted against `Weighted_Selection` directly rather than by calling
	 * `coordinate()` twice and comparing. Two calls agreeing proves nothing
	 * when the seed is ignored — the winner would still match whenever chance
	 * landed the same way, which for two candidates is most of the time. A test
	 * that fails a third of the runs is worse than no test.
	 *
	 * This compares the winner to the one the selector picks for that exact
	 * seed, so an unthreaded seed fails every time.
	 */
	public function test_the_supplied_seed_decides_the_first_slot(): void {
		$candidates = array();

		foreach ( array( 1, 2, 3, 4, 5, 6 ) as $id ) {
			$candidates[] = array(
				'id'              => $id,
				'asset_id'        => 100 + $id,
				'organization_id' => 10 + $id,
				'weight'          => 100,
				'attachment_id'   => 500 + $id,
				'click_url'       => 'https://example.com/' . $id,
			);
		}

		$seed = 987_654;

		$results = Page_Decision_Coordinator::coordinate(
			array(
				'header' => array(
					'placement_id' => 1,
					'candidates'   => $candidates,
				),
			),
			Decision_Pipeline::standard(),
			1_700_000_000,
			$seed
		);

		$expected = Weighted_Selection::choose(
			array_map(
				static fn ( array $row ): Decision_Candidate => new Decision_Candidate( $row ),
				$candidates
			),
			$seed
		);

		$this->assertNotNull( $expected['winner'], 'The selector produced no winner to compare against.' );
		$this->assertTrue( $results['header']['result']->has_winner() );
		$this->assertSame(
			(int) $expected['winner']->row['id'],
			(int) $results['header']['result']->winner['id'],
			'The coordinator did not decide the slot with the seed it was given.'
		);
	}
}
