<?php
/**
 * Native rollup reporting against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Reporting_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Portal\Report_Actions;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Rollup_Report_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Reporting_Read;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Org isolation is SQL, and zeros are omitted when the surface is off.
 */
final class ReportingTest extends WP_UnitTestCase {

	/**
	 * Portal assembler.
	 *
	 * @var View_Data
	 */
	private View_Data $view;

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Rollup writes.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * Org-scoped, range-bounded reads.
	 *
	 * @var Rollup_Report_Repository
	 */
	private Rollup_Report_Repository $reports;

	/**
	 * Organization A.
	 *
	 * @var int
	 */
	private int $org_a;

	/**
	 * Organization B.
	 *
	 * @var int
	 */
	private int $org_b;

	/**
	 * Advertiser in org A.
	 *
	 * @var int
	 */
	private int $advertiser_a;

	/**
	 * Advertiser in org B.
	 *
	 * @var int
	 */
	private int $advertiser_b;

	/**
	 * Campaign in org A.
	 *
	 * @var int
	 */
	private int $campaign_a;

	/**
	 * Campaign in org B.
	 *
	 * @var int
	 */
	private int $campaign_b;

	/**
	 * Placement used only as a rollup slot.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Two organizations, delivery tables, and a placement to hang counters on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$installer = new Installer( new Audit_Repository(), new Roles() );
		$installer->install_roles();
		$installer->install_delivery_tables();

		$container          = Plugin::instance()->container();
		$this->view         = $container->get( View_Data::class );
		$this->settings     = $container->get( Settings::class );
		$this->rollups      = $container->get( Rollup_Repository::class );
		$this->reports      = $container->get( Rollup_Report_Repository::class );
		$this->advertiser_a = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->advertiser_b = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_a        = $this->make_org( $this->advertiser_a, 'Org A' );
		$this->org_b        = $this->make_org( $this->advertiser_b, 'Org B' );
		$this->campaign_a   = $this->make_campaign( $this->org_a, 'A flight' );
		$this->campaign_b   = $this->make_campaign( $this->org_b, 'B flight' );
		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$container->get( Ownership::class )->flush_cache();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Settings and range parameters must not leak into later tests.
	 *
	 * The range is read from `$_GET` and memoized per instance, so a test that
	 * left one behind would silently narrow every window a later test reports —
	 * the sort of cross-test coupling that produces a failure in the wrong file.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );
		unset( $_GET['from'], $_GET['to'], $_GET['days'] );
		parent::tear_down();
	}

	/**
	 * Reporting without the tiles switch still omits metrics. Native fill
	 * is always on and is not a second gate.
	 *
	 * @return void
	 */
	public function test_reporting_off_omits_metrics(): void {
		$this->bump( $this->campaign_a, 4, 1 );
		$this->enable_reporting( false );

		wp_set_current_user( $this->advertiser_a );

		$this->assertSame( array(), $this->view->delivery_counts() );
		$this->assertSame( array(), $this->view->delivery_series() );
		$this->assertArrayNotHasKey( 'impressions', $this->view->campaign( $this->campaign_a ) );
		$this->assertArrayNotHasKey( 'ctr', $this->view->campaign( $this->campaign_a ) );
	}

	/**
	 * Dashboard totals are the caller's organization, including campaigns not
	 * on the current list page, and never house or another tenant.
	 *
	 * @return void
	 */
	public function test_org_totals_are_sql_scoped_and_exclude_house(): void {
		$this->bump( $this->campaign_a, 5, 2 );
		$this->bump( $this->campaign_b, 80, 9 );
		$this->bump( 0, 50, 7 );
		$this->enable_reporting( true );

		wp_set_current_user( $this->advertiser_a );

		$counts = $this->view->delivery_counts();

		$this->assertSame( 'Impressions', $counts[0]['label'] );
		$this->assertSame( '5', $counts[0]['value'] );
		$this->assertSame( '2', $counts[1]['value'] );
		$this->assertSame( '40.0%', $counts[2]['value'] );

		wp_set_current_user( $this->advertiser_b );

		$theirs = $this->view->delivery_counts();

		$this->assertSame( '80', $theirs[0]['value'] );
		$this->assertSame( '9', $theirs[1]['value'] );
	}

