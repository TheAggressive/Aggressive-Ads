<?php
/**
 * Decision exclusion counters.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Workflow\Decision_Metrics;
use Aggressive\Ads\Workflow\Fill_Cache;
use WP_UnitTestCase;

/**
 * Exclusion reasons must be countable when nothing serves.
 */
final class DecisionMetricsTest extends WP_UnitTestCase {

	/**
	 * Metrics service under test.
	 *
	 * @var Decision_Metrics
	 */
	private Decision_Metrics $metrics;

	public function set_up(): void {
		parent::set_up();

		delete_option( Decision_Metrics::OPTION_EXCLUSIONS );
		$this->metrics = new Decision_Metrics();
	}

	public function tear_down(): void {
		delete_option( Decision_Metrics::OPTION_EXCLUSIONS );
		parent::tear_down();
	}

	public function test_record_exclusion_increments_aggregate_and_placement_counts(): void {
		$this->metrics->record_exclusion( 42, Exclusion_Reason::NO_FILL );
		$this->metrics->record_exclusion( 42, Exclusion_Reason::NO_FILL );
		$this->metrics->record_exclusion( 7, Exclusion_Reason::COMPETITION_LOST );

		$stored = get_option( Decision_Metrics::OPTION_EXCLUSIONS, array() );

		$this->assertIsArray( $stored );
		$this->assertSame( 2, $stored['aggregate'][ Exclusion_Reason::NO_FILL ] ?? 0 );
		$this->assertSame( 1, $stored['aggregate'][ Exclusion_Reason::COMPETITION_LOST ] ?? 0 );
		$this->assertSame( 2, $stored['placements']['42'][ Exclusion_Reason::NO_FILL ] ?? 0 );
		$this->assertSame( 1, $stored['placements']['7'][ Exclusion_Reason::COMPETITION_LOST ] ?? 0 );
		$this->assertSame(
			array(
				Exclusion_Reason::NO_FILL          => 2,
				Exclusion_Reason::COMPETITION_LOST => 1,
			),
			$this->metrics->exclusion_counts()
		);
	}

	public function test_record_exclusion_ignores_invalid_input(): void {
		$this->metrics->record_exclusion( 0, Exclusion_Reason::NO_FILL );
		$this->metrics->record_exclusion( 5, '' );

		$this->assertSame( array(), $this->metrics->exclusion_counts() );
		$this->assertFalse( get_option( Decision_Metrics::OPTION_EXCLUSIONS, false ) );
	}

	public function test_decide_records_exclusion_reasons(): void {
		Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->install_table();
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$container = Plugin::instance()->container();
		$metrics   = new Decision_Metrics();
		$engine    = new Decision_Engine(
			$container->get( Creative_Assignment_Repository::class ),
			$container->get( Creative_Assignment_Migrator::class ),
			$metrics,
			Decision_Pipeline::standard(),
			$container->get( Fill_Cache::class )
		);

		$engine->decide(
			99,
			time(),
			1,
			array(
				array(
					'id'            => 1,
					'line_item_id'  => 1,
					'campaign_id'   => 1,
					'revision_id'   => 1,
					'weight'        => 100,
					'attachment_id' => 0,
					'click_url'     => 'https://example.com/ad',
				),
			)
		);

		$this->assertSame(
			1,
			$metrics->exclusion_counts()[ Exclusion_Reason::ELIGIBILITY_MISSING_ATTACHMENT ] ?? 0
		);
		$this->assertSame( 1, $metrics->exclusion_counts()[ Exclusion_Reason::NO_FILL ] ?? 0 );
	}
}
