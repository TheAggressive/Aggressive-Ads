<?php
/**
 * What a publisher was told, and whether it survives being told otherwise.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Supply_Forecast;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Forecast_Recorder;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_UnitTestCase;

/**
 * The inventory-commerce contract makes one demand of this table that no
 * amount of care in the caller can satisfy: *forecasts are immutable
 * snapshots; reforecasting creates a new version and preserves observed error
 * for the old one.* A figure a publisher quoted in March has to survive being
 * re-forecast in April, or the error recorded against the March number is
 * measuring whatever the table happens to hold now.
 *
 * So these assert the negatives as much as the positives: what a second
 * forecast must not overwrite, what a second maturing run must not rewrite,
 * and what an open window must not be recorded as.
 */
final class ForecastSnapshotTest extends WP_UnitTestCase {

	/**
	 * Snapshot storage.
	 *
	 * @var Forecast_Repository
	 */
	private Forecast_Repository $forecasts;

	/**
	 * Writer under test.
	 *
	 * @var Forecast_Recorder
	 */
	private Forecast_Recorder $recorder;

	/**
	 * Counter writes.
	 *
	 * @var Decision_Rollup_Repository
	 */
	private Decision_Rollup_Repository $rollups;

	/**
	 * The last day whose counters are sealed.
	 *
	 * @var string
	 */
	private string $last = '';

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$container       = Plugin::instance()->container();
		$this->forecasts = $container->get( Forecast_Repository::class );
		$this->recorder  = $container->get( Forecast_Recorder::class );
		$this->rollups   = $container->get( Decision_Rollup_Repository::class );

		$this->rollups->install_table();
		$this->forecasts->install_table();

