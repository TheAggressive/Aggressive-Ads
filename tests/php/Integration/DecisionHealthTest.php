<?php
/**
 * Site Health: assignment serving readiness.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Decision_Pipeline;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Decision_Health;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Workflow\Decision_Metrics;
use Aggressive\Ads\Workflow\Fill_Cache;
use WP_UnitTestCase;

/**
 * Serving readiness is visible before a publisher notices empty paid slots.
 */
final class DecisionHealthTest extends WP_UnitTestCase {

	/**
	 * Health service under test.
	 *
	 * @var Decision_Health
	 */
	private Decision_Health $health;

	public function set_up(): void {
		parent::set_up();

		$this->health = Plugin::instance()->container()->get( Decision_Health::class );
	}

	public function tear_down(): void {
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
		parent::tear_down();
	}

	public function test_decision_health_is_registered(): void {
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $this->health, 'register_test' ) ) );

		$tests  = array( 'direct' => array( 'core_test' => array( 'test' => '__return_true' ) ) );
		$result = $this->health->register_test( $tests );

		$this->assertArrayHasKey( 'core_test', $result['direct'] );
		$this->assertArrayHasKey( 'aggr_decision_serving_path', $result['direct'] );
	}

	public function test_backfill_pending_is_recommended(): void {
		Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->install_table();
		delete_option( Creative_Assignment_Migrator::OPTION_DONE );

		$result = $this->health->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'still running', $result['label'] );
	}

	public function test_serving_ready_is_good(): void {
		Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->install_table();
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$result = $this->health->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'creative assignments', $result['label'] );
	}

	public function test_finished_backfill_without_table_is_critical(): void {
		global $wpdb;

		$container   = Plugin::instance()->container();
		$assignments = new Creative_Assignment_Repository();
		$assignments->install_table();
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$table = $assignments->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Simulates a missing serving table after a reported-complete backfill.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		try {
			$engine = new Decision_Engine(
				$assignments,
				$container->get( Creative_Assignment_Migrator::class ),
				new Decision_Metrics(),
				Decision_Pipeline::standard(),
				$container->get( Fill_Cache::class )
			);
			$health = new Decision_Health( $engine, $container->get( Creative_Assignment_Migrator::class ) );
			$result = $health->run_test();

			$this->assertSame( 'critical', $result['status'] );
			$this->assertStringContainsString( 'not ready', $result['label'] );
		} finally {
			$assignments->install_table();
		}
	}
}
