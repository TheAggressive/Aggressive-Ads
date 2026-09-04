<?php
/**
 * What a released upgrade actually does to a site's schema.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Refresh_Policy;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Install\Migration_Map;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

/**
 * The published release is database version 13. Master is 17.
 *
 * Each step in between has its own test, and every one of them passes while the
 * *walk* could still be wrong: a step missing from the map, a step that installs
 * a table but not the column a later one assumes, an ordering that leaves the
 * version stamped ahead of the schema. Those only show up end to end, and the
 * first site to find out would be a real one — a migration runs once, against
 * data somebody cares about.
 *
 * So this asserts the artifacts, not the version. Reaching 17 proves the walker
 * counted; it does not prove anything was built.
 */
final class ReleaseUpgradePathTest extends WP_UnitTestCase {

	/** The schema version of the last published release. */
	private const PUBLISHED = 13;

	/**
	 * Puts the site where an upgrading installation actually starts.
	 *
	 * The delivery tables are dropped as well as the version rewound: a fixture
	 * that still has them would pass whatever the migrations did.
	 *
	 * @return void
	 */
	private function rewind_to_published(): void {
		$container = Plugin::instance()->container();

		$container->get( Creative_Assignment_Repository::class )->drop_table();
		$container->get( Creative_Asset_Repository::class )->drop_table();

		delete_option( Creative_Assignment_Migrator::OPTION_DONE );
		delete_option( Creative_Assignment_Migrator::OPTION_CURSOR );
		delete_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE );

