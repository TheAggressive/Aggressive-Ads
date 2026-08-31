<?php
/**
 * What the operator is told about conversion tracking.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Install\Conversion_Health;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Domain\Conversion_Attribution;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Conversion_Metrics;
use WP_UnitTestCase;

/**
 * Conversion tracking fails silently in a way delivery does not.
 *
 * An ad that cannot serve leaves a gap somebody notices. A conversion that
 * cannot be recorded looks exactly like a campaign nobody converted on — so
 * these assertions are about the three indistinguishable states being told
 * apart, and about the two ordinary ones not being reported as problems.
 */
final class ConversionHealthTest extends WP_UnitTestCase {

	/**
	 * Health test under test.
	 *
	 * @var Conversion_Health
	 */
	private Conversion_Health $health;

	/**
	 * Definition persistence.
	 *
	 * @var Conversion_Definition_Repository
	 */
	private Conversion_Definition_Repository $definitions;

	/**
	 * Reporting projection.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * Refusal counters.
	 *
	 * @var Conversion_Metrics
	 */
	private Conversion_Metrics $metrics;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->health      = $container->get( Conversion_Health::class );
		$this->definitions = $container->get( Conversion_Definition_Repository::class );
		$this->rollups     = $container->get( Rollup_Repository::class );
		$this->metrics     = $container->get( Conversion_Metrics::class );

