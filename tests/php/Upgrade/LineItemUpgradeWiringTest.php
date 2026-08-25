<?php
/**
 * The real container migration map runs the P1 upgrade.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Line_Item_Migrator;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Line_Item_Repository;
use WP_UnitTestCase;

/**
 * `UpgraderTest` proves the walker; this proves the map it walks.
 *
 * Those are different claims, and only the first was tested. `UpgraderTest`
 * builds an `Upgrader` with synthetic steps — `array( 999 => $recording_step )`
 * — and asserts ordering, skipping, failure handling and locking. Every one of
 * those assertions would still pass if migrations 12 and 13 were registered
 * against the wrong versions, called the wrong methods, or were missing from
 * the container entirely.
 *
 * The repository and the migrator have their own component tests too. What
 * nothing covered is the wiring: that the container's real map runs those
 * components, in order, on a site upgrading from a version before P1. This is
 * the shape where every part works and the assembly does not, and an upgrade is
 * the one code path a publisher cannot re-run by hand if it goes wrong.
 */
final class LineItemUpgradeWiringTest extends WP_UnitTestCase {

	/** The last db version before the line-item work. */
	private const BEFORE_P1 = 11;

	/**
	 * Line-item persistence.
	 *
	 * @var Line_Item_Repository
	 */
	private Line_Item_Repository $line_items;

	public function set_up(): void {
		parent::set_up();

		$this->line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );

