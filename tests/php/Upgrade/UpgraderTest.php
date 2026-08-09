<?php
/**
 * Migration walker behaviour.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Upgrade;

use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Install\Upgrader;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Security\Roles;
use RuntimeException;
use WP_UnitTestCase;

/**
 * The walker's failure behaviour is the part that matters, and it is normally
 * first exercised during a real production upgrade — which is the worst
 * possible time to discover it is wrong. The migration map is a constructor
 * argument specifically so it can be driven here instead.
 */
final class UpgraderTest extends WP_UnitTestCase {

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Installer collaborator.
	 *
	 * @var Installer
	 */
	private Installer $installer;

	/**
	 * Builds collaborators and clears any lock left by another test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit     = new Audit_Repository();
		$this->installer = new Installer( $this->audit, new Roles() );

		delete_option( Installer::OPTION_UPGRADE_LOCK );
	}

	/**
	 * With every version current, the upgrader does nothing at all.
	 *
	 * This runs on every request, so "nothing to do" must be genuinely cheap
	 * and genuinely inert.
	 *
	 * @return void
	 */
	public function test_does_nothing_when_versions_are_current(): void {
		$this->installer->install();

		$ran = false;

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 999 => static function () use ( &$ran ): void { $ran = true; } )
		);

		$this->assertFalse( $upgrader->needs_work() );

		$upgrader->maybe_upgrade();

		$this->assertFalse( $ran, 'A migration ran while every version was current.' );
	}

	/**
	 * A stale plugin version is enough to trigger the check.
	 *
	 * @return void
	 */
	public function test_a_stale_plugin_version_needs_work(): void {
		$this->installer->install();

		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		$upgrader = new Upgrader( $this->installer, $this->audit );

		$this->assertTrue( $upgrader->needs_work() );
	}

	/**
	 * Steps run in ascending version order regardless of declaration order.
	 *
	 * A step added out of sequence during a merge must still run in the right
	 * place; relying on array literal order makes correctness a property of
	 * how a conflict was resolved.
	 *
	 * @return void
	 */
	public function test_migrations_run_in_version_order(): void {
		$this->installer->install();
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		$order = array();

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array(
				4 => static function () use ( &$order ): void { $order[] = 4; },
				2 => static function () use ( &$order ): void { $order[] = 2; },
				3 => static function () use ( &$order ): void { $order[] = 3; },
			)
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array( 2, 3, 4 ), $order );
		$this->assertSame( 4, (int) get_option( Installer::OPTION_DB_VERSION ) );
	}

	/**
	 * A step already applied is skipped.
	 *
	 * @return void
	 */
	public function test_already_applied_steps_are_skipped(): void {
		$this->installer->install();
		update_option( Installer::OPTION_DB_VERSION, 3 );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		$ran = array();

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array(
				2 => static function () use ( &$ran ): void { $ran[] = 2; },
				3 => static function () use ( &$ran ): void { $ran[] = 3; },
				4 => static function () use ( &$ran ): void { $ran[] = 4; },
			)
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array( 4 ), $ran );
	}

	/**
	 * A failing step stops the walk at the last version that succeeded.
	 *
	 * This is the whole reason each step stamps its own version. Resuming at
	 * the first unfinished step means a fatal never replays a completed ALTER;
	 * a single bump after the loop would re-run everything from the beginning,
	 * which is how one bad deploy becomes a corrupted schema.
	 *
	 * @return void
	 */
	public function test_a_failing_step_leaves_the_version_at_the_last_success(): void {
		$this->installer->install();
		update_option( Installer::OPTION_DB_VERSION, 1 );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		$ran = array();

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array(
				2 => static function () use ( &$ran ): void { $ran[] = 2; },
				3 => static function (): void { throw new RuntimeException( 'ALTER failed' ); },
				4 => static function () use ( &$ran ): void { $ran[] = 4; },
			)
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array( 2 ), $ran, 'Step 4 ran despite step 3 failing.' );
		$this->assertSame( 2, (int) get_option( Installer::OPTION_DB_VERSION ) );
	}

	/**
	 * A failure is recorded rather than swallowed silently.
	 *
	 * @return void
	 */
	public function test_a_failing_step_writes_a_failed_audit_row(): void {
		global $wpdb;

		$this->installer->install();
		update_option( Installer::OPTION_DB_VERSION, 1 );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 2 => static function (): void { throw new RuntimeException( 'ALTER failed' ); } )
		);

		$upgrader->maybe_upgrade();

		$table = $this->audit->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE event = 'plugin.upgrade_failed' ORDER BY id DESC LIMIT 1", ARRAY_A );

		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row['outcome'] );
		$this->assertSame( 'ALTER failed', $row['message'] );
	}

	/**
	 * The lock is released even when a step throws.
	 *
	 * Without the finally, one failed upgrade wedges the site: the lock is
	 * never released and every later request declines to do anything.
	 *
	 * @return void
	 */
	public function test_the_lock_is_released_after_a_failure(): void {
		$this->installer->install();
		update_option( Installer::OPTION_DB_VERSION, 1 );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 2 => static function (): void { throw new RuntimeException( 'boom' ); } )
		);

		$upgrader->maybe_upgrade();

		$this->assertFalse( get_option( Installer::OPTION_UPGRADE_LOCK ) );
	}

	/**
	 * A held lock stops a second request from migrating concurrently.
	 *
	 * @return void
	 */
	public function test_a_held_lock_prevents_a_concurrent_upgrade(): void {
		$this->installer->install();
		update_option( Installer::OPTION_DB_VERSION, 1 );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		// Another request is mid-upgrade, right now.
		add_option( Installer::OPTION_UPGRADE_LOCK, time(), '', false );

		$ran = false;

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 2 => static function () use ( &$ran ): void { $ran = true; } )
		);

		$upgrader->maybe_upgrade();

		$this->assertFalse( $ran, 'Two requests migrated at the same time.' );
	}

	/**
	 * A lock left by a request that died is cleared and the upgrade proceeds.
	 *
	 * @return void
	 */
	public function test_a_stale_lock_is_cleared(): void {
		$this->installer->install();
		update_option( Installer::OPTION_DB_VERSION, 1 );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		// A lock from a request that fataled an hour ago.
		add_option( Installer::OPTION_UPGRADE_LOCK, time() - 3600, '', false );

		$ran = false;

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 2 => static function () use ( &$ran ): void { $ran = true; } )
		);

		$upgrader->maybe_upgrade();

		$this->assertTrue( $ran, 'A stale lock permanently wedged the upgrade.' );
	}

	/**
	 * The upgrade lock is never autoloaded. It is written and deleted rather
	 * than read, and an autoloaded option that churns is a cache-invalidation
	 * cost for nothing.
	 *
	 * @return void
	 */
	public function test_the_lock_option_is_not_autoloaded(): void {
		global $wpdb;

		$this->installer->install();
		update_option( Installer::OPTION_DB_VERSION, 1 );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );

		$observed = null;

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array(
				2 => static function () use ( &$observed, $wpdb ): void {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$observed = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
							Installer::OPTION_UPGRADE_LOCK
						)
					);
				},
			)
		);

		$upgrader->maybe_upgrade();

		$this->assertNotNull( $observed, 'The lock was not held while a migration ran.' );
		$this->assertNotSame( 'yes', $observed );
	}
}
