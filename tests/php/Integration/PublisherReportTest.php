<?php
/**
 * The publisher's answer to "why is this slot empty".
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Report_Data;
use Aggressive\Ads\Admin\Report_Export;
use Aggressive\Ads\Admin\Reports_Screen;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Rollup_Reconciler;
use WP_UnitTestCase;

/**
 * P13 stored these counters and deliberately built nothing that reads them.
 *
 * Its own exit criterion was narrowed at closeout to say so: the data exists,
 * bounded and indexed and reconcilable, and showing it is this phase's job.
 * These are the tests for the showing.
 */
final class PublisherReportTest extends WP_UnitTestCase {

	/**
	 * The role matrix version that shipped immediately before this capability.
	 *
	 * A literal, deliberately. See the upgrade test below for what the obvious
	 * `Roles::VERSION - 1` cost.
	 */
	private const ROLES_VERSION_BEFORE = 3;

	/**
	 * Screen under test.
	 *
	 * @var Reports_Screen
	 */
	private Reports_Screen $screen;

	/**
	 * Assembler under test.
	 *
	 * @var Report_Data
	 */
	private Report_Data $data;

	/**
	 * Counter writes.
	 *
	 * @var Decision_Rollup_Repository
	 */
	private Decision_Rollup_Repository $rollups;

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Export under test.
	 *
	 * @var Report_Export
	 */
	private Report_Export $export;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$this->screen   = $container->get( Reports_Screen::class );
		$this->data     = $container->get( Report_Data::class );
		$this->rollups  = $container->get( Decision_Rollup_Repository::class );
		$this->export   = $container->get( Report_Export::class );
		$this->settings = $container->get( Settings::class );

