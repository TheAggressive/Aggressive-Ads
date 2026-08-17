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

		$advertiser_a = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$advertiser_b = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

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

		$rows = $this->reporting->daily_rows_for_org( $this->org_a, 7 );

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

		$this->assertSame( array(), $this->reporting->daily_rows_for_org( $this->org_a, 7 ) );
	}

	/**
	 * The document carries a header, the counts, and a CTR percentage.
	 *
	 * @return void
	 */
	public function test_document_carries_counts_and_ctr(): void {
		$this->bump( $this->campaign_a, 4, 1 );
		$this->enable_reporting( true );

		$csv = $this->exports->document( $this->reporting->daily_rows_for_org( $this->org_a, 7 ) );

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

		$csv = $this->exports->document( $this->reporting->daily_rows_for_org( $this->org_a, 7 ) );

		$this->assertStringContainsString( ",0,3,\r\n", $csv );
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

		$csv = $this->exports->document( $this->reporting->daily_rows_for_org( $this->org_a, 7 ) );

		$this->assertStringNotContainsString( ',=HYPERLINK', $csv, 'A bare formula reached a cell boundary.' );
		$this->assertStringNotContainsString( ',"=HYPERLINK', $csv, 'Quoting alone does not stop evaluation.' );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
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
		for ( $i = 0; $i < $impressions; $i++ ) {
			$this->rollups->increment( 'impressions', $this->placement_id, $campaign_id );
		}

		for ( $i = 0; $i < $clicks; $i++ ) {
			$this->rollups->increment( 'clicks', $this->placement_id, $campaign_id );
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