		update_option( Installer::OPTION_DB_VERSION, self::PUBLISHED, true );
		update_option( Installer::OPTION_PLUGIN_VERSION, '1.4.0', true );
	}

	/** Column names actually on a table. */
	private function columns( string $table ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$rows = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );

		return is_array( $rows ) ? array_column( $rows, 'Field' ) : array();
	}

	/**
	 * Upgrading from the published release builds every table it should.
	 *
	 * The creative model is two tables that did not exist at 13, and nothing
	 * serves without them — a slot whose assignments never arrived is an empty
	 * slot, silently.
	 */
	public function test_the_published_release_upgrades_to_the_creative_model(): void {
		$this->rewind_to_published();

		Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();

		/*
		 * Fresh repositories, not the container's.
		 *
		 * `table_exists()` memoises, and the rewind above called `drop_table()`
		 * on the shared instances — so those cached "no table" before the
		 * migration ran and would report a failure the database does not have.
		 */
		$this->assertTrue(
			( new Creative_Assignment_Repository() )->table_exists(),
			'Upgrading from the published release left no assignment table, so nothing can serve.'
		);
		$this->assertTrue(
			( new Creative_Asset_Repository() )->table_exists(),
			'Upgrading from the published release left no asset table.'
		);
	}

	/**
	 * And starts the backfill those tables are useless without.
	 *
	 * Creating the tables and never walking the catalogue leaves every existing
	 * creative unassigned, which reads as "no ads configured" rather than as a
	 * migration that did half its job.
	 */
	public function test_the_upgrade_starts_the_creative_backfill(): void {
		$this->rewind_to_published();

		Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();

		$this->assertNotFalse(
			get_option( Creative_Assignment_Migrator::OPTION_CURSOR, false ),
			'The creative backfill was never started, so existing creatives stay unassigned.'
		);
	}

	/**
	 * The rollup columns two later phases depend on.
	 *
	 * `line_item_id` carries a pacing cap and `viewables` carries the
	 * distinction between unmeasured and zero. Both are added by migrations
	 * after the published version, and a stage reading a column that is not
	 * there fails at serve time rather than at upgrade time.
	 */
	public function test_the_upgrade_adds_both_rollup_columns(): void {
		$this->rewind_to_published();

		Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();

		$columns = $this->columns(
			Plugin::instance()->container()->get( Rollup_Repository::class )->table_name()
		);

		$this->assertContains( 'line_item_id', $columns, 'Pacing counters have nowhere to be attributed.' );
		$this->assertContains( 'viewables', $columns, 'Viewability has nowhere to be recorded.' );
	}

	/**
	 * Counters an upgrading site already had are attributed to their line item.
	 *
	 * The column alone proves nothing: every `install_table()` adds it, so a
	 * sabotage removing the attribution step still left the column there and
	 * the earlier assertion green. What only that step does is *fill* it, and a
	 * site upgrading from the published release has rows that need it — left at
	 * zero, their campaign's cap starts from nothing and the first day
	 * overdelivers.
	 *
	 * @return void
	 */
	public function test_the_upgrade_attributes_counters_a_site_already_had(): void {
		global $wpdb;

		$this->rewind_to_published();

		$container = Plugin::instance()->container();
		$rollups   = $container->get( Rollup_Repository::class );

		$campaign_id = (int) self::factory()->post->create( array( 'post_type' => 'aggr_campaign' ) );
		$line_item   = $container->get( \Aggressive\Ads\Repository\Line_Item_Repository::class )
			->ensure_default( $campaign_id );

		$this->assertIsArray( $line_item, 'The fixture produced no line item to attribute to.' );

		// A counter as the published release wrote it: no line item named.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recreating the pre-v16 row shape.
		$wpdb->insert(
			$rollups->table_name(),
			array(
				'day_utc'      => '2026-07-01',
				'placement_id' => 71,
				'campaign_id'  => $campaign_id,
				'line_item_id' => 0,
				'impressions'  => 25,
			)
		);

		$container->get( Upgrader::class )->maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table.
		$attributed = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT line_item_id FROM %i WHERE campaign_id = %d AND day_utc = %s',
				$rollups->table_name(),
				$campaign_id,
				'2026-07-01'
			)
		);

		$this->assertSame(
			(int) $line_item['id'],
			$attributed,
			'An upgrading site kept unattributed counters, so its caps restart from zero.'
		);
	}

	/**
	 * The upgrade records when viewability measurement began.
	 *
	 * Without it the reconciler cannot tell a day nobody measured from a day
	 * nothing was seen, and rewrites history to zero the first time it runs.
	 */
	public function test_the_upgrade_records_the_measurement_boundary(): void {
		$this->rewind_to_published();

		Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();

		$this->assertNotFalse(
			get_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE, false ),
			'Nothing recorded when measurement began, so the reconciler will zero history.'
		);
	}

	/**
	 * **A real upgrade leaves existing placements able to do what they did.**
	 *
	 * Migration 25 is the whole reason the strict refresh default is safe to
	 * ship. Tested in isolation it calls the repository directly; tested here it
	 * has to survive the walker, in sequence, from a version a real site is on.
	 * The failure it guards is silent — an upgraded site whose ads simply stop
	 * changing, with nothing erroring and nothing logged.
	 *
	 * @return void
	 */
	public function test_an_upgrade_leaves_existing_placements_permitted_to_refresh(): void {
		$this->rewind_to_published();

		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'upgraded-leaderboard',
			)
		);
		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		$container  = Plugin::instance()->container();
		$placements = $container->get( Placement_Repository::class );

		// Before: nothing recorded, so the strict default would forbid refresh.
		$this->assertFalse( $placements->refresh_policy( $placement_id )->enabled );

		$container->get( Upgrader::class )->maybe_upgrade();

		$policy = $placements->refresh_policy( $placement_id );

		$this->assertTrue(
			$policy->enabled,
			'An upgrade quietly forbade refresh on a placement that could already do it.'
		);
		$this->assertSame( Refresh_Policy::LEGACY_CLIENT_MAX_PER_VIEW, $policy->max_per_view );
	}

	/**
	 * The grain reaches the counter table through the walker, not just alone.
	 *
	 * **Rewound to 25, not to 13, and the difference is the whole test.** From
	 * 13 the walker passes through migration 24, whose `install_delivery_tables()`
	 * already applies today's DDL — so the column and the key arrive whatever
	 * migration 26 does, and deleting 26 entirely left this green. It was
	 * passing for a reason unrelated to the thing it names.
	 *
	 * A site upgrading from 25 is the one migration 26 exists for, and its table
	 * is in the pre-P15 shape, so this puts it there first. `dbDelta` adds an
	 * index and never drops one, which is the failure being guarded.
	 *
	 * @return void
	 */
	public function test_an_upgrade_from_the_previous_version_carries_the_grain(): void {
		global $wpdb;

		$container = Plugin::instance()->container();
		$table     = $container->get( Decision_Rollup_Repository::class )->table_name();

		// The table as a site on 25 has it: no grain, and the old unique.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreating the pre-P15 state this migration repairs.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX slot_day_outcome_kind" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreating the pre-P15 state this migration repairs.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN opportunity" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Recreating the pre-P15 state this migration repairs.
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY slot_day_outcome (placement_id,day_utc,outcome)" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$before = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		$this->assertNotContains(
			'opportunity',
			$before,
			'The fixture failed to recreate the pre-P15 table, so what follows would prove nothing.'
		);

		/*
		 * The migration step directly, not `maybe_upgrade()`. The ALTER TABLE
		 * above commits the transaction this test is wrapped in, so the
		 * upgrader's option-based lock would survive the rollback and silently
		 * disable a later test's upgrade — the trap `ConversionSchemaTest`
		 * already records for the same reason.
		 */
		$steps = Migration_Map::steps( $container );

		$this->assertArrayHasKey(
			26,
			$steps,
			'The grain is declared but no database version installs it.'
		);

		$steps[26]();

		$table = $container->get( Decision_Rollup_Repository::class )->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on this plugin's table.
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names = is_array( $rows ) ? array_values( array_unique( array_column( $rows, 'Key_name' ) ) ) : array();

		$this->assertContains( 'opportunity', $columns );
		$this->assertContains( 'slot_day_outcome_kind', $names );
		$this->assertNotContains(
			'slot_day_outcome',
			$names,
			'The superseded unique survived a real upgrade, so a refresh cannot hold its own row.'
		);
	}

	/**
	 * The version lands on the current schema, and only after the work.
	 *
	 * Asserted last and deliberately not alone: a walker that stamped the
	 * version without running a step would satisfy this and nothing above.
	 */
	public function test_the_stored_version_reaches_the_current_schema(): void {
		$this->rewind_to_published();

		Plugin::instance()->container()->get( Upgrader::class )->maybe_upgrade();

		$this->assertSame( Schema::DB_VERSION, Installer::stored_db_version() );
	}

	/**
	 * Running the same upgrade twice changes nothing.
	 *
	 * A published upgrade lands on sites that get two requests at once, and on
	 * sites where somebody reloads a slow admin page. The second pass must be a
	 * no-op rather than a second backfill.
	 */
	public function test_upgrading_twice_is_a_no_op(): void {
		$this->rewind_to_published();

		$upgrader = Plugin::instance()->container()->get( Upgrader::class );
		$upgrader->maybe_upgrade();

		$since = get_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE );

		$upgrader->maybe_upgrade();

		$this->assertSame( Schema::DB_VERSION, Installer::stored_db_version() );
		$this->assertSame(
			$since,
			get_option( Rollup_Repository::OPTION_VIEWABILITY_SINCE ),
			'A second upgrade moved the measurement boundary, discarding a day of history.'
		);
	}
}
