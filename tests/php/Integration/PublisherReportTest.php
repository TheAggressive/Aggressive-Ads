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
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
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
	 * **Every reason the engine can record has a sentence.**
	 *
	 * A code is never rendered raw: `targeting_mismatch` tells a publisher
	 * nothing they can act on. Derived from the vocabulary rather than listed
	 * by hand, so a reason added to `No_Fill_Reason` without a label here fails
	 * rather than reaching a screen as a slug.
	 *
	 * @return void
	 */
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

		$this->assertSame( 'Date (UTC),Placement,Placement ID,Outcome,Code,Events', $header );
		$this->assertCount( 3, $rows, 'One row per outcome that occurred, and no others.' );

		// The sentence for a reader and the code for a machine, both present.
		$this->assertStringContainsString( Report_Data::reason_labels()[ No_Fill_Reason::TARGETING_MISMATCH ], $csv );
		$this->assertStringContainsString( No_Fill_Reason::TARGETING_MISMATCH, $csv );
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
	 * An unauthorized caller cannot download the figures.
	 *
	 * The export is the bulk path: the one surface that hands over everything
	 * at once, which is where an authorization gap is worth the most.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_download_the_fill_report(): void {
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
			$this->export->handle_export();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			ob_end_clean();
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertTrue( $died, 'An advertiser downloaded the publisher fill report.' );
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