	/**
	 * Campaign rows carry only that campaign's counters.
	 *
	 * @return void
	 */
	public function test_campaign_metrics_do_not_include_another_org(): void {
		$this->bump( $this->campaign_a, 3, 1 );
		$this->bump( $this->campaign_b, 99, 40 );
		$this->enable_reporting( true );

		wp_set_current_user( $this->advertiser_a );

		$mine = $this->view->campaign( $this->campaign_a );
		$peek = $this->view->campaign( $this->campaign_b );

		$this->assertIsArray( $mine );
		$this->assertSame( 3, $mine['impressions'] );
		$this->assertSame( 1, $mine['clicks'] );
		$this->assertSame( Reporting_Rules::ctr( 3, 1 ), $mine['ctr'] );
		$this->assertNull( $peek );
	}

	/**
	 * REST includes counts for owned campaigns and still 404s a foreign one.
	 *
	 * @return void
	 */
	public function test_rest_metrics_follow_ownership_and_the_module_gate(): void {
		$this->bump( $this->campaign_a, 6, 3 );
		$this->bump_conversions( $this->campaign_a, 2 );
		$this->bump( $this->campaign_b, 20, 4 );
		$this->enable_reporting( true );

		wp_set_current_user( $this->advertiser_a );

		$owned    = $this->get( '/campaigns/' . $this->campaign_a );
		$foreign  = $this->get( '/campaigns/' . $this->campaign_b );
		$listing  = $this->get( '/campaigns' )->get_data();
		$listed_a = $listing['campaigns'][0];

		$this->assertSame( 200, $owned->get_status() );
		$this->assertSame( 6, $owned->get_data()['impressions'] );
		$this->assertSame( 3, $owned->get_data()['clicks'] );
		$this->assertSame( 0.5, $owned->get_data()['ctr'] );

		/*
		 * Conversions on the route, not only through the reader that feeds it.
		 * The exit criterion names `GET /campaigns`, and a claim about a route
		 * wants evidence at the route.
		 */
		$this->assertSame( 2, $owned->get_data()['conversions'] );
		$this->assertSame( 404, $foreign->get_status() );
		$this->assertArrayNotHasKey( 'impressions', $foreign->get_data() );
		$this->assertArrayNotHasKey( 'ctr', $foreign->get_data() );
		$this->assertArrayNotHasKey( 'conversions', $foreign->get_data() );
		$this->assertSame( $this->campaign_a, $listed_a['id'] );
		$this->assertSame( 6, $listed_a['impressions'] );
		$this->assertSame( 3, $listed_a['clicks'] );
		$this->assertSame( 0.5, $listed_a['ctr'] );
		$this->assertSame( 2, $listed_a['conversions'] );

		$this->enable_reporting( false );
		wp_set_current_user( $this->advertiser_a );

		$off = $this->get( '/campaigns/' . $this->campaign_a )->get_data();

		$this->assertArrayNotHasKey( 'impressions', $off );
		$this->assertArrayNotHasKey( 'clicks', $off );
		$this->assertArrayNotHasKey( 'ctr', $off );
		$this->assertArrayNotHasKey( 'conversions', $off );
	}

	/**
	 * The sparkline covers the chosen window, org-scoped, padding empty days.
	 *
	 * **It follows the tiles rather than keeping a length of its own.** A page
	 * whose figures covered one window and whose chart covered another would
	 * invite the reader to compare them, and every such comparison would be
	 * wrong. The chart was a fixed seven days until the window became the
	 * reader's to choose.
	 *
	 * @return void
	 */
	public function test_series_covers_the_window_and_pads_missing_days(): void {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

		$this->bump( $this->campaign_a, 4, 0, $yesterday );
		$this->bump( $this->campaign_b, 90, 0, $today );
		$this->bump( 0, 30, 0, $today );
		$this->enable_reporting( true );

		wp_set_current_user( $this->advertiser_a );

		$series = $this->view->delivery_series();
		$last   = array_key_last( $series );

		$this->assertCount( Reporting_Read::DEFAULT_DAYS, $series, 'The chart did not cover the window the tiles report.' );
		$this->assertSame( $yesterday, $series[ $last - 1 ]['day'] );
		$this->assertSame( 4, $series[ $last - 1 ]['impressions'] );
		$this->assertSame( 100, $series[ $last - 1 ]['height'] );
		$this->assertSame( $today, $series[ $last ]['day'] );
		$this->assertSame( 0, $series[ $last ]['impressions'], 'Another organization’s delivery reached this chart.' );
		$this->assertSame( 0, $series[ $last ]['height'] );

		wp_set_current_user( $this->advertiser_b );

		$theirs = $this->view->delivery_series();

		$this->assertSame( 90, $theirs[ $last ]['impressions'] );
		$this->assertSame( 0, $theirs[ $last - 1 ]['impressions'] );
	}

