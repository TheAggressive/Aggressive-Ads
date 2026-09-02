<?php
/**
 * Native-delivery production dependency checks.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Security\Delivery_Health;
use Aggressive\Ads\Workflow\Event_Retention;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_UnitTestCase;

/** Site Health exposes the cache and maintenance requirements. */
final class DeliveryHealthTest extends WP_UnitTestCase {

	/**
	 * Health service under test.
	 *
	 * @var Delivery_Health
	 */
	private Delivery_Health $health;

	/** Resolves the hooked health service. */
	public function set_up(): void {
		parent::set_up();
		$this->health = Plugin::instance()->container()->get( Delivery_Health::class );
	}

	/** The test is wired without replacing core tests. */
	public function test_delivery_health_is_registered(): void {
		$this->assertNotFalse( has_filter( 'site_status_tests', array( $this->health, 'register_test' ) ) );

		$tests  = array( 'direct' => array( 'core_test' => array( 'test' => '__return_true' ) ) );
		$result = $this->health->register_test( $tests );

		$this->assertArrayHasKey( 'core_test', $result['direct'] );
		$this->assertArrayHasKey( 'aggr_delivery_capacity', $result['direct'] );
	}

	/** Missing persistent cache is visible before a high-volume launch. */
	public function test_missing_persistent_cache_is_recommended(): void {
		$external = wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );

		try {
			$result = $this->health->run_test();
		} finally {
			wp_using_ext_object_cache( $external );
		}

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Redis or Memcached', (string) $result['description'] );
	}

	/** A representative 1,000-id cache item and both cron jobs pass together. */
	public function test_persistent_cache_capacity_and_maintenance_are_good(): void {
		$container = Plugin::instance()->container();
		$container->get( Rollup_Reconciler::class )->ensure_scheduled();
		$container->get( Event_Retention::class )->ensure_scheduled();

		$external = wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );

		try {
			$result = $this->health->run_test();
		} finally {
			wp_using_ext_object_cache( $external );
		}

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( '1,000-creative', (string) $result['description'] );
	}

	/**
	 * Runs the check with a persistent cache and the maintenance jobs scheduled.
	 *
	 * @return array<string, mixed>
	 */
	private function healthy_result(): array {
		$container = Plugin::instance()->container();
		$container->get( Rollup_Reconciler::class )->ensure_scheduled();
		$container->get( Event_Retention::class )->ensure_scheduled();

		$external = wp_using_ext_object_cache();
		wp_using_ext_object_cache( true );

		try {
			return $this->health->run_test();
		} finally {
			wp_using_ext_object_cache( $external );
		}
	}

	/**
	 * An installed, empty rollups table.
	 *
	 * Emptied rather than assumed empty: the suite's tables are shared and
	 * other classes seed rows that predate `projector_version`, which arrive
	 * here as version 0. A version assertion over somebody else's fixture
	 * measures the suite rather than the code — this is the "assert your
	 * fixture is real before asserting on it" rule, and it caught this test
	 * reporting versions `(0, 1)` for a single seeded row.
	 */
	private function empty_rollups(): \Aggressive\Ads\Repository\Rollup_Repository {
		global $wpdb;

		$rollups = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Rollup_Repository::class );
		$rollups->install_table();

		$table = $rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clearing a shared fixture table.
		$wpdb->query( "DELETE FROM {$table}" );

		$this->assertSame(
			array(),
			$rollups->projector_versions(),
			'The fixture table is not empty, so the version assertions below would read other tests rows.'
		);

		return $rollups;
	}

	/**
	 * **"Is this day final?" is answerable without opening the database.**
	 *
	 * P13's observability contract requires the watermark to be readable. It
	 * was not: this check reported that the reconciler was *scheduled* and
	 * nothing about how far it had got, so a projection stalled three days back
	 * looked identical to one caught up.
	 */
	public function test_the_reconciliation_watermark_is_reported(): void {
		update_option( Rollup_Reconciler::OPTION, '2026-08-30' );

		$this->assertStringContainsString(
			'2026-08-30',
			(string) $this->healthy_result()['description'],
			'The watermark is not reported, so how far the projection has got is invisible.'
		);
	}

	/**
	 * And an installation that has never reconciled says so, rather than
	 * printing an empty date that reads as a bug.
	 */
	public function test_an_unreconciled_installation_says_so(): void {
		delete_option( Rollup_Reconciler::OPTION );

		$this->assertStringContainsString(
			'No day has been reconciled yet',
			(string) $this->healthy_result()['description']
		);
	}

	/**
	 * **Mixed projector versions are named**, which is what makes the column
	 * load-bearing rather than decorative.
	 *
	 * Nothing read `projector_version` before this. A column nothing looks at
	 * is a column that rots — this codebase already shipped `allow_s2s` stored,
	 * validated and exposed over REST while nothing set it, so every definition
	 * refused every server report.
	 */
	public function test_more_than_one_projector_version_is_named(): void {
		global $wpdb;

		$rollups = $this->empty_rollups();

		foreach ( array( 1, 2 ) as $version ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding two projector generations.
			$wpdb->insert(
				$rollups->table_name(),
				array(
					'day_utc'           => '2026-08-0' . $version,
					'placement_id'      => $version,
					'campaign_id'       => $version,
					'line_item_id'      => 0,
					'org_id'            => 5,
					'impressions'       => 1,
					'projector_version' => $version,
				)
			);
		}

		$description = (string) $this->healthy_result()['description'];

		$this->assertStringContainsString( 'more than one projector', $description );
		$this->assertStringContainsString( '1, 2', $description );
	}

	/**
	 * **And a single version says nothing at all.**
	 *
	 * Mixed versions are the expected state while an upgrade reconciles older
	 * days, so this must not become a sentence every healthy site carries — a
	 * check that always has something to say is one nobody reads.
	 */
	public function test_a_single_projector_version_adds_no_sentence(): void {
		global $wpdb;

		$rollups = $this->empty_rollups();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One projector generation only.
		$wpdb->insert(
			$rollups->table_name(),
			array(
				'day_utc'           => '2026-08-01',
				'placement_id'      => 9,
				'campaign_id'       => 9,
				'line_item_id'      => 0,
				'org_id'            => 5,
				'impressions'       => 1,
				'projector_version' => 1,
			)
		);

		$this->assertStringNotContainsString(
			'more than one projector',
			(string) $this->healthy_result()['description']
		);
	}
}
