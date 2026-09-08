<?php
/**
 * The outlook screen, and who may see it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Forecast_Data;
use Aggressive\Ads\Admin\Forecast_Screen;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Availability;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Supply_Forecast;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * P16's workflow layer was reachable only from tests until this screen existed,
 * which is the gap these close: the assembler is exercised through the
 * container, and the screen is exercised through `render()`.
 *
 * The distinctions under test are the ones a screen gets wrong — a placement
 * nobody has forecast must not read as sold out, and one kind of inventory must
 * not be totalled with another.
 */
final class ForecastScreenTest extends WP_UnitTestCase {

	/**
	 * Assembler under test.
	 *
	 * @var Forecast_Data
	 */
	private Forecast_Data $data;

	/**
	 * Snapshot storage.
	 *
	 * @var Forecast_Repository
	 */
	private Forecast_Repository $forecasts;

	/**
	 * Reservation ledger.
	 *
	 * @var Reservation_Repository
	 */
	private Reservation_Repository $reservations;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$container          = Plugin::instance()->container();
		$this->data         = $container->get( Forecast_Data::class );
		$this->forecasts    = $container->get( Forecast_Repository::class );
		$this->reservations = $container->get( Reservation_Repository::class );

		$this->forecasts->install_table();
		$this->reservations->install_table();
	}

	/**
	 * An active placement.
	 *
	 * @param string $name Display name.
	 * @return int
	 */
	private function placement( string $name = 'Leaderboard' ): int {
		/*
		 * Backdated, because `Supply_History` will not forecast a placement
		 * from days before it existed — a placement created today has no
		 * history and no forecast, which is correct and makes it useless as a
		 * fixture for a job that produces one.
		 */
		$created = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );

		$id = (int) self::factory()->post->create(
			array(
				'post_type'     => Post_Types::PLACEMENT,
				'post_status'   => 'publish',
				'post_title'    => $name,
				'post_name'     => 'slot-' . wp_generate_password( 8, false ),
				'post_date_gmt' => $created,
				'post_date'     => $created,
			)
		);

		update_post_meta( $id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $id, Placement_Repository::META_SIZE, '728x90' );

		return $id;
	}

	/**
	 * Records a forecast for the default window.
	 *
	 * @param int    $placement Placement post id.
	 * @param int    $estimate  What it is forecast to supply.
	 * @param string $kind      Opportunity kind.
	 */
	private function forecast( int $placement, int $estimate, string $kind = Opportunity::PAGE ): void {
		$window = $this->data->default_window();

		$this->forecasts->record(
			$placement,
			$kind,
			$window['from'],
			$window['to'],
			array(
				'estimate'      => $estimate,
				'optimistic'    => $estimate * 2,
				'confidence'    => Supply_Forecast::CONFIDENCE_HIGH,
				'days_observed' => 90,
				'days_forecast' => 30,
			)
		);
	}

	/**
	 * The default view.
	 *
	 * @return array<string, mixed>
	 */
	private function view(): array {
		$window = $this->data->default_window();

		return $this->data->view( Opportunity::PAGE, $window['from'], $window['to'] );
	}

	public function test_the_window_starts_after_the_counters_seal(): void {
		$window = $this->data->default_window();

		$this->assertGreaterThan(
			gmdate( 'Y-m-d' ),
			$window['from'],
			'A window including today mixes a part-measured day into a forecast of unmeasured ones, and the result is neither.'
		);
		$this->assertGreaterThan( $window['from'], $window['to'] );
	}

	public function test_a_forecast_placement_shows_its_figures(): void {
		$placement = $this->placement( 'Leaderboard' );

		$this->forecast( $placement, 10000 );

		$rows = $this->view()['rows'];

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Leaderboard', $rows[0]['name'] );
		$this->assertSame( 10000, $rows[0]['forecast'] );
		$this->assertSame( 0, $rows[0]['committed'] );
		$this->assertSame( 10000, $rows[0]['remaining'] );
		$this->assertSame( Availability::AVAILABLE, $rows[0]['verdict'] );
	}

	public function test_an_unforecast_placement_is_not_sold_out(): void {
		$this->placement();

		$rows = $this->view()['rows'];

		$this->assertNull(
			$rows[0]['forecast'],
			'A screen rendering nought would say a placement nobody has measured is sold out.'
		);
		$this->assertNull( $rows[0]['remaining'] );
		$this->assertSame( Availability::UNKNOWN, $rows[0]['verdict'] );
	}

	public function test_reservations_reduce_what_is_left(): void {
		$placement = $this->placement();
		$window    = $this->data->default_window();

		$this->forecast( $placement, 10000 );
		$this->reservations->claim(
			array(
				'placement'        => $placement,
				'opportunity'      => Opportunity::PAGE,
				'from'             => $window['from'],
				'to'               => $window['to'],
				'campaign'         => 5,
				'org'              => 6,
				'quantity'         => 4000,
				'capacity'         => 10000,
				'forecast_version' => 1,
			)
		);

		$rows = $this->view()['rows'];

		$this->assertSame( 4000, $rows[0]['committed'] );
		$this->assertSame( 6000, $rows[0]['remaining'] );
	}

	public function test_refresh_inventory_is_not_page_inventory(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 9000, Opportunity::REFRESH );

		$this->assertNull(
			$this->view()['rows'][0]['forecast'],
			'Summing the kinds would put a rotation timer into the page supply figure.'
		);
	}

	public function test_the_refresh_view_shows_refresh_inventory(): void {
		$placement = $this->placement();
		$window    = $this->data->default_window();

		$this->forecast( $placement, 9000, Opportunity::REFRESH );

		$refresh = $this->data->view( Opportunity::REFRESH, $window['from'], $window['to'] );

		$this->assertSame( Opportunity::REFRESH, $refresh['opportunity'] );
		$this->assertSame(
			9000,
			$refresh['rows'][0]['forecast'],
			'Asking for one kind and being shown the other makes the screen unable to speak about refresh inventory at all.'
		);
	}

	public function test_an_invented_kind_falls_back_to_page(): void {
		$placement = $this->placement();

		$this->forecast( $placement, 4000 );

		$view = $this->data->view( 'invented', $this->data->default_window()['from'], $this->data->default_window()['to'] );

		$this->assertSame( Opportunity::PAGE, $view['opportunity'] );
		$this->assertSame( 4000, $view['rows'][0]['forecast'] );
	}

	public function test_the_totals_count_what_the_screen_cannot_speak_for(): void {
		$forecast = $this->placement( 'Forecast' );

		$this->forecast( $forecast, 5000 );
		$this->placement( 'Unmeasured' );

		$totals = $this->view()['totals'];

		$this->assertSame( 2, $totals['placements'] );
		$this->assertSame( 5000, $totals['forecast'] );
		$this->assertSame(
			1,
			$totals['unforecast'],
			'A total that silently skipped unmeasured placements would present partial coverage as complete.'
		);
		$this->assertSame( 0, $totals['oversold'] );
	}

	public function test_an_oversold_placement_is_counted(): void {
		$placement = $this->placement();
		$window    = $this->data->default_window();

		$this->forecast( $placement, 1000 );
		$this->reservations->claim(
			array(
				'placement'        => $placement,
				'opportunity'      => Opportunity::PAGE,
				'from'             => $window['from'],
				'to'               => $window['to'],
				'campaign'         => 5,
				'org'              => 6,
				'quantity'         => 5000,
				'capacity'         => 5000,
				'forecast_version' => 1,
			)
		);

		$this->assertSame( 1, $this->view()['totals']['oversold'] );
		$this->assertSame( 0, $this->view()['rows'][0]['remaining'] );
	}

	public function test_a_reader_without_the_capability_is_refused(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$this->assertFalse( current_user_can( Capabilities::MANAGE_PLACEMENTS ) );

		$screen = Plugin::instance()->container()->get( Forecast_Screen::class );

		$this->expectException( \WPDieException::class );

		$screen->render();
	}

	public function test_an_authorized_reader_gets_the_payload(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$placement = $this->placement( 'Leaderboard' );

		$this->forecast( $placement, 10000 );

		$screen = Plugin::instance()->container()->get( Forecast_Screen::class );

		ob_start();
		$screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aggr-forecast-root', $html );
		$this->assertStringContainsString( 'data-aggr-forecast', $html );
		$this->assertStringContainsString(
			'Leaderboard',
			html_entity_decode( $html ),
			'The payload reached the page, which is the half a screen test exists to prove.'
		);
	}

	/**
	 * **The screen reads a table, and something has to write it.**
	 *
	 * Shipped without this, the outlook reported every placement as never
	 * forecast — `aggr_forecasts` was read by a screen and written by nothing.
	 * That is the failure this project keeps re-shipping, so the job that fills
	 * it is asserted through the same path cron takes.
	 *
	 * @return void
	 */
	public function test_the_scheduled_job_gives_the_screen_something_to_read(): void {
		$placement = $this->placement();
		$rollups   = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Decision_Rollup_Repository::class );

		$rollups->install_table();

		// Ninety days of history, so a forecast has something to draw from.
		for ( $ago = 90; $ago >= 1; $ago-- ) {
			$rollups->add(
				gmdate( 'Y-m-d', time() - $ago * DAY_IN_SECONDS ),
				$placement,
				array( \Aggressive\Ads\Domain\Decision_Outcome::REQUEST => 500 ),
				Opportunity::PAGE
			);
		}

		$this->assertNull(
			$this->view()['rows'][0]['forecast'],
			'Nothing has forecast it yet, which is the state the screen shipped in.'
		);

		$written = Plugin::instance()->container()
			->get( \Aggressive\Ads\Workflow\Forecast_Scheduler::class )
			->run();

		$this->assertGreaterThan( 0, $written );
		$this->assertNotNull(
			$this->view()['rows'][0]['forecast'],
			'The job ran and the screen still has nothing to show, so the two halves do not meet.'
		);
	}

	/**
	 * The job also closes windows that have finished.
	 *
	 * Maturing is what makes forecast error measurable, and it runs on every
	 * pass rather than only when a snapshot was written — a window that closed
	 * yesterday is waiting whether or not today produced anything.
	 *
	 * @return void
	 */
	public function test_the_job_records_what_a_finished_window_supplied(): void {
		$placement = $this->placement();
		$rollups   = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Decision_Rollup_Repository::class );

		$rollups->install_table();

		// A week that has already ended, with real supply recorded against it.
		for ( $ago = 14; $ago >= 8; $ago-- ) {
			$rollups->add(
				gmdate( 'Y-m-d', time() - $ago * DAY_IN_SECONDS ),
				$placement,
				array( \Aggressive\Ads\Domain\Decision_Outcome::REQUEST => 200 ),
				Opportunity::PAGE
			);
		}

		$from = gmdate( 'Y-m-d', time() - 14 * DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d', time() - 8 * DAY_IN_SECONDS );

		$this->forecasts->record(
			$placement,
			Opportunity::PAGE,
			$from,
			$to,
			array(
				'estimate'      => 1000,
				'optimistic'    => 2000,
				'confidence'    => Supply_Forecast::CONFIDENCE_HIGH,
				'days_observed' => 30,
				'days_forecast' => 7,
			)
		);

		$this->assertNull( $this->forecasts->latest( $placement, Opportunity::PAGE, $from, $to )['actual'] );

		Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Forecast_Scheduler::class )->run();

		$this->assertSame(
			1400,
			$this->forecasts->latest( $placement, Opportunity::PAGE, $from, $to )['actual'],
			'Seven days at two hundred. Without maturing the snapshot never gains an outcome and its error can never be computed.'
		);
	}

	public function test_both_kinds_of_inventory_are_forecast(): void {
		$placement = $this->placement();
		$rollups   = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Decision_Rollup_Repository::class );

		$rollups->install_table();

		foreach ( Opportunity::all() as $kind ) {
			for ( $ago = 90; $ago >= 1; $ago-- ) {
				$rollups->add(
					gmdate( 'Y-m-d', time() - $ago * DAY_IN_SECONDS ),
					$placement,
					array( \Aggressive\Ads\Domain\Decision_Outcome::REQUEST => 300 ),
					$kind
				);
			}
		}

		Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Forecast_Scheduler::class )->run();

		$window = $this->data->default_window();

		foreach ( Opportunity::all() as $kind ) {
			$view = $this->data->view( $kind, $window['from'], $window['to'] );

			$this->assertNotNull(
				$view['rows'][0]['forecast'],
				"The {$kind} view reads as never forecast, so switching to it shows an empty screen."
			);
		}
	}

	public function test_the_container_supplies_the_screen(): void {
		$container = Plugin::instance()->container();

		$this->assertInstanceOf( Forecast_Data::class, $container->get( Forecast_Data::class ) );
		$this->assertInstanceOf( Forecast_Screen::class, $container->get( Forecast_Screen::class ) );
	}
}
