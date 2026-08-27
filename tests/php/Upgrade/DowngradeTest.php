<?php
/**
 * Returning to an older build does not restart a finished migration.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Install\Upgrader;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Migrations are forward-only, so a rollback is "install the older ZIP".
 *
 * The hazard is not the walker, which skips anything at or below the stored
 * version. It is the activation hook: reactivating an older build runs
 * `install()`, which stamped its own `DB_VERSION` unconditionally. That claimed
 * a database older than the one on disk — dbDelta drops nothing, so the newer
 * tables were still there — and coming forward re-ran their migrations. A
 * backfill that had finished deleted its cursor, so `start()` re-added it at
 * zero and re-walked the whole catalogue: idempotent, and hours of it.
 *
 * A stored version above the code's is the rollback condition whichever side
 * moved, which is what makes it testable here without an older ZIP.
 */
final class DowngradeTest extends WP_UnitTestCase {

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Installer under test.
	 *
	 * @var Installer
	 */
	private Installer $installer;

	public function set_up(): void {
		parent::set_up();

		$this->audit     = new Audit_Repository();
		$this->installer = new Installer( $this->audit, new Roles() );

		delete_option( Installer::OPTION_UPGRADE_LOCK );
	}

	/**
	 * The state a rolled-back site is in: database ahead, plugin behind.
	 *
	 * The older plugin version is not decoration. It is the arm of
	 * `needs_work()` that is actually true after a rollback — the db and roles
	 * arms both compare with `<` and a newer database fails them. Without it
	 * `maybe_upgrade()` returns at the door and every assertion below passes
	 * without reaching the walker they are about.
	 *
	 * @return int The version the database is left claiming.
	 */
	private function pretend_database_is_ahead(): int {
		$ahead = Schema::DB_VERSION + 5;

		$this->installer->install();
		update_option( Installer::OPTION_DB_VERSION, $ahead );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		return $ahead;
	}

	/** The fixture is a rollback, not a current site the upgrader will ignore. */
	public function test_the_fixture_actually_gives_the_upgrader_work_to_do(): void {
		$this->pretend_database_is_ahead();

		$this->assertTrue(
			( new Upgrader( $this->installer, $this->audit, array() ) )->needs_work(),
			'The rollback fixture looks current, so nothing below reaches the walker.'
		);
	}

	/**
	 * Reactivating over a newer database does not lower the marker.
	 *
	 * The fix, stated directly. Everything below is a consequence of it.
	 */
	public function test_installing_never_lowers_the_stored_database_version(): void {
		$ahead = $this->pretend_database_is_ahead();

		$this->installer->install();

		$this->assertSame(
			$ahead,
			(int) get_option( Installer::OPTION_DB_VERSION ),
			'Reactivating stamped a version older than the schema on disk.'
		);
	}

	/**
	 * A fresh install still stamps the current version.
	 *
	 * The negative half: a clamp that only ever kept the stored value would
	 * satisfy the assertion above and never install anything.
	 */
	public function test_a_fresh_install_still_stamps_the_current_version(): void {
		delete_option( Installer::OPTION_DB_VERSION );

		$this->installer->install();

		$this->assertSame(
			Schema::DB_VERSION,
			(int) get_option( Installer::OPTION_DB_VERSION ),
			'A fresh install did not record its own schema version.'
		);
	}

	/**
	 * No migration re-runs against a database ahead of the code.
	 *
	 * Asserted with steps that record themselves rather than the real map: a
	 * real step is idempotent and would leave no trace of having run.
	 */
	public function test_no_migration_runs_against_a_newer_database(): void {
		$ahead = $this->pretend_database_is_ahead();
		$ran   = array();

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array(
				Schema::DB_VERSION => static function () use ( &$ran ): void {
					$ran[] = Schema::DB_VERSION;
				},
			)
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array(), $ran, 'A migration re-ran on a rolled-back site.' );
		$this->assertSame( $ahead, (int) get_option( Installer::OPTION_DB_VERSION ) );
	}

	/**
	 * A finished backfill survives the round trip.
	 *
	 * The symptom the whole file is about, asserted through the marker a
	 * publisher would actually read.
	 */
	public function test_a_completed_backfill_is_not_restarted_by_a_rollback(): void {
		$this->pretend_database_is_ahead();

		// What completion leaves behind: the done marker set, the cursor gone.
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );
		delete_option( Creative_Assignment_Migrator::OPTION_CURSOR );

		$this->installer->install();

		( new Upgrader( $this->installer, $this->audit, array() ) )->maybe_upgrade();

		$this->assertSame(
			1,
			(int) get_option( Creative_Assignment_Migrator::OPTION_DONE, 0 ),
			'Rolling back and forward restarted a finished backfill.'
		);
		$this->assertFalse(
			get_option( Creative_Assignment_Migrator::OPTION_CURSOR, false ),
			'A finished backfill was given a fresh cursor to walk from zero.'
		);
	}

	/**
	 * The P2 tables are left alone by a build that predates them.
	 *
	 * Why a rollback is inert rather than destructive: the older DDL does not
	 * mention these tables, and dbDelta removes nothing it is not shown.
	 */
	public function test_a_rollback_leaves_the_creative_model_tables_in_place(): void {
		global $wpdb;

		$this->pretend_database_is_ahead();
		$this->installer->install_creative_model();

		$table = $wpdb->prefix . 'aggr_creative_assignments';

		$this->installer->install();

		$this->assertNotEmpty(
			$wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table ) ),
			'Reactivating an older build removed the assignment table.'
		);
	}
}