		$this->reset_migration_state();
	}

	public function tear_down(): void {
		$this->reset_migration_state();

		// Leave the schema installed for whatever runs next: dropping a table
		// is DDL, and the suite's transaction rollback does not undo it.
		$this->line_items->install_table();

		parent::tear_down();
	}

	/** Clears every option the P1 migrations read or write. */
	private function reset_migration_state(): void {
		delete_option( Line_Item_Migrator::OPTION_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_DONE );
		delete_option( Line_Item_Migrator::OPTION_NAME_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_NAME_DONE );
		delete_option( Installer::OPTION_UPGRADE_LOCK );
		wp_clear_scheduled_hook( Line_Item_Migrator::HOOK );
	}

	/**
	 * Puts the site back to a state before the line-item work shipped.
	 *
	 * The table is dropped as well as the version rewound, because a site that
	 * never ran migration 12 does not have it. Leaving it in place would let a
	 * broken `install_line_items()` wiring pass unnoticed.
	 */
	private function rewind_to_before_p1(): void {
		update_option( Installer::OPTION_DB_VERSION, self::BEFORE_P1, true );
		$this->line_items->drop_table();

		$this->assertFalse(
			$this->line_items->table_exists(),
			'The fixture still had the line-item table, so installing it proves nothing.'
		);
	}

	/** The container's real upgrader, with the real migration map. */
	private function upgrader(): Upgrader {
		return Plugin::instance()->container()->get( Upgrader::class );
	}

	/**
	 * A site upgrading from before P1 ends fully migrated.
	 *
	 * Asserts the four things the closure contract names together, because they
	 * only mean anything together: the physical schema exists, both passes were
	 * started, and the stored version reached the current one.
	 */
	public function test_an_upgrade_from_before_p1_installs_the_schema_and_starts_both_passes(): void {
		$this->rewind_to_before_p1();

		$this->upgrader()->maybe_upgrade();

		// The physical table. Note this alone does not pin *which* step made
		// it: 12 and 13 both call install_line_items(), deliberately, so either
		// would satisfy this. `test_each_pass_starts_after_its_schema_exists()`
		// is what holds each step to its own half.
		$this->assertTrue(
			$this->line_items->table_exists(),
			'The upgrade did not install the line-item table.'
		);

		// 12: the default-row pass was started rather than merely scheduled at
		// some later date. `start()` seeds the cursor and clears the marker.
		$this->assertNotFalse(
			get_option( Line_Item_Migrator::OPTION_CURSOR, false ),
			'Migration 12 did not start the default-row pass.'
		);

		// 13: the name-provenance pass, which is a separate step and the one
		// that would be silently absent if the map ended at 12.
		$this->assertNotFalse(
			get_option( Line_Item_Migrator::OPTION_NAME_CURSOR, false ),
			'Migration 13 did not start the name-provenance pass.'
		);

		// And the version reached the top, so neither step was skipped nor
		// left the walker stranded partway.
		$this->assertSame(
			Schema::DB_VERSION,
			Installer::stored_db_version(),
			'The stored database version did not reach the current schema version.'
		);
	}

	/**
	 * Each pass finds the table already there when it starts.
	 *
	 * Both steps read the line-item table, and both call
	 * `install_line_items()` before starting their pass — deliberately, so
	 * neither depends on the other having run. That redundancy is also why
	 * asserting "the table exists afterwards" proves nothing about either step:
	 * it passes with the install removed from 12, because 13 puts it back.
	 *
	 * So the observation is made at the moment each pass *starts*, which is the
	 * instant its cursor option appears. A step that starts its pass against a
	 * table that does not exist is scheduling work that can only fail, and this
	 * is the only assertion that can see it.
	 */
	public function test_each_pass_starts_after_its_schema_exists(): void {
		$this->rewind_to_before_p1();

		$observed = array();

		foreach (
			array(
				'default' => Line_Item_Migrator::OPTION_CURSOR,
				'name'    => Line_Item_Migrator::OPTION_NAME_CURSOR,
			) as $pass => $option
		) {
			$record = function () use ( &$observed, $pass ): void {
				$observed[ $pass ] ??= $this->line_items->table_exists();
			};

			// Whichever of the two fires depends on whether the option already
			// existed, so both are watched.
			add_action( 'add_option_' . $option, $record );
			add_action( 'update_option_' . $option, $record );
		}

		$this->upgrader()->maybe_upgrade();

		$this->assertTrue(
			$observed['default'] ?? false,
			'Migration 12 started the default-row pass before installing the table.'
		);
		$this->assertTrue(
			$observed['name'] ?? false,
			'Migration 13 started the name-provenance pass before the table existed.'
		);
	}

	/**
	 * An upgrade interrupted after 12 resumes at 13.
	 *
	 * The realistic interruption: a fatal, a timeout or a deploy lands between
	 * the two steps, so the version is stamped at 12 and the second pass never
	 * started. The next request must finish the job rather than consider the
	 * upgrade done.
	 */
	public function test_an_upgrade_interrupted_after_12_resumes_at_13(): void {
		$this->rewind_to_before_p1();

		// Exactly the state the walker leaves when 12 succeeds and 13 has not
		// run: version stamped at 12, schema present, name pass untouched.
		$this->line_items->install_table();
		update_option( Installer::OPTION_DB_VERSION, 12, true );
		delete_option( Line_Item_Migrator::OPTION_NAME_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_NAME_DONE );

		$this->assertFalse(
			get_option( Line_Item_Migrator::OPTION_NAME_CURSOR, false ),
			'The fixture had already started the name pass.'
		);

		$this->upgrader()->maybe_upgrade();

		$this->assertNotFalse(
			get_option( Line_Item_Migrator::OPTION_NAME_CURSOR, false ),
			'An upgrade interrupted after migration 12 never ran migration 13.'
		);
		$this->assertSame( Schema::DB_VERSION, Installer::stored_db_version() );
	}

	/**
	 * A site already at the current version runs neither step again.
	 *
	 * The negative half. Every assertion above would pass on a map that ran
	 * every migration on every request, which would restart both passes for
	 * every publisher on every page load.
	 */
	public function test_a_current_site_does_not_restart_the_passes(): void {
		$this->line_items->install_table();

		update_option( Installer::OPTION_DB_VERSION, Schema::DB_VERSION, true );
		update_option( Installer::OPTION_PLUGIN_VERSION, AGGR_VERSION, true );
		update_option( Line_Item_Migrator::OPTION_DONE, 1, false );
		update_option( Line_Item_Migrator::OPTION_NAME_DONE, 1, false );

		$this->upgrader()->maybe_upgrade();

		// `start()` deletes the completion marker, so an intact marker is the
		// evidence that the step did not run again.
		$this->assertSame( 1, (int) get_option( Line_Item_Migrator::OPTION_DONE, 0 ) );
		$this->assertSame( 1, (int) get_option( Line_Item_Migrator::OPTION_NAME_DONE, 0 ) );
	}
}