		$this->metrics->reset();
		$this->definitions->install_table();
		$this->rollups->install_table();
	}

	/**
	 * Yesterday, which is the day the test reads.
	 */
	private function yesterday(): string {
		return gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	}

	/**
	 * Creates one definition.
	 *
	 * @param string $status Definition status.
	 */
	private function definition( string $status = Conversion_Definition::STATUS_ACTIVE ): int {
		return $this->definitions->create(
			array(
				'name'                 => 'Purchase',
				'org_id'               => 0,
				'window_seconds'       => 2592000,
				'default_value_micros' => 0,
				'currency'             => '',
				'allow_s2s'            => false,
				'status'               => $status,
			)
		);
	}

	/**
	 * Writes a day's counters straight into the projection.
	 *
	 * @param int      $clicks      Clicks that day.
	 * @param int|null $conversions Conversions, or null for a day nobody measured.
	 */
	private function day( int $clicks, ?int $conversions ): void {
		global $wpdb;

		$table = $this->rollups->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture for this plugin's own table.
		$wpdb->insert(
			$table,
			array(
				'day_utc'      => $this->yesterday(),
				'placement_id' => 11,
				'campaign_id'  => 22,
				'line_item_id' => 0,
				'impressions'  => max( $clicks, 1 ),
				'clicks'       => $clicks,
				'viewables'    => 0,
				'conversions'  => $conversions,
			)
		);
	}

	/**
	 * **The signal that matters most: nothing is defined, so nothing can be
	 * recorded.**
	 *
	 * Without this the whole feature is inert and every report reads as a
	 * campaign nobody converted on.
	 */
	public function test_no_definitions_is_reported_as_actionable(): void {
		$result = $this->health->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'No conversions are defined', $result['label'] );
	}

	/**
	 * An archived definition does not count as configuration.
	 *
	 * It exists and it refuses every report, so a site that retired everything
	 * must not look configured.
	 */
	public function test_an_archived_definition_does_not_count_as_configured(): void {
		$this->assertGreaterThan( 0, $this->definition( Conversion_Definition::STATUS_ARCHIVED ) );

		$result = $this->health->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'No conversions are defined', $result['label'] );
	}

	/**
	 * A quiet site is not a problem.
	 */
	public function test_no_clicks_is_not_reported_as_a_problem(): void {
		$this->definition();

		$result = $this->health->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'clicked', $result['label'] );
	}

	/**
	 * A day predating conversion tracking is not a problem either.
	 *
	 * NULL means nobody was counting. Reporting that as zero conversions would
	 * be the same lie the column exists to prevent.
	 */
	public function test_an_unmeasured_day_is_not_reported_as_zero(): void {
		$this->definition();
		$this->day( 40, null );

		$result = $this->health->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'not measured', $result['label'] );
	}

	/**
	 * **Clicks and no conversions is the case worth surfacing.**
	 *
	 * It can mean nobody converted. It can also mean the reporting key is not
	 * on the advertiser's page, or the click token is being stripped from the
	 * destination — and an operator cannot tell without being told to look.
	 */
	public function test_clicks_without_conversions_is_actionable(): void {
		$this->definition();
		$this->day( 40, 0 );

		$result = $this->health->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'no conversions were recorded', $result['label'] );
		$this->assertStringContainsString( 'aggr_ct', $result['description'] );
	}

	/**
	 * A working day says so, with the number.
	 */
	public function test_a_working_day_reports_the_count(): void {
		$this->definition();
		$this->day( 40, 3 );

		$result = $this->health->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( '3', $result['label'] );
	}

	/**
	 * The test is registered, or none of the above is ever seen.
	 */
	/**
	 * **The refusals are told to the operator, in words, where "why not" is the
	 * question being asked.**
	 *
	 * This is the read half of the counter. It was written after the write half
	 * because a counter nothing reads and a reader with nothing to count both
	 * pass their own tests; the pair is what proves the feature.
	 */
	public function test_refusals_are_explained_when_nothing_was_recorded(): void {
		$this->assertGreaterThan( 0, $this->definition() );
		$this->day( 40, 0 );

		foreach ( range( 1, 3 ) as $ignored ) {
			$this->metrics->record_refusal( Conversion_Attribution::OUT_OF_WINDOW );
		}

		$this->metrics->flush();

		$result = $this->health->run_test();

		$this->assertSame( 'recommended', $result['status'], 'The counters must inform the description, never the status.' );
		$this->assertStringContainsString( 'Reports refused since', (string) $result['description'] );
		$this->assertStringContainsString( 'attribution window', (string) $result['description'] );
		$this->assertStringContainsString( '3', (string) $result['description'] );
	}

	/**
	 * A reason code never reaches the screen, only what it means.
	 *
	 * `out_of_window` is a decision this codebase makes, not a sentence anybody
	 * outside it can act on.
	 */
	public function test_a_reason_code_is_never_shown_raw(): void {
		$this->assertGreaterThan( 0, $this->definition() );
		$this->day( 40, 0 );
		$this->metrics->record_refusal( Conversion_Attribution::S2S_NOT_PERMITTED );
		$this->metrics->flush();

		$description = (string) $this->health->run_test()['description'];

		$this->assertStringContainsString( 'does not accept server reports', $description );
		$this->assertStringNotContainsString( Conversion_Attribution::S2S_NOT_PERMITTED, $description );
	}

	/**
	 * **Nothing refused says nothing**, rather than "0 refusals".
	 *
	 * A count of zero invites the reader to wonder what it is a count of, on
	 * the healthy site where there is nothing to wonder about.
	 */
	public function test_no_refusals_adds_no_sentence(): void {
		$this->assertGreaterThan( 0, $this->definition() );
		$this->day( 40, 7 );

		$result = $this->health->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringNotContainsString( 'refused', (string) $result['description'] );
	}

	public function test_the_test_is_registered_with_site_health(): void {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayHasKey( 'aggr_conversions', $tests['direct'] );
	}

	/**
	 * `conversions` stays nullable all the way out of the repository.
	 *
	 * Coalescing it to zero anywhere between the column and the operator turns
	 * "no day was measured" into "nothing converted", which is the distinction
	 * the column exists for.
	 */
	public function test_the_repository_keeps_unmeasured_apart_from_zero(): void {
		$this->day( 5, null );

		$this->assertNull( $this->rollups->day_conversions( $this->yesterday() )['conversions'] );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture for this plugin's own table.
		$wpdb->update(
			$this->rollups->table_name(),
			array( 'conversions' => 0 ),
			array( 'day_utc' => $this->yesterday() ),
			array( '%d' ),
			array( '%s' )
		);

		$this->assertSame( 0, $this->rollups->day_conversions( $this->yesterday() )['conversions'] );
	}
}