		$this->rollups->install_table();
		$this->enable_reporting( true );
	}

	/**
	 * Filter parameters must not leak into later tests.
	 *
	 * They are read from `$_GET`, so one left behind would quietly filter a
	 * later test's report and fail it somewhere unrelated.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['placement'], $_GET['days'] );
		parent::tear_down();
	}

	/**
	 * **Every reason the engine can record has a sentence.**
	 *
	 * A code is never rendered raw: `targeting_mismatch` tells a publisher
	 * nothing they can act on. Derived from the vocabulary rather than listed
	 * by hand, so a reason added to `No_Fill_Reason` without a label here fails
	 * rather than reaching a screen as a slug.
	 *
	 * @return void
	 */
	public function test_every_opportunity_kind_has_a_label(): void {
		$labels = Report_Data::opportunity_labels();

		foreach ( Opportunity::all() as $kind ) {
			$this->assertArrayHasKey( $kind, $labels, sprintf( 'No sentence for the inventory kind "%s".', $kind ) );
			$this->assertNotSame( $kind, $labels[ $kind ], 'A kind is being rendered as its own code.' );
			$this->assertNotSame( '', trim( $labels[ $kind ] ) );
		}
	}

	public function test_every_reason_code_has_a_label(): void {
		$labels = Report_Data::reason_labels();

		foreach ( No_Fill_Reason::all() as $code ) {
			$this->assertArrayHasKey( $code, $labels, sprintf( 'No sentence for the reason code "%s".', $code ) );
			$this->assertNotSame( $code, $labels[ $code ], 'A reason is being rendered as its own code.' );
			$this->assertNotSame( '', trim( $labels[ $code ] ) );
		}
	}

	/**
	 * Fill rate, shares, and the rate that does not exist.
	 *
	 * @return void
	 */
	public function test_fill_figures_are_computed_from_the_counters(): void {
		$today = gmdate( 'Y-m-d' );

		$this->rollups->add(
			$today,
			7,
			array(
				Decision_Outcome::REQUEST          => 100,
				Decision_Outcome::FILL             => 75,
				No_Fill_Reason::TARGETING_MISMATCH => 20,
				No_Fill_Reason::FREQUENCY_CAPPED   => 5,
			)
		);

		$fill = $this->data->fill( $this->data->period( 30 ), 7 );

		$this->assertSame( 100, $fill['requests'] );
		$this->assertSame( 75, $fill['fills'] );
		$this->assertEqualsWithDelta( 0.75, $fill['fill_rate'], 0.0001 );
		$this->assertSame( 0, $fill['unaccounted'], 'Requests did not reconcile against fills plus reasons.' );
		$this->assertCount( 2, $fill['reasons'] );

		$shares = array();

		foreach ( $fill['reasons'] as $reason ) {
			$shares[ $reason['code'] ] = $reason['share'];
		}

		$this->assertEqualsWithDelta( 0.20, $shares[ No_Fill_Reason::TARGETING_MISMATCH ], 0.0001 );
	}

	/**
	 * **A refresh is not a page request, on the surface a publisher reads.**
	 *
	 * The column is stored correctly and then every production reader used to
	 * `SUM` it away. A test that only asked the table would stay green while
	 * the fill report invented supply from a timer.
	 *
	 * @return void
	 */
	public function test_a_refresh_is_not_counted_as_a_page_request(): void {
		$today = gmdate( 'Y-m-d' );

		$this->rollups->add(
			$today,
			7,
			array(
				Decision_Outcome::REQUEST => 10,
				Decision_Outcome::FILL    => 8,
			),
			Opportunity::PAGE
		);
		$this->rollups->add(
			$today,
			7,
			array(
				Decision_Outcome::REQUEST => 40,
				Decision_Outcome::FILL    => 40,
			),
			Opportunity::REFRESH
		);

		$fill = $this->data->fill( $this->data->period( 30 ), 7 );

		$this->assertSame( 10, $fill['requests'], 'Refresh requests were added to page supply.' );
		$this->assertSame( 8, $fill['fills'] );
		$this->assertSame( 40, $fill['refresh']['requests'] );
		$this->assertSame( 40, $fill['refresh']['fills'] );
	}

	/**
	 * A refresh no-fill is explained as a refresh, not absorbed into "every
	 * request was filled" because the page ones were.
	 *
	 * @return void
	 */
	public function test_a_refresh_no_fill_is_explained_as_a_refresh(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$today = gmdate( 'Y-m-d' );

		$this->rollups->add(
			$today,
			7,
			array(
				Decision_Outcome::REQUEST => 10,
				Decision_Outcome::FILL    => 10,
			),
			Opportunity::PAGE
		);
		$this->rollups->add(
			$today,
			7,
			array(
				Decision_Outcome::REQUEST          => 8,
				Decision_Outcome::FILL             => 5,
				No_Fill_Reason::TARGETING_MISMATCH => 3,
			),
			Opportunity::REFRESH
		);

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$labels = Report_Data::reason_labels();

		$this->assertStringContainsString( 'Every page request was filled.', $html );
		$this->assertStringContainsString( 'Why refresh requests were not filled', $html );
		$this->assertStringContainsString( esc_html( $labels[ No_Fill_Reason::TARGETING_MISMATCH ] ), $html );
		$this->assertStringContainsString( 'of refresh requests filled', $html );
		$this->assertStringNotContainsString( 'Every request was filled.', $html );
	}

	/**
	 * Refresh-only traffic is a report, not the empty-state sentence.
	 *
	 * @return void
	 */
	public function test_refresh_only_traffic_is_not_an_empty_report(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->rollups->add(
			gmdate( 'Y-m-d' ),
			7,
			array(
				Decision_Outcome::REQUEST        => 4,
				Decision_Outcome::FILL           => 1,
				No_Fill_Reason::FREQUENCY_CAPPED => 3,
			),
			Opportunity::REFRESH
		);

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$labels = Report_Data::reason_labels();

		$this->assertStringNotContainsString( 'No advertisement was requested in this window', $html );
		$this->assertStringContainsString( 'Why refresh requests were not filled', $html );
		$this->assertStringContainsString( esc_html( $labels[ No_Fill_Reason::FREQUENCY_CAPPED ] ), $html );
		$this->assertStringContainsString( 'No page requests in this window.', $html );
	}

	/**
	 * Nothing asked for is not a zero-percent fill rate.
	 *
	 * A placement nobody requested did not fail to fill, and "0%" is the
	 * alarming reading of a slot that was simply never on a page.
	 *
	 * @return void
	 */
	public function test_a_placement_nobody_requested_has_no_fill_rate(): void {
		$fill = $this->data->fill( $this->data->period( 30 ), 4242 );

		$this->assertSame( 0, $fill['requests'] );
		$this->assertNull( $fill['fill_rate'], 'A slot nobody asked for was reported as failing to fill.' );
		$this->assertSame( array(), $fill['reasons'] );
	}

	/**
	 * A request that is neither a fill nor a reason is surfaced, not absorbed.
	 *
	 * P13's invariant is a property of the decision engine rather than of the
	 * table. A screen that normalised the difference away would hide the one
	 * thing on it worth investigating.
	 *
	 * @return void
	 */
	public function test_requests_with_no_recorded_outcome_are_reported(): void {
		$this->rollups->add(
			gmdate( 'Y-m-d' ),
			9,
			array(
				Decision_Outcome::REQUEST => 50,
				Decision_Outcome::FILL    => 30,
			)
		);

		$fill = $this->data->fill( $this->data->period( 30 ), 9 );

		$this->assertSame( 20, $fill['unaccounted'], 'A gap between requests and outcomes was quietly absorbed.' );
	}

	/**
	 * An out-of-range window is refused rather than answered.
	 *
	 * The value comes from a query string. Clamping it to the nearest legal
	 * range would report a period nobody asked for, which is the failure mode
	 * `Report_Period` exists to prevent.
	 *
	 * @return void
	 */
	public function test_a_window_that_is_not_offered_falls_back_to_the_default(): void {
		$this->assertSame( 30, $this->data->period( 3000 )->days );
		$this->assertSame( 30, $this->data->period( 0 )->days );
		$this->assertSame( 7, $this->data->period( 7 )->days );
		$this->assertContains( $this->data->period( 90 )->days, Report_Data::WINDOWS );
	}

	/**
	 * **The render callback refuses, not only the menu.**
	 *
	 * A hidden menu item is not authorization: `admin.php?page=aggr-reports` is
	 * a URL anybody can type, and WordPress runs the callback for whoever asks.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_reach_the_report_by_url(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$died = false;

		add_filter(
			'wp_die_handler',
			static function () use ( &$died ): callable {
				return static function () use ( &$died ): void {
					$died = true;

					throw new \RuntimeException( 'wp_die' );
				};
			}
		);

		try {
			ob_start();
			$this->screen->render();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			ob_end_clean();
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died, 'An advertiser read the publisher report by typing its URL.' );
	}

	/**
	 * A reviewer holds the new capability, so the role matrix actually granted it.
	 *
	 * A capability nobody holds is a screen nobody can open, and the role
	 * version bump is what delivers it to sites installed under the old matrix.
	 *
	 * @return void
	 */
	public function test_a_reviewer_holds_the_reports_capability(): void {
		$reviewer = (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		wp_set_current_user( $reviewer );

		$this->assertTrue( current_user_can( Capabilities::VIEW_REPORTS ) );

		$this->assertFalse(
			user_can( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ), Capabilities::VIEW_REPORTS ),
			'An advertiser was granted the publisher reporting capability.'
		);
	}

	/**
	 * **An existing site receives the capability through the upgrade, not only a fresh install.**
	 *
	 * The role matrix is applied once at install and re-applied when
	 * `Roles::VERSION` moves. Bumping that constant is the whole delivery
	 * mechanism for a new capability, and nothing had ever asserted it worked —
	 * so a screen could have shipped that only sites installed after it could
	 * open, and every test would still have passed on its own fresh fixture.
	 *
	 * **The stored version is the literal one that shipped before this change,
	 * not `VERSION - 1`.** Written the relative way first, this test passed with
	 * the bump reverted — winding back from whatever the constant currently is
	 * always leaves the upgrade with work to do, so it proved the upgrader runs
	 * and never that this release asks it to. The literal is what makes
	 * forgetting to bump the constant a failure.
	 *
	 * @return void
	 */
	public function test_an_upgrading_site_is_granted_the_new_capability(): void {
		$role = get_role( Roles::REVIEWER );

		$this->assertNotNull( $role );

		$role->remove_cap( Capabilities::VIEW_REPORTS );
		update_option( Installer::OPTION_ROLES_VERSION, self::ROLES_VERSION_BEFORE, true );

		$reviewer = (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		$this->assertFalse( user_can( $reviewer, Capabilities::VIEW_REPORTS ), 'The fixture did not reach the pre-upgrade state, so this proves nothing.' );

		Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();

		// Roles are cached per request once read.
		wp_cache_flush();

		$this->assertTrue(
			user_can( $reviewer, Capabilities::VIEW_REPORTS ),
			'A site upgrading into this release cannot open the report its own reviewers are meant to read.'
		);
	}

	/**
	 * **An administrator is granted it by installing, which is a separate path.**
	 *
	 * The reviewer role names its capabilities one at a time; an administrator
	 * receives `Capabilities::primitives()` wholesale. Leaving the new
	 * capability out of that list shipped a screen the site owner could not
	 * open while every reviewer test stayed green — which is what happened.
	 *
	 * The capability is stripped and the roles reinstalled rather than merely
	 * asserted, because role definitions live in an option and a cached
	 * `WP_Roles`: an assertion made against whatever the suite happened to
	 * install earlier passes without the install path being exercised at all.
	 * Written that way first, it passed with the capability removed.
	 *
	 * @return void
	 */
	public function test_installing_grants_an_administrator_the_capability(): void {
		$role = get_role( 'administrator' );

		$this->assertNotNull( $role );

		$role->remove_cap( Capabilities::VIEW_REPORTS );
		wp_cache_flush();

		$admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertFalse( user_can( $admin, Capabilities::VIEW_REPORTS ), 'The fixture did not reach the pre-install state, so this proves nothing.' );

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
		wp_cache_flush();

		$this->assertTrue(
			user_can( $admin, Capabilities::VIEW_REPORTS ),
			'Installing does not grant an administrator the reporting capability, so the site owner cannot open the screen.'
		);
	}

	/**
	 * The export truncates a long window, keeps the recent end, and names it.
	 *
	 * Three assertions that all survived their own deletion until this existed:
	 * the truncation, the direction it truncates in, and the sanitisation of a
	 * filename that carries a staff-controlled placement name into a response
	 * header.
	 *
	 * @return void
	 */
	public function test_the_export_window_is_capped_and_its_filename_is_safe(): void {
		$ninety = $this->export->export_period( 90 );

		$this->assertSame( Report_Export::MAX_DAYS, $ninety->days, 'A 90-day window was not capped to what the export can assemble.' );
		$this->assertSame( gmdate( 'Y-m-d' ), $ninety->end, 'Truncation dropped the recent days rather than the old ones.' );

		// A window already inside the cap is left alone.
		$this->assertSame( 7, $this->export->export_period( 7 )->days );

		$hostile = (int) self::factory()->post->create(
			array(
				'post_type'  => 'aggr_placements',
				'post_title' => '../../etc/passwd "quoted"',
			)
		);

		$filename = $this->export->filename( $ninety, $hostile );

		$this->assertStringNotContainsString( '/', $filename, 'A path separator reached a Content-Disposition header.' );
		$this->assertStringNotContainsString( '"', $filename, 'A quote reached a Content-Disposition header, where it ends the filename.' );
		$this->assertStringEndsWith( '.csv', $filename );
	}

	/**
	 * A placement id that is not in the catalogue reports the site, not itself.
	 *
	 * **The code claims to check this and nothing proved it.** Found by
	 * mutation: replacing the catalogue lookup with `return $id` left every
	 * test green. The counters are staff-wide so nothing crosses a tenant here,
	 * but an unchecked id renders an empty report that looks like a placement
	 * with no traffic rather than a placement that does not exist — and a
	 * validation a docblock claims is a validation somebody will rely on.
	 *
	 * Falling back to the whole site rather than failing is the deliberate
	 * choice: a stale bookmark should show something true.
	 *
	 * @return void
	 */
	public function test_an_unknown_placement_falls_back_to_the_whole_site(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$known = (int) self::factory()->post->create(
			array(
				'post_type'  => 'aggr_placements',
				'post_title' => 'Known placement',
			)
		);

		$this->rollups->add( gmdate( 'Y-m-d' ), $known, array( Decision_Outcome::REQUEST => 7 ) );

		$_GET['placement'] = (string) ( $known + 9_999 );

		ob_start();
		$this->screen->render();
		$unknown = (string) ob_get_clean();

		// The site total, which includes the known placement's seven requests.
		$this->assertStringContainsString( '7 requests', $unknown, 'An unknown placement id was used as a filter instead of being refused.' );

		$_GET['placement'] = (string) $known;

		ob_start();
		$this->screen->render();
		$filtered = (string) ob_get_clean();

		// And a real id still filters, so the fallback is not swallowing every id.
		$this->assertStringContainsString( '7 requests', $filtered );
		$this->assertStringContainsString( 'selected', $filtered, 'A known placement was not marked selected in the control.' );
	}

	/**
	 * The screen prints sentences and figures, never a reason code.
	 *
	 * @return void
	 */
	public function test_the_screen_renders_labels_rather_than_codes(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->rollups->add(
			gmdate( 'Y-m-d' ),
			11,
			array(
				Decision_Outcome::REQUEST          => 10,
				Decision_Outcome::FILL             => 6,
				No_Fill_Reason::TARGETING_MISMATCH => 4,
			)
		);

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$labels = Report_Data::reason_labels();

		$this->assertStringContainsString( esc_html( $labels[ No_Fill_Reason::TARGETING_MISMATCH ] ), $html );
		$this->assertStringNotContainsString( No_Fill_Reason::TARGETING_MISMATCH, $html, 'A raw reason code reached the screen.' );
		$this->assertStringContainsString( 'UTC', $html, 'The report does not say its days are UTC.' );
	}

	/**
	 * The export carries a row per day and outcome, with a stable header.
	 *
	 * Long format rather than a column per reason: a wide file's columns would
	 * change the first time a new reason occurred, and somebody has a
	 * spreadsheet pointed at a column.
	 *
	 * @return void
	 */
	public function test_the_export_is_long_format_with_a_pinned_header(): void {
		$today = gmdate( 'Y-m-d' );

		$this->rollups->add(
			$today,
			11,
			array(
				Decision_Outcome::REQUEST          => 10,
				Decision_Outcome::FILL             => 6,
				No_Fill_Reason::TARGETING_MISMATCH => 4,
			)
		);

		$rows = $this->rollups->daily_outcomes( $today, $today, 11 );
		$csv  = $this->export->document( $rows );

		$header = ltrim( (string) strtok( $csv, "\r\n" ), "\xEF\xBB\xBF" );

		$this->assertSame( 'Date (UTC),Placement,Placement ID,Outcome,Code,Opportunity,Kind,Events', $header );
		$this->assertCount( 3, $rows, 'One row per outcome that occurred, and no others.' );

		// The sentence for a reader and the code for a machine, both present.
		$this->assertStringContainsString( Report_Data::reason_labels()[ No_Fill_Reason::TARGETING_MISMATCH ], $csv );
		$this->assertStringContainsString( No_Fill_Reason::TARGETING_MISMATCH, $csv );
	}

	/**
	 * The export keeps a page row and a refresh row apart.
	 *
	 * @return void
	 */
	public function test_the_export_does_not_merge_a_refresh_into_a_page_row(): void {
		$today = gmdate( 'Y-m-d' );

		$this->rollups->add( $today, 11, array( Decision_Outcome::REQUEST => 10 ), Opportunity::PAGE );
		$this->rollups->add( $today, 11, array( Decision_Outcome::REQUEST => 4 ), Opportunity::REFRESH );

		$rows = $this->rollups->daily_outcomes( $today, $today, 11 );
		$csv  = $this->export->document( $rows );

		$this->assertCount( 2, $rows, 'A refresh was merged into the page row beside it.' );
		$this->assertSame( array( Opportunity::PAGE, Opportunity::REFRESH ), array_column( $rows, 'opportunity' ) );
		$this->assertStringContainsString( ',' . Report_Data::opportunity_labels()[ Opportunity::PAGE ] . ',' . Opportunity::PAGE . ',10', $csv );
		$this->assertStringContainsString( ',' . Report_Data::opportunity_labels()[ Opportunity::REFRESH ] . ',' . Opportunity::REFRESH . ',4', $csv );
	}

	/**
	 * A placement named as a formula reaches the file inert.
	 *
	 * Placement names are staff-controlled rather than advertiser-controlled,
	 * which lowers the risk and changes nothing: the writer is not the place to
	 * start making exceptions about who is trusted.
	 *
	 * @return void
	 */
	public function test_a_formula_named_placement_is_neutralized(): void {
		$placement = (int) self::factory()->post->create(
			array(
				'post_type'  => 'aggr_placements',
				'post_title' => '=HYPERLINK("https://attacker.example","Click")',
			)
		);

		$this->rollups->add( gmdate( 'Y-m-d' ), $placement, array( Decision_Outcome::REQUEST => 1 ) );

		$csv = $this->export->document( $this->rollups->daily_outcomes( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ), $placement ) );

		$this->assertStringNotContainsString( ',=HYPERLINK', $csv, 'A bare formula reached a cell boundary.' );
		$this->assertStringNotContainsString( ',"=HYPERLINK', $csv, 'Quoting alone does not stop evaluation.' );
		$this->assertStringContainsString( "'=HYPERLINK", $csv, 'The formula was not prefixed, so a spreadsheet would evaluate it.' );
	}

	/**
	 * **The document needs no request, no session and no screen.**
	 *
	 * This is the whole of what P14 owes the scheduled-delivery contract: not a
	 * scheduler, but proof that producing the bytes is a pure function of rows.
	 * A later phase can call this from cron without unpicking anything, and
	 * knows from here that the hard part left is delivery — bounces,
	 * unsubscribes, attachment size, a schedule that must not double-send —
	 * rather than report generation.
	 *
	 * @return void
	 */
	public function test_the_document_is_a_pure_function_of_its_rows(): void {
		wp_set_current_user( 0 );

		$rows = array(
			array(
				'day'          => '2026-08-01',
				'placement_id' => 0,
				'outcome'      => Decision_Outcome::REQUEST,
				'events'       => 12,
			),
		);

		$first  = $this->export->document( $rows );
		$second = $this->export->document( $rows );

		$this->assertSame( $first, $second, 'The same rows produced different bytes.' );
		$this->assertStringContainsString( '2026-08-01', $first );
		$this->assertStringContainsString( '12', $first );
		$this->assertSame( 0, get_current_user_id(), 'The document read a session it should not need.' );
	}

	/**
	 * **Each guard on the export is proven by the message it dies with.**
	 *
	 * The first version of this asserted only that *something* called
	 * `wp_die()`, and mutation testing showed what that was worth: deleting the
	 * capability check left the test green, because `check_admin_referer()`
	 * died a line later and the assertion could not tell them apart. It is the
	 * same failure `testing-strategy.md` records for the retry receipt — a
	 * second mechanism quietly satisfying the assertion.
	 *
	 * Reporting is off throughout so that a guard which stops guarding falls
	 * through to the module notice rather than to a download, which would end
	 * the test process in `exit`.
	 *
	 * @return void
	 */
	public function test_each_guard_on_the_export_refuses_for_its_own_reason(): void {
		$this->enable_reporting( false );

		// A valid nonce, so the capability is the only thing that can refuse.
		$_REQUEST['_wpnonce'] = wp_create_nonce( Report_Export::EXPORT_ACTION );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) ) );

		$this->assertStringContainsString(
			'permission',
			$this->refusal_from_export(),
			'An advertiser was refused for some other reason than lacking the capability — or was not refused at all.'
		);

		// The capability, and no nonce, so only the referer check can refuse.
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		unset( $_REQUEST['_wpnonce'] );

		$this->assertStringNotContainsString(
			'Reporting is not available',
			$this->refusal_from_export(),
			'A request with no nonce reached past the referer check.'
		);

		// Both satisfied: the module gate is what is left to refuse.
		$_REQUEST['_wpnonce'] = wp_create_nonce( Report_Export::EXPORT_ACTION );

		$this->assertStringContainsString(
			'Reporting is not available',
			$this->refusal_from_export(),
			'An authorized request with reporting off was refused for the wrong reason.'
		);

		unset( $_REQUEST['_wpnonce'] );
	}

	/**
	 * Runs the export handler and returns the message it died with.
	 *
	 * @return string
	 */
	private function refusal_from_export(): string {
		$message = '';

		$handler = static function () use ( &$message ): callable {
			return static function ( $died_with ) use ( &$message ): void {
				$message = is_wp_error( $died_with ) ? $died_with->get_error_message() : (string) $died_with;

				throw new \RuntimeException( 'wp_die' );
			};
		};

		add_filter( 'wp_die_handler', $handler );

		try {
			ob_start();
			$this->export->handle_export();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			ob_end_clean();
		} finally {
			remove_filter( 'wp_die_handler', $handler );
		}

		return $message;
	}

	/**
	 * Freshness stays quiet when every day in the window is settled.
	 *
	 * The portal has this assertion and the publisher screen did not, so the
	 * note could have been printed unconditionally and nothing would have said
	 * so — a permanent "still being counted" on figures that are final is a
	 * disclaimer that teaches people to ignore disclaimers.
	 *
	 * @return void
	 */
	public function test_the_publisher_freshness_note_is_quiet_when_settled(): void {
		update_option( Rollup_Reconciler::OPTION, gmdate( 'Y-m-d', strtotime( '-3 days' ) ), false );

		$this->assertNotSame( '', $this->data->freshness_note( $this->data->period( 30 ) ), 'A window past the watermark claimed to be settled.' );

		$sealed = Report_Period::ending( 7, gmdate( 'Y-m-d', strtotime( '-10 days' ) ) );

		$this->assertNotNull( $sealed );
		$this->assertSame( '', $this->data->freshness_note( $sealed ), 'A fully reconciled window still carried a warning.' );
	}

	/**
	 * The download button names the days it will produce, not the days on screen.
	 *
	 * The two caps differ, and the button is the only place a reader learns
	 * that before clicking it.
	 *
	 * @return void
	 */
	public function test_the_download_button_names_the_capped_window(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$_GET['days'] = '90';

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Last 90 days (UTC)', $html, 'The screen is not showing the 90-day window this asserts about.' );
		$this->assertStringContainsString( sprintf( 'Download %d days (CSV)', Report_Export::MAX_DAYS ), $html );
		$this->assertStringNotContainsString( 'Download 90 days (CSV)', $html, 'The button promised more than the export will assemble.' );
	}

	/**
	 * Reporting off hides the figures here exactly as it does everywhere else.
	 *
	 * @return void
	 */
	public function test_the_report_is_absent_while_reporting_is_off(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->rollups->add( gmdate( 'Y-m-d' ), 12, array( Decision_Outcome::REQUEST => 8 ) );
		$this->enable_reporting( false );

		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Fill rate', $html, 'A figure survived the reporting gate.' );
		$this->assertStringContainsString( 'Reporting is switched off', $html );
	}

	/**
	 * A range never reaches the counters unbounded.
	 *
	 * The screen can only express a `Report_Period`, and one cannot be built
	 * longer than the cap — so there is no path from a query string to a read
	 * across all of history.
	 *
	 * @return void
	 */
	public function test_no_offered_window_exceeds_the_bound(): void {
		foreach ( Report_Data::WINDOWS as $days ) {
			$this->assertLessThanOrEqual( Report_Period::MAX_DAYS, $days );
			$this->assertSame( $days, $this->data->period( $days )->days );
		}
	}

	/**
	 * Toggles the Reporting module.
	 *
	 * @param bool $reporting Desired state.
	 * @return void
	 */
	private function enable_reporting( bool $reporting ): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_REPORTING ] = $reporting;

		$this->settings->save( $document );
	}
}