	/**
	 * Direct rollup reads for another org's id still cannot be reached through
	 * the org-scoped query used by the dashboard.
	 *
	 * @return void
	 */
	public function test_totals_for_org_ignores_unrelated_campaign_rows(): void {
		$this->bump( $this->campaign_a, 2, 1 );
		$this->bump( $this->campaign_b, 50, 10 );

		$mine = $this->reports->totals_for_org( $this->org_a, Report_Period::trailing( 30, gmdate( 'Y-m-d' ) ) );

		$this->assertSame( 2, $mine['impressions'] );
		$this->assertSame( 1, $mine['clicks'] );
	}

	/**
	 * **House inventory is excluded by two independent guards, not one.**
	 *
	 * `FrozenTenancyTest` asserts a house delivery never lands in an org total,
	 * and it passes whether or not the `campaign_id > 0` predicate exists —
	 * because a house row also carries `org_id = 0`, so the tenancy filter
	 * alone already excludes it. Mutation testing found that: relaxing the
	 * predicate to `>= 0` changed nothing anywhere.
	 *
	 * The predicate is defence in depth against a row whose tenancy was written
	 * wrongly, which is the only way a house row could ever reach an org total.
	 * This seeds exactly that row — `campaign_id = 0` carrying a real `org_id`,
	 * which `Event_Recorder` will not produce and a bug might — and asserts the
	 * second guard holds on its own.
	 *
	 * @return void
	 */
	public function test_a_house_row_with_a_tenant_is_still_excluded(): void {
		global $wpdb;

		$this->enable_reporting( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding the corrupt row this guard exists for; no repository will write it.
		$wpdb->insert(
			$this->rollups->table_name(),
			array(
				'day_utc'      => gmdate( 'Y-m-d' ),
				'placement_id' => $this->placement_id,
				'campaign_id'  => 0,
				'line_item_id' => 0,
				'org_id'       => $this->org_a,
				'impressions'  => 500,
			)
		);

		$totals = $this->reports->totals_for_org( $this->org_a, Report_Period::trailing( 30, gmdate( 'Y-m-d' ) ) );

		$this->assertSame(
			0,
			$totals['impressions'],
			'House inventory carrying a tenant id was billed to that advertiser.'
		);
	}

	/**
	 * A range in the query string is what the whole page reports on.
	 *
	 * Tiles, chart, caption and export all resolve one request, so the page
	 * cannot end up disagreeing with itself about which days it is describing.
	 *
	 * @return void
	 */
	public function test_a_requested_range_is_what_the_page_reports(): void {
		$this->enable_reporting( true );
		wp_set_current_user( $this->advertiser_a );

		$_GET['from'] = '2026-08-01';
		$_GET['to']   = '2026-08-07';

		$window = $this->view->delivery_window();

		$this->assertSame( '2026-08-01', $window['from'] );
		$this->assertSame( '2026-08-07', $window['to'] );
		$this->assertSame( 7, $window['days'] );
		$this->assertFalse( $window['rejected'] );
		$this->assertCount( 7, $this->view->delivery_series(), 'The chart covers a different window than the tiles.' );
	}

	/**
	 * **A range that cannot be used is refused, and the page says so.**
	 *
	 * Falling back is not enough on its own. A screen that quietly showed the
	 * last thirty days after being asked for something else looks authoritative
	 * and has answered a question nobody put to it — which is exactly why
	 * `Report_Period` refuses rather than clamps.
	 *
	 * @return void
	 */
	public function test_an_impossible_range_is_refused_and_reported(): void {
		$this->enable_reporting( true );
		wp_set_current_user( $this->advertiser_a );

		$_GET['from'] = '2026-01-01';
		$_GET['to']   = '2026-12-31';

		$window = $this->view->delivery_window();

		$this->assertTrue( $window['rejected'], 'A year-long range was accepted by a screen bounded to 92 days.' );
		$this->assertSame( Reporting_Read::DEFAULT_DAYS, $window['days'] );
	}

	/**
	 * The export follows the window, truncated to what it can assemble.
	 *
	 * The two caps differ because they answer different constraints — what a
	 * read may examine, and what a document may hold in memory — so a 90-day
	 * window offers a 31-day download, and the button names that number rather
	 * than the window's.
	 *
	 * @return void
	 */
	public function test_the_export_follows_the_window_and_names_what_it_will_produce(): void {
		$this->enable_reporting( true );
		wp_set_current_user( $this->advertiser_a );

		$_GET['days'] = 90;

		$window = $this->view->delivery_window();

		$this->assertSame( 90, $window['days'] );
		$this->assertSame( Report_Actions::MAX_DAYS, $window['export_days'], 'The export offered more than it can assemble.' );
		$this->assertSame( $window['to'], $window['export_to'], 'The export dropped the most recent days rather than the oldest.' );
	}

	/**
	 * Each tile carries its change against the equal window before it.
	 *
	 * Read through the production view data rather than the repository, because
	 * what this asserts is that two bounded reads reach one tile — the earlier
	 * mistake available here was comparing a window against itself.
	 *
	 * @return void
	 */
	public function test_the_tiles_compare_against_the_previous_window(): void {
		$this->enable_reporting( true );

		// Forty days back is inside the comparison window and outside the
		// reported one, which is the whole point of the arrangement.
		$this->bump( $this->campaign_a, 100, 4, gmdate( 'Y-m-d', strtotime( '-40 days' ) ) );
		$this->bump( $this->campaign_a, 150, 1 );
		wp_set_current_user( $this->advertiser_a );

		$tiles = array();

		foreach ( $this->view->delivery_counts() as $tile ) {
			$tiles[ (string) $tile['label'] ] = $tile;
		}

		$this->assertSame( '150', $tiles['Impressions']['value'] );
		$this->assertStringContainsString( '50.0%', (string) $tiles['Impressions']['change'], 'The tile did not compare against the previous window.' );
		$this->assertSame( 'up', $tiles['Impressions']['direction'] );

		/*
		 * The sign is in the text, not only in the class. A reader in high
		 * contrast or monochrome must still be able to tell a rise from a fall,
		 * so the direction class may never be the only carrier.
		 */
		$this->assertStringContainsString( '+', (string) $tiles['Impressions']['change'] );

		/*
		 * And the same in the other direction, because the colour is the half a
		 * reader may not have. Clicks fell from four to one over the same two
		 * windows, and the tile must say so in characters.
		 */
		$this->assertSame( 'down', $tiles['Clicks']['direction'] );
		$this->assertStringContainsString( "\u{2212}", (string) $tiles['Clicks']['change'], 'A decline was carried only by the direction class.' );
		$this->assertStringContainsString( '75.0%', (string) $tiles['Clicks']['change'] );
	}

	/**
	 * Nothing before the window means no comparison, not a total collapse.
	 *
	 * A first-week advertiser has an empty previous window, and every change
	 * from nothing is infinite. Rendering that as a percentage would put a
	 * number on the dashboard that means nothing and reads as alarming.
	 *
	 * @return void
	 */
	public function test_an_empty_previous_window_produces_no_comparison(): void {
		$this->enable_reporting( true );
		$this->bump( $this->campaign_a, 25, 3 );
		wp_set_current_user( $this->advertiser_a );

		$tiles = array();

		foreach ( $this->view->delivery_counts() as $tile ) {
			$tiles[ (string) $tile['label'] ] = $tile;
		}

		$this->assertSame( '', $tiles['Impressions']['change'], 'A comparison was drawn against a window with nothing in it.' );
		$this->assertSame( '', $tiles['Impressions']['direction'] );

		// And the metric nobody was measuring is not reported as a decline.
		$this->assertSame( '', $tiles['Conversions']['change'], 'An unmeasured metric was given a comparison.' );
	}

	/**
	 * **P12 counted conversions and nothing showed them.**
	 *
	 * Ingestion, deduplication, attribution and a definitions screen all
	 * shipped; `aggr_rollups.conversions` has been written, reconciled and
	 * retained ever since, and appeared in no tile, no table, no CSV column and
	 * no REST field. A feature that runs and cannot be seen is indistinguishable
	 * from one that was never built.
	 *
	 * @return void
	 */
	public function test_a_counted_conversion_reaches_the_tiles_and_the_campaign_row(): void {
		$this->enable_reporting( true );
		$this->bump( $this->campaign_a, 10, 2 );
		$this->bump_conversions( $this->campaign_a, 3 );
		wp_set_current_user( $this->advertiser_a );

		$totals = $this->reports->totals_for_org( $this->org_a, Report_Period::trailing( 30, gmdate( 'Y-m-d' ) ) );

		$this->assertSame( 3, $totals['conversions'] );

		$labels = array();

		foreach ( $this->view->delivery_counts() as $tile ) {
			$labels[ (string) $tile['label'] ] = (string) $tile['value'];
		}

		$this->assertArrayHasKey( 'Conversions', $labels, 'The dashboard has no conversions tile.' );
		$this->assertSame( '3', $labels['Conversions'] );

		$row = $this->reporting_read()->attach_one( array( 'id' => $this->campaign_a ) );

		$this->assertSame( 3, $row['conversions'], 'A campaign row carried no conversion count.' );
	}

	/**
	 * An unmeasured campaign reports null, and the tile says so in words.
	 *
	 * Zero would claim the campaign delivered and converted nobody. Every
	 * campaign that ran before conversion tracking is in this state, so getting
	 * it wrong misreports all of history rather than an edge case.
	 *
	 * @return void
	 */
	public function test_an_unmeasured_conversion_is_not_a_zero(): void {
		$this->enable_reporting( true );
		$this->bump( $this->campaign_a, 10, 2 );
		wp_set_current_user( $this->advertiser_a );

		$totals = $this->reports->totals_for_org( $this->org_a, Report_Period::trailing( 30, gmdate( 'Y-m-d' ) ) );

		$this->assertNull( $totals['conversions'], 'An unmeasured period was reported as zero conversions.' );

		$row = $this->reporting_read()->attach_one( array( 'id' => $this->campaign_a ) );

		$this->assertNull( $row['conversions'], 'An unmeasured campaign was reported as zero conversions.' );

		$labels = array();

		foreach ( $this->view->delivery_counts() as $tile ) {
			$labels[ (string) $tile['label'] ] = (string) $tile['value'];
		}

		$this->assertSame( 'Not measured', $labels['Conversions'] );
	}

	/**
	 * Reporting off leaves the conversion field absent, not zero.
	 *
	 * The gate applies to every metric identically. A client that received
	 * `conversions: 0` from a site with reporting switched off would have been
	 * told something untrue about the campaign rather than nothing about it.
	 *
	 * @return void
	 */
	public function test_conversions_are_absent_while_reporting_is_off(): void {
		$this->bump( $this->campaign_a, 10, 2 );
		$this->bump_conversions( $this->campaign_a, 3 );
		$this->enable_reporting( false );
		wp_set_current_user( $this->advertiser_a );

		$row = $this->reporting_read()->attach_one( array( 'id' => $this->campaign_a ) );

		$this->assertArrayNotHasKey( 'conversions', $row );
		$this->assertSame( array(), $this->view->delivery_counts() );
	}

	/**
	 * The tiles sum the reporting window, not everything ever delivered.
	 *
	 * **This is the behaviour change P14 made, and the one worth a test.** The
	 * tiles used to be all-time totals, which cost a scan of the whole
	 * organization's history on every page load and, less visibly, meant a
	 * campaign that ran last year kept inflating a figure a reader took for
	 * current. A delivery outside the window must be absent from the number and
	 * still present in the table it was written to.
	 */
	public function test_the_delivery_tiles_cover_only_the_reporting_window(): void {
		$this->bump( $this->campaign_a, 3, 1 );
		$this->bump( $this->campaign_a, 90, 40, gmdate( 'Y-m-d', strtotime( '-60 days' ) ) );

		$window = $this->reports->totals_for_org( $this->org_a, Report_Period::trailing( 30, gmdate( 'Y-m-d' ) ) );

		$this->assertSame( 3, $window['impressions'], 'A delivery from outside the window was counted in it.' );
		$this->assertSame( 1, $window['clicks'] );

		// And the older delivery is still on the books, so this is a bounded
		// read rather than lost history. Sixty days back is inside the longest
		// range a report may cover and outside the window the tiles use, which
		// is the whole distinction being asserted.
		$wider = $this->reports->totals_for_org( $this->org_a, Report_Period::trailing( 92, gmdate( 'Y-m-d' ) ) );

		$this->assertSame( 93, $wider['impressions'], 'The older delivery was not merely out of range, it was gone.' );
	}

	/**
	 * The dashboard says which window it covers, and that the days are UTC.
	 */
	public function test_the_dashboard_names_its_window_and_its_timezone(): void {
		$this->enable_reporting( true );

		$label  = $this->view->delivery_range_label();
		$window = $this->view->delivery_window();

		$this->assertStringContainsString( 'UTC', $label, 'The tiles do not say the days are UTC, which they are.' );
		$this->assertSame( gmdate( 'Y-m-d' ), $window['to'], 'The default window does not end today.' );
		$this->assertSame( Reporting_Read::DEFAULT_DAYS, $window['days'] );
		$this->assertFalse( $window['rejected'], 'A request with no range was treated as a refused one.' );

		/*
		 * The label names the dates rather than a length, now that the reader
		 * chooses the window: "last 30 days" over a range somebody typed in
		 * August would be worse than no label at all.
		 */
		$this->assertStringContainsString( (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $window['from'] . ' UTC' ) ), $label );
	}