		$this->last = Rollup_Reconciler::latest_closed_day();
	}

	/**
	 * A placement old enough to have history behind it.
	 *
	 * @return int
	 */
	private function placement(): int {
		$created = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );

		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'     => Post_Types::PLACEMENT,
				'post_status'   => 'publish',
				'post_name'     => 'slot-' . wp_generate_password( 8, false ),
				'post_title'    => 'Slot',
				'post_date_gmt' => $created,
				'post_date'     => $created,
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		return $placement_id;
	}

	/**
	 * A UTC day relative to the last sealed one.
	 *
	 * @param int $ago Days back; negative for the future.
	 * @return string
	 */
	private function day( int $ago ): string {
		return gmdate( 'Y-m-d', strtotime( $this->last . ' 00:00:00 UTC' ) - $ago * DAY_IN_SECONDS );
	}

	/**
	 * Records opportunities through the production write path.
	 *
	 * @param int    $placement Placement post id.
	 * @param int    $from_ago  First day back from the sealed day.
	 * @param int    $to_ago    Last day back, inclusive and smaller.
	 * @param int    $each      Opportunities per day.
	 * @param string $kind      Opportunity kind.
	 */
	private function record_days( int $placement, int $from_ago, int $to_ago, int $each, string $kind = Opportunity::PAGE ): void {
		for ( $ago = $from_ago; $ago >= $to_ago; $ago-- ) {
			$this->rollups->add( $this->day( $ago ), $placement, array( Decision_Outcome::REQUEST => $each ), $kind );
		}
	}

	public function test_the_table_is_installed_at_the_current_schema_version(): void {
		$this->assertTrue( $this->forecasts->table_exists() );
		$this->assertSame( 27, Schema::DB_VERSION, 'A new table without a version bump never reaches an existing site.' );
	}

	public function test_a_forecast_is_stored_as_version_one(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 30, 0, 500 );

		$result = $this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ), 30 );

		$this->assertSame( 1, $result['version'] );
		$this->assertSame( 3500, $result['forecast']['estimate'] );

		$stored = $this->forecasts->latest( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ) );

		$this->assertIsArray( $stored );
		$this->assertSame( 3500, $stored['estimate'] );
		$this->assertSame( Supply_Forecast::CONFIDENCE_HIGH, $stored['confidence'] );
		$this->assertNull( $stored['actual'], 'A window nobody has measured is not a window that supplied nothing.' );
	}

	public function test_reforecasting_preserves_what_was_said_before(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 30, 0, 500 );

		// A lean stretch further back, which a wider history would see.
		$this->record_days( $placement, 60, 31, 50 );

		$first = $this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ), 30 );

		/*
		 * The same window, re-forecast against sixty days rather than thirty.
		 * A quantile over the wider run reaches down into the lean stretch, so
		 * the second answer is genuinely lower — which is the situation the
		 * immutability rule exists for.
		 */
		$second = $this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ), 60 );

		$this->assertSame( 1, $first['version'] );
		$this->assertSame( 2, $second['version'] );

		$versions = $this->recorder->history_for( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ) );

		$this->assertCount( 2, $versions );
		$this->assertSame(
			3500,
			$versions[0]['estimate'],
			'The March figure has to survive being told something else in April, or the error recorded against it measures whatever the table holds now.'
		);
		$this->assertLessThan( 3500, $versions[1]['estimate'] );
	}

	public function test_a_forecast_with_no_estimate_is_not_stored(): void {
		/*
		 * Made today, so there is no day it existed for and nothing to draw
		 * from. A placement that merely produced nothing is a different case:
		 * that one has a real zero forecast, which is worth storing.
		 */
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'slot-' . wp_generate_password( 8, false ),
			)
		);

		$result = $this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ) );

		$this->assertNull( $result['forecast']['estimate'] );
		$this->assertSame(
			0,
			$result['version'],
			'A row with nothing to be wrong about adds a version whose error can never be computed, and makes an unmeasurable placement look re-forecast.'
		);
		$this->assertSame( array(), $this->recorder->history_for( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ) ) );
	}

	public function test_a_closed_window_records_what_it_actually_supplied(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 40, 15, 500 );

		// A window that has already ended, forecast after the fact so the
		// snapshot exists to be matured.
		$this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ), 40 );
		$this->record_days( $placement, 7, 1, 300 );

		$closed = $this->recorder->mature( $this->last );

		$this->assertSame( 1, $closed );

		$stored = $this->forecasts->latest( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ) );

		$this->assertIsArray( $stored );
		$this->assertSame( 2100, $stored['actual'], 'Seven days at three hundred is what the window really produced.' );
	}

	public function test_an_outcome_is_written_once(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 40, 15, 500 );
		$this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ), 40 );
		$this->record_days( $placement, 7, 1, 300 );

		$this->assertSame( 1, $this->recorder->mature( $this->last ) );

		$written = $this->forecasts->record_actual( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ), 999999 );

		$this->assertSame(
			0,
			$written,
			'A matured figure that can be rewritten is a figure an inconvenient forecast error can be edited out of.'
		);

		$stored = $this->forecasts->latest( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ) );

		$this->assertIsArray( $stored );
		$this->assertSame( 2100, $stored['actual'] );
	}

	public function test_every_version_of_a_window_is_judged_against_the_same_outcome(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 40, 15, 500 );
		$this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ), 40 );
		$this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ), 40 );
		$this->record_days( $placement, 7, 1, 300 );

		$this->recorder->mature( $this->last );

		$versions = $this->recorder->history_for( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ) );

		$this->assertCount( 2, $versions );

		foreach ( $versions as $version ) {
			$this->assertSame(
				2100,
				$version['actual'],
				'Each version forecast the same days, so each one is wrong or right about the same outcome.'
			);
		}
	}

	public function test_an_open_window_is_not_matured(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 30, 0, 500 );

		// A window running into the future: it cannot have supplied anything yet.
		$this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ), 30 );

		$this->assertSame(
			0,
			$this->recorder->mature( $this->last ),
			'A partial window summed as though it were complete reports every placement as over-forecast — an error that says the model is pessimistic when what happened is that nobody waited.'
		);

		$stored = $this->forecasts->latest( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ) );

		$this->assertIsArray( $stored );
		$this->assertNull( $stored['actual'] );
	}

	public function test_a_caller_that_asks_too_early_still_matures_nothing(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 30, 0, 500 );
		$this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ), 30 );

		/*
		 * `awaiting_actuals()` filters windows that have not ended, so the
		 * guards below it are only reached by a caller that asks about a day
		 * the clock has not got to — a wrong argument, or a skewed clock. That
		 * is precisely when a partial window would be written down as an
		 * outcome, so the guards are asserted through it rather than trusted.
		 */
		$this->assertSame(
			0,
			$this->recorder->mature( $this->day( -30 ) ),
			'A window still running cannot have supplied anything, and recording what it has produced so far as its outcome reports the placement as over-forecast for ever.'
		);

		$stored = $this->forecasts->latest( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ) );

		$this->assertIsArray( $stored );
		$this->assertNull( $stored['actual'] );
	}

	public function test_a_refresh_window_is_not_judged_by_page_supply(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 40, 0, 100, Opportunity::REFRESH );
		$this->record_days( $placement, 40, 0, 9000, Opportunity::PAGE );

		$this->recorder->snapshot( $placement, Opportunity::REFRESH, $this->day( 7 ), $this->day( 1 ), 40 );
		$this->recorder->mature( $this->last );

		$stored = $this->forecasts->latest( $placement, Opportunity::REFRESH, $this->day( 7 ), $this->day( 1 ) );

		$this->assertIsArray( $stored );
		$this->assertSame(
			700,
			$stored['actual'],
			'Page and refresh are separate inventory. Measuring one against the other would report a refresh forecast as catastrophically low.'
		);
	}

	public function test_a_reversed_window_stores_nothing(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 30, 0, 500 );

		$this->assertSame(
			0,
			$this->forecasts->record(
				$placement,
				Opportunity::PAGE,
				$this->day( -7 ),
				$this->day( -1 ),
				array(
					'estimate'      => 100,
					'optimistic'    => 200,
					'confidence'    => Supply_Forecast::CONFIDENCE_HIGH,
					'days_observed' => 30,
					'days_forecast' => 7,
				)
			)
		);
	}

	public function test_an_unknown_opportunity_stores_nothing(): void {
		$this->assertSame(
			0,
			$this->forecasts->record(
				$this->placement(),
				'invented',
				$this->day( -1 ),
				$this->day( -7 ),
				array(
					'estimate'      => 100,
					'optimistic'    => 200,
					'confidence'    => Supply_Forecast::CONFIDENCE_HIGH,
					'days_observed' => 30,
					'days_forecast' => 7,
				)
			)
		);
	}

	public function test_purging_removes_windows_that_have_ended(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 40, 0, 500 );
		$this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ), 40 );

		$this->assertCount( 1, $this->recorder->history_for( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ) ) );

		$removed = $this->forecasts->purge_through( $this->day( 0 ), 100 );

		$this->assertSame( 1, $removed );
		$this->assertSame( array(), $this->recorder->history_for( $placement, Opportunity::PAGE, $this->day( 7 ), $this->day( 1 ) ) );
	}

	public function test_purging_leaves_a_window_that_has_not_ended(): void {
		$placement = $this->placement();

		$this->record_days( $placement, 30, 0, 500 );
		$this->recorder->snapshot( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ), 30 );

		$this->assertSame(
			0,
			$this->forecasts->purge_through( $this->day( 0 ), 100 ),
			'Retention that reached a live window would delete the forecast a publisher is currently selling against.'
		);
		$this->assertCount( 1, $this->recorder->history_for( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ) ) );
	}

	public function test_the_container_supplies_both_halves(): void {
		$container = Plugin::instance()->container();

		$this->assertInstanceOf( Forecast_Repository::class, $container->get( Forecast_Repository::class ) );
		$this->assertInstanceOf( Forecast_Recorder::class, $container->get( Forecast_Recorder::class ) );
	}
}
