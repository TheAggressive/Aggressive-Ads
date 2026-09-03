<?php
/**
 * Org-scoped CSV export of delivery performance.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Report_Actions;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Reporting_Read;
use WP_UnitTestCase;

/**
 * The export is a bulk read of exactly the data the tenancy boundary exists to
 * separate, so it is asserted against a second organization and against house
 * rows rather than only against itself.
 */
final class ReportExportTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Report_Actions
	 */
	private Report_Actions $exports;

	/**
	 * Reporting gate.
	 *
	 * @var Reporting_Read
	 */
	private Reporting_Read $reporting;

	/**
	 * Rollup writes.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Organization A.
	 *
	 * @var int
	 */
	private int $org_a;

	/**
	 * A signed-in advertiser, for the handler tests.
	 *
	 * @var int
	 */
	private int $advertiser_a = 0;

	/**
	 * Organization B.
	 *
	 * @var int
	 */
	private int $org_b;

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
	 * Two organizations with delivery tables and counters.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$installer = new Installer( new Audit_Repository(), new Roles() );
		$installer->install_roles();
		$installer->install_delivery_tables();

		$container       = Plugin::instance()->container();
		$this->exports   = $container->get( Report_Actions::class );
		$this->reporting = $container->get( Reporting_Read::class );
		$this->rollups   = $container->get( Rollup_Repository::class );
		$this->settings  = $container->get( Settings::class );

		$advertiser_a       = (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->advertiser_a = $advertiser_a;
		$advertiser_b       = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_a      = $this->make_org( $advertiser_a, 'Org A' );
		$this->org_b      = $this->make_org( $advertiser_b, 'Org B' );
		$this->campaign_a = $this->make_campaign( $this->org_a, 'A flight' );
		$this->campaign_b = $this->make_campaign( $this->org_b, 'B flight' );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Settings option must not leak into later tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * The handler is attached where admin-post will find it.
	 *
	 * @return void
	 */
	public function test_export_action_is_registered(): void {
		$this->assertNotFalse(
			has_action( 'admin_post_' . Report_Actions::EXPORT_ACTION, array( $this->exports, 'handle_export' ) )
		);
	}

	/**
	 * Rows are the caller's organization only — never another tenant, never
	 * house rows, which have no organization meta to join.
	 *
	 * @return void
	 */
	public function test_rows_exclude_other_orgs_and_house(): void {
		$this->bump( $this->campaign_a, 5, 2 );
		$this->bump( $this->campaign_b, 80, 9 );
		$this->bump( 0, 50, 7 );
		$this->enable_reporting( true );

		$rows = $this->reporting->daily_rows_for_org( $this->org_a, Report_Period::trailing( 7, gmdate( 'Y-m-d' ) ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( $this->campaign_a, $rows[0]['campaign_id'] );
		$this->assertSame( 5, $rows[0]['impressions'] );
		$this->assertSame( 2, $rows[0]['clicks'] );
	}

	/**
	 * The gate applies to the bulk read as much as to the tiles. If it did
	 * not, this would be the one place a site owner's decision to hide the
	 * numbers could be walked around, and the place that hands them over
	 * wholesale.
	 *
	 * @return void
	 */
	public function test_reporting_off_yields_no_rows(): void {
		$this->bump( $this->campaign_a, 5, 2 );
		$this->enable_reporting( false );

		$this->assertSame( array(), $this->reporting->daily_rows_for_org( $this->org_a, Report_Period::trailing( 7, gmdate( 'Y-m-d' ) ) ) );
	}

	/**
	 * The document carries a header, the counts, and a CTR percentage.
	 *
	 * @return void
	 */
	public function test_document_carries_counts_and_ctr(): void {
		$this->bump( $this->campaign_a, 4, 1 );
		$this->enable_reporting( true );

		$csv = $this->exports->document( $this->reporting->daily_rows_for_org( $this->org_a, Report_Period::trailing( 7, gmdate( 'Y-m-d' ) ) ) );

		$this->assertStringContainsString( 'Impressions,Clicks,CTR %', $csv );
		$this->assertStringContainsString( gmdate( 'Y-m-d' ) . ',A flight,', $csv );
		$this->assertStringContainsString( ',4,1,25', $csv );
	}

	/**
	 * No impressions means an empty CTR cell, not 0 — writing 0% would claim
	 * the ad was seen and ignored.
	 *
	 * @return void
	 */
	public function test_zero_impressions_leaves_ctr_empty(): void {
		$this->bump( $this->campaign_a, 0, 3 );
		$this->enable_reporting( true );

		$csv = $this->exports->document( $this->reporting->daily_rows_for_org( $this->org_a, Report_Period::trailing( 7, gmdate( 'Y-m-d' ) ) ) );

		// Two empty trailing cells now: no CTR to compute, and no conversion
		// measurement on a day that predates it.
		$this->assertStringContainsString( ",0,3,,\r\n", $csv );
	}

	/**
	 * Conversions are appended to the header, and the existing columns do not move.
	 *
	 * **The whole header is asserted, not a substring.** Somebody has a
	 * spreadsheet pointed at column D. Appending is safe and inserting is not,
	 * and the only assertion that can tell the two apart is one that pins the
	 * order.
	 *
	 * @return void
	 */
	public function test_conversions_are_appended_without_moving_the_existing_columns(): void {
		$this->enable_reporting( true );

		$csv = $this->exports->document( array() );

		// The document opens with a UTF-8 BOM so Excel reads it as UTF-8; that
		// is the writer's job, not this assertion's.
		$header = ltrim( (string) strtok( $csv, "\r\n" ), "\xEF\xBB\xBF" );

		$this->assertSame(
			'Date (UTC),Campaign,Campaign ID,Impressions,Clicks,CTR %,Conversions',
			$header
		);
	}

	/**
	 * A counted conversion reaches the last cell; an uncounted day leaves it empty.
	 *
	 * Empty and `0` are read very differently by every spreadsheet that will
	 * open this, and the difference is the one the nullable column exists to
	 * preserve: a day before conversion tracking did not convert nobody, it was
	 * not being counted.
	 *
	 * @return void
	 */
	public function test_an_unmeasured_conversion_is_an_empty_cell_not_a_zero(): void {
		$this->bump( $this->campaign_a, 4, 1 );
		$this->enable_reporting( true );

		$rows = $this->reporting->daily_rows_for_org( $this->org_a, Report_Period::trailing( 7, gmdate( 'Y-m-d' ) ) );

		$this->assertStringContainsString( ",4,1,25,\r\n", $this->exports->document( $rows ), 'An unmeasured conversion was written as something other than an empty cell.' );

		$this->rollups->increment( 'conversions', $this->placement_id, $this->campaign_a, '', 0, (int) get_post_meta( $this->campaign_a, Campaign_Repository::META_ORG_ID, true ) );

		$measured = $this->reporting->daily_rows_for_org( $this->org_a, Report_Period::trailing( 7, gmdate( 'Y-m-d' ) ) );

		$this->assertStringContainsString( ",4,1,25,1\r\n", $this->exports->document( $measured ), 'A counted conversion did not reach the document.' );
	}

	/**
	 * A campaign named as a spreadsheet formula reaches the file inert.
	 *
	 * This is the end-to-end version of the unit test: the name survives
	 * WordPress's own sanitization on the way in, so the CSV layer is the last
	 * place it can be made safe.
	 *
	 * @return void
	 */
	public function test_a_formula_named_campaign_is_neutralized_end_to_end(): void {
		$payload = '=HYPERLINK("https://attacker.example","Click")';

		$campaign = $this->make_campaign( $this->org_a, $payload );
		$this->bump( $campaign, 2, 1 );
		$this->enable_reporting( true );

		$csv = $this->exports->document( $this->reporting->daily_rows_for_org( $this->org_a, Report_Period::trailing( 7, gmdate( 'Y-m-d' ) ) ) );

		$this->assertStringNotContainsString( ',=HYPERLINK', $csv, 'A bare formula reached a cell boundary.' );
		$this->assertStringNotContainsString( ',"=HYPERLINK', $csv, 'Quoting alone does not stop evaluation.' );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
	}

	/**
	 * **Each guard on the export is proven by the message it dies with.**
	 *
	 * `document()` covers the bytes and covered nothing around them: mutation
	 * testing removed the capability check, the referer check and the module
	 * gate from `handle_export()` in turn and the suite stayed green, because
	 * nothing called the handler at all. The bulk path is the one surface that
	 * hands over everything at once, so it is the worst place to have taken
	 * that on trust.
	 *
	 * Reporting is off throughout, so a guard that stops guarding falls through
	 * to the module notice rather than to a download — which would end the test
	 * process in `exit`.
	 *
	 * @return void
	 */
	public function test_each_guard_on_the_export_refuses_for_its_own_reason(): void {
		$this->enable_reporting( false );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Report_Actions::EXPORT_ACTION );

		// Signed out: no portal capability, and a valid nonce, so only the
		// capability can be what refuses.
		wp_set_current_user( 0 );

		$this->assertStringContainsString(
			'permission',
			$this->refusal_from_export(),
			'A signed-out caller was refused for some other reason, or not refused at all.'
		);

		wp_set_current_user( $this->advertiser_a );
		unset( $_REQUEST['_wpnonce'] );

		$this->assertStringNotContainsString(
			'Reporting is not available',
			$this->refusal_from_export(),
			'A request with no nonce reached past the referer check.'
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( Report_Actions::EXPORT_ACTION );

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
			$this->exports->handle_export();
			ob_end_clean();
		} catch ( \RuntimeException $e ) {
			ob_end_clean();
		} finally {
			remove_filter( 'wp_die_handler', $handler );
		}

		return $message;
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

		$this->assertTrue( $this->settings->save( $document ) );
	}

	/**
	 * Increments today's counters.
	 *
	 * @param int $campaign_id Campaign id, or 0 for house.
	 * @param int $impressions Impression count.
	 * @param int $clicks      Click count.
	 * @return void
	 */
	private function bump( int $campaign_id, int $impressions, int $clicks ): void {
		// Resolved as `Event_Recorder` resolves it: `org_id` is frozen onto the
		// row at write time, and org-scoped reads filter on it.
		$org_id = $campaign_id > 0
			? (int) get_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, true )
			: 0;

		for ( $i = 0; $i < $impressions; $i++ ) {
			$this->rollups->increment( 'impressions', $this->placement_id, $campaign_id, '', 0, $org_id );
		}

		for ( $i = 0; $i < $clicks; $i++ ) {
			$this->rollups->increment( 'clicks', $this->placement_id, $campaign_id, '', 0, $org_id );
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
}