	/**
	 * Freshness names the first day that may still move, and says nothing when
	 * every day in range is settled.
	 */
	public function test_freshness_names_the_boundary_and_stays_quiet_when_settled(): void {
		$this->enable_reporting( true );

		$delivery = Plugin::instance()->container()->get( Delivery_View_Data::class );

		update_option( Rollup_Reconciler::OPTION, gmdate( 'Y-m-d', strtotime( '-3 days' ) ), false );

		$note = $delivery->freshness_note();

		$this->assertNotSame( '', $note, 'A window running past the watermark claimed to be settled.' );

		/*
		 * A window that ends before the watermark is fully rebuilt from the
		 * ledger, so there is nothing to warn about. An empty string here is
		 * the assertion that the note is derived rather than always printed.
		 */
		$sealed = Report_Period::ending( 7, gmdate( 'Y-m-d', strtotime( '-10 days' ) ) );

		$this->assertNotNull( $sealed );
		$this->assertSame( '', $delivery->freshness_note( $sealed ), 'A fully reconciled window still carried a warning.' );
	}

	/**
	 * Dispatches a GET.
	 *
	 * @param string $route Route path.
	 * @return \WP_REST_Response
	 */
	private function get( string $route ): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/aggr/v1' . $route ) );
	}

	/**
	 * Turns advertiser metric tiles on or off.
	 *
	 * @param bool $reporting Reporting module.
	 */
	private function enable_reporting( bool $reporting ): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_REPORTING ] = $reporting;

		$this->assertTrue( $this->settings->save( $document ) );
	}

	/**
	 * Increments today's counters.
	 *
	 * @param int    $campaign_id Campaign id, or 0 for house.
	 * @param int    $impressions Impression count.
	 * @param int    $clicks      Click count.
	 * @param string $day_utc     Optional UTC Y-m-d. Empty uses today.
	 */
	private function bump( int $campaign_id, int $impressions, int $clicks, string $day_utc = '' ): void {
		/*
		 * Resolved the way `Event_Recorder` resolves it, because `org_id` is
		 * frozen onto the row at write time and org-scoped reads filter on it.
		 * A fixture that left it at 0 would write rows no report can see, and
		 * the tests below would fail for a reason unrelated to what they name.
		 */
		$org_id = $campaign_id > 0
			? (int) get_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, true )
			: 0;

		for ( $i = 0; $i < $impressions; $i++ ) {
			$this->rollups->increment( 'impressions', $this->placement_id, $campaign_id, $day_utc, 0, $org_id );
		}

		for ( $i = 0; $i < $clicks; $i++ ) {
			$this->rollups->increment( 'clicks', $this->placement_id, $campaign_id, $day_utc, 0, $org_id );
		}
	}

	/**
	 * An organization owned by one user.
	 *
	 * @param int    $owner Owning user id.
	 * @param string $name  Display name.
	 */
	private function make_org( int $owner, string $name ): int {
		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);

		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $owner );

		return $org_id;
	}

	/**
	 * A live campaign in an organization.
	 *
	 * @param int    $org_id Owning organization.
	 * @param string $title  Campaign title.
	 */
	private function make_campaign( int $org_id, string $title ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
				'post_title'  => $title,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $org_id );

		return $campaign_id;
	}

	/**
	 * The viewability tile tells three answers apart.
	 *
	 * A day nobody measured, a day measured with nothing seen, and a real rate
	 * are three different facts, and collapsing any two of them misleads. Zero
	 * per cent is the one worth investigating — usually the script is not
	 * running — and it is indistinguishable from history if unmeasured days
	 * report zero too.
	 *
	 * @return void
	 */
	public function test_the_viewability_tile_separates_unmeasured_from_zero(): void {
		$this->enable_reporting( true );
		wp_set_current_user( $this->advertiser_a );

		// Impressions recorded before viewability existed: the column is NULL.
		$this->bump( $this->campaign_a, 4, 0 );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recreating a pre-P11 row in this plugin's own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET viewables = NULL WHERE campaign_id = %d',
				$this->rollups->table_name(),
				$this->campaign_a
			)
		);

		$tile = $this->view->delivery_counts()[3];

		$this->assertSame( 'Viewable', $tile['label'] );
		$this->assertSame(
			'Not measured',
			$tile['value'],
			'History was reported as a viewability rate rather than as unmeasured.'
		);

		// Now measured, and nothing seen.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same fixture.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET viewables = 0 WHERE campaign_id = %d',
				$this->rollups->table_name(),
				$this->campaign_a
			)
		);

		$this->assertSame(
			'0.0%',
			$this->view->delivery_counts()[3]['value'],
			'A measured day with no views must read zero, which is the number worth chasing.'
		);

		// And a real rate.
		$this->bump_viewables( $this->campaign_a, 1 );

		$this->assertSame(
			'25.0%',
			$this->view->delivery_counts()[3]['value']
		);
	}

	/**
	 * Records attributed conversions against a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $conversions How many conversions to record.
	 * @return void
	 */
	private function bump_conversions( int $campaign_id, int $conversions ): void {
		$org_id = $campaign_id > 0
			? (int) get_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, true )
			: 0;

		for ( $i = 0; $i < $conversions; $i++ ) {
			$this->rollups->increment( 'conversions', $this->placement_id, $campaign_id, '', 0, $org_id );
		}
	}

	/**
	 * The reporting gate and reads, from the container.
	 */
	private function reporting_read(): \Aggressive\Ads\Workflow\Reporting_Read {
		return Plugin::instance()->container()->get( \Aggressive\Ads\Workflow\Reporting_Read::class );
	}

	/**
	 * Records views against a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $viewables   How many views to record.
	 * @return void
	 */
	private function bump_viewables( int $campaign_id, int $viewables ): void {
		$org_id = $campaign_id > 0
			? (int) get_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, true )
			: 0;

		for ( $i = 0; $i < $viewables; $i++ ) {
			$this->rollups->increment( 'viewables', $this->placement_id, $campaign_id, '', 0, $org_id );
		}
	}
}
