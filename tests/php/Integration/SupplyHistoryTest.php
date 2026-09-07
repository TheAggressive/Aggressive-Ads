<?php
/**
 * Which days count as evidence, and which are only silence.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Supply_Forecast;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use Aggressive\Ads\Workflow\Supply_History;
use WP_UnitTestCase;

/**
 * `SupplyForecastTest` proves the arithmetic against history handed to it.
 * This proves the history — which is the half that was missing, and the half
 * that decides whether the arithmetic is being fed evidence or an illusion.
 *
 * Every fixture writes through `Decision_Rollup_Repository::add()`, the same
 * call the delivery path makes. A forecast test that arranged its own series
 * would be testing quantiles again, and would keep passing if the query behind
 * it read the wrong outcome, the wrong opportunity kind or the wrong placement.
 */
final class SupplyHistoryTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Supply_History
	 */
	private Supply_History $history;

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

		$container     = Plugin::instance()->container();
		$this->history = $container->get( Supply_History::class );
		$this->rollups = $container->get( Decision_Rollup_Repository::class );

		$this->rollups->install_table();
		$this->last = Rollup_Reconciler::latest_closed_day();
	}

	/**
	 * A placement, optionally backdated so history has room before it.
	 *
	 * @param int $created_days_ago How long ago it was made.
	 * @return int
	 */
	private function placement( int $created_days_ago = 400 ): int {
		$created = gmdate( 'Y-m-d H:i:s', time() - $created_days_ago * DAY_IN_SECONDS );

		return (int) self::factory()->post->create(
			array(
				'post_type'     => Post_Types::PLACEMENT,
				'post_status'   => 'publish',
				'post_name'     => 'slot-' . wp_generate_password( 8, false ),
				'post_title'    => 'Slot',
				'post_date_gmt' => $created,
				'post_date'     => $created,
			)
		);
	}

	/**
	 * A UTC day some number of days before the last sealed one.
	 *
	 * @param int $ago Days back from the last sealed day.
	 * @return string
	 */
	private function day( int $ago ): string {
		return gmdate( 'Y-m-d', strtotime( $this->last . ' 00:00:00 UTC' ) - $ago * DAY_IN_SECONDS );
	}

	/**
	 * Records opportunities through the production write path.
	 *
	 * @param int    $placement   Placement post id.
	 * @param string $day         UTC day.
	 * @param int    $requests    Opportunities to record.
	 * @param string $opportunity Kind.
	 */
	private function record( int $placement, string $day, int $requests, string $opportunity = Opportunity::PAGE ): void {
		$this->rollups->add( $day, $placement, array( Decision_Outcome::REQUEST => $requests ), $opportunity );
	}

	public function test_a_day_the_placement_existed_and_produced_nothing_is_a_zero(): void {
		$placement = $this->placement();

		// Busy on two days out of the last ten; silent on the other eight.
		$this->record( $placement, $this->day( 1 ), 1000 );
		$this->record( $placement, $this->day( 2 ), 1000 );

		$observed = $this->history->observed( $placement, Opportunity::PAGE, 10 );

		$this->assertCount(
			10,
			$observed,
			'The counters write no row for a quiet day, so a series built from rows alone contains only the busy days — and a quantile over only the busy days describes a placement that is busy every day.'
		);
		$this->assertSame( 0, $observed[ $this->day( 5 ) ] );
		$this->assertSame( 1000, $observed[ $this->day( 1 ) ] );
	}

	public function test_a_placement_that_serves_two_days_a_week_is_not_sold_as_though_it_serves_seven(): void {
		$placement = $this->placement();

		for ( $ago = 0; $ago < 30; $ago++ ) {
			if ( 0 === $ago % 3 ) {
				$this->record( $placement, $this->day( $ago ), 900 );
			}
		}

		$forecast = $this->history->forecast( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ), 30 );

		$this->assertNotNull( $forecast['estimate'] );
		$this->assertSame(
			0,
			$forecast['estimate'],
			'Two thirds of days produced nothing, so the twentieth percentile of a real week is nought — which is the honest answer and the one a mean would have hidden.'
		);
	}

	public function test_days_before_the_placement_existed_are_not_counted_as_empty(): void {
		$placement = $this->placement( 5 );

		for ( $ago = 0; $ago < 4; $ago++ ) {
			$this->record( $placement, $this->day( $ago ), 500 );
		}

		$observed = $this->history->observed( $placement, Opportunity::PAGE, 90 );

		$this->assertLessThanOrEqual(
			6,
			count( $observed ),
			'Reading back ninety days would zero-fill eighty-odd days the placement did not exist for, and hold a new placement at nought for as long as the history window is wide.'
		);

		foreach ( array_keys( $observed ) as $day ) {
			$this->assertGreaterThanOrEqual(
				gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ),
				$day,
				'Days before the placement was made are not evidence of an empty placement; they are not evidence of anything.'
			);
		}
	}

	public function test_the_open_day_is_left_out_of_history(): void {
		$placement = $this->placement();
		$today     = gmdate( 'Y-m-d' );

		$this->record( $placement, $today, 7 );

		for ( $ago = 0; $ago < 10; $ago++ ) {
			$this->record( $placement, $this->day( $ago ), 500 );
		}

		$observed = $this->history->observed( $placement, Opportunity::PAGE, 10 );

		$this->assertArrayNotHasKey(
			$today,
			$observed,
			'Today is partial by definition, and a half-day counted whole is the lowest number in the series — exactly the number a conservative estimate reaches for.'
		);
	}

	public function test_a_refresh_never_becomes_page_supply(): void {
		$placement = $this->placement();

		for ( $ago = 0; $ago < 10; $ago++ ) {
			$this->record( $placement, $this->day( $ago ), 10, Opportunity::PAGE );
			$this->record( $placement, $this->day( $ago ), 9000, Opportunity::REFRESH );
		}

		$page = $this->history->observed( $placement, Opportunity::PAGE, 10 );

		$this->assertSame(
			array_fill_keys( array_keys( $page ), 10 ),
			$page,
			'Summing the kinds puts a rotation timer back into the supply figure, and a forecast built that way promises inventory a timer invented.'
		);

		$refresh = $this->history->observed( $placement, Opportunity::REFRESH, 10 );

		$this->assertSame( 9000, $refresh[ $this->day( 1 ) ] );
	}

	public function test_only_requests_are_supply(): void {
		$placement = $this->placement();

		for ( $ago = 0; $ago < 10; $ago++ ) {
			$this->rollups->add(
				$this->day( $ago ),
				$placement,
				array(
					Decision_Outcome::REQUEST      => 100,
					Decision_Outcome::FILL         => 60,
					No_Fill_Reason::ALL_INELIGIBLE => 40,
				),
				Opportunity::PAGE
			);
		}

		$observed = $this->history->observed( $placement, Opportunity::PAGE, 10 );

		$this->assertSame(
			100,
			$observed[ $this->day( 3 ) ],
			'Supply is what was asked for. Fills and no-fill reasons describe what happened to that supply, and summing them would count every opportunity twice.'
		);
	}

	public function test_another_placements_traffic_is_not_this_ones_supply(): void {
		$quiet = $this->placement();
		$busy  = $this->placement();

		for ( $ago = 0; $ago < 10; $ago++ ) {
			$this->record( $busy, $this->day( $ago ), 5000 );
		}

		$observed = $this->history->observed( $quiet, Opportunity::PAGE, 10 );

		$this->assertSame( array_fill_keys( array_keys( $observed ), 0 ), $observed );
	}

	public function test_a_placement_with_no_history_forecasts_nothing(): void {
		$forecast = $this->history->forecast( $this->placement( 0 ), Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ) );

		$this->assertNull(
			$forecast['estimate'],
			'A placement made today has no evidence behind it, and zero would refuse a booking on the strength of none.'
		);
		$this->assertSame( Supply_Forecast::CONFIDENCE_NONE, $forecast['confidence'] );
	}

	public function test_a_window_the_domain_refuses_answers_nothing_rather_than_zero(): void {
		$placement = $this->placement();

		for ( $ago = 0; $ago < 30; $ago++ ) {
			$this->record( $placement, $this->day( $ago ), 500 );
		}

		$reversed = $this->history->forecast( $placement, Opportunity::PAGE, $this->day( -7 ), $this->day( -1 ) );

		$this->assertNull( $reversed['estimate'] );
		$this->assertSame( 0, $reversed['days_forecast'] );
		$this->assertGreaterThan(
			0,
			$reversed['days_observed'],
			'The history was read and is sound; it is the window that was refused, and the result has to say which.'
		);
	}

	public function test_the_forecast_scales_with_the_window(): void {
		$placement = $this->placement();

		for ( $ago = 0; $ago < 60; $ago++ ) {
			$this->record( $placement, $this->day( $ago ), 400 );
		}

		$week      = $this->history->forecast( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -7 ), 60 );
		$fortnight = $this->history->forecast( $placement, Opportunity::PAGE, $this->day( -1 ), $this->day( -14 ), 60 );

		$this->assertSame( 7, $week['days_forecast'] );
		$this->assertSame( 14, $fortnight['days_forecast'] );
		$this->assertSame( 2 * (int) $week['estimate'], (int) $fortnight['estimate'] );
	}

	public function test_the_container_supplies_the_service(): void {
		$this->assertInstanceOf(
			Supply_History::class,
			Plugin::instance()->container()->get( Supply_History::class ),
			'A factory that throws on boot is the failure mode registration has; nothing else proves the wiring.'
		);
	}

	public function test_an_unknown_placement_has_no_history(): void {
		$this->assertSame( array(), $this->history->observed( 0, Opportunity::PAGE ) );
		$this->assertSame( array(), $this->history->observed( 999999, Opportunity::PAGE ) );
	}

	public function test_an_unknown_opportunity_kind_has_no_history(): void {
		$placement = $this->placement();

		$this->record( $placement, $this->day( 1 ), 500 );

		$this->assertSame( array(), $this->history->observed( $placement, 'invented' ) );
	}
}
