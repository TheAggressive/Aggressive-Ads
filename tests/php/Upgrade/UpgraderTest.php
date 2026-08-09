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
	 * Steps recorded by the test migrations, in the order they ran.
	 *
	 * @var array<int, int>
	 */
	private array $ran = array();

	/**
	 * Builds collaborators and clears any lock left by another test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit     = new Audit_Repository();
		$this->installer = new Installer( $this->audit, new Roles() );
		$this->ran       = array();

		delete_option( Installer::OPTION_UPGRADE_LOCK );
	}

	/**
	 * Builds a migration step that records having run.
	 *
	 * @param int $version The version the step produces.
	 * @return callable(): void
	 */
	private function recording_step( int $version ): callable {
		return function () use ( $version ): void {
			$this->ran[] = $version;
		};
	}

	/**
	 * Builds a migration step that fails, as a bad ALTER would.
	 *
	 * @param string $message Failure message.
	 * @return callable(): void
	 */
	private function failing_step( string $message ): callable {
		return static function () use ( $message ): void {
			throw new RuntimeException( $message );
		};
	}

	/**
	 * Puts the site one version behind so the upgrader has work to do.
	 *
	 * @param int $db_version The db version to pretend is stored.
	 * @return void
	 */
	private function pretend_site_is_behind( int $db_version = 1 ): void {
		$this->installer->install();

		update_option( Installer::OPTION_DB_VERSION, $db_version );
		update_option( Installer::OPTION_PLUGIN_VERSION, '0.0.1' );
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

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 999 => $this->recording_step( 999 ) )
		);

		$this->assertFalse( $upgrader->needs_work() );

		$upgrader->maybe_upgrade();

		$this->assertSame( array(), $this->ran, 'A migration ran while every version was current.' );
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
		$this->pretend_site_is_behind();

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array(
				4 => $this->recording_step( 4 ),
				2 => $this->recording_step( 2 ),
				3 => $this->recording_step( 3 ),
			)
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array( 2, 3, 4 ), $this->ran );
		$this->assertSame( 4, (int) get_option( Installer::OPTION_DB_VERSION ) );
	}

	/**
	 * A step already applied is skipped.
	 *
	 * @return void
	 */
	public function test_already_applied_steps_are_skipped(): void {
		$this->pretend_site_is_behind( 3 );

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array(
				2 => $this->recording_step( 2 ),
				3 => $this->recording_step( 3 ),
				4 => $this->recording_step( 4 ),
			)
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array( 4 ), $this->ran );
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
		$this->pretend_site_is_behind();

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array(
				2 => $this->recording_step( 2 ),
				3 => $this->failing_step( 'ALTER failed' ),
				4 => $this->recording_step( 4 ),
			)
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array( 2 ), $this->ran, 'Step 4 ran despite step 3 failing.' );
		$this->assertSame( 2, (int) get_option( Installer::OPTION_DB_VERSION ) );
	}

	/**
	 * A failure is recorded rather than swallowed silently.
	 *
	 * @return void
	 */
	public function test_a_failing_step_writes_a_failed_audit_row(): void {
		global $wpdb;

		$this->pretend_site_is_behind();

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 2 => $this->failing_step( 'ALTER failed' ) )
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
		$this->pretend_site_is_behind();

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 2 => $this->failing_step( 'boom' ) )
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
		$this->pretend_site_is_behind();

		// Another request is mid-upgrade, right now.
		add_option( Installer::OPTION_UPGRADE_LOCK, time(), '', false );

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 2 => $this->recording_step( 2 ) )
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array(), $this->ran, 'Two requests migrated at the same time.' );
	}

	/**
	 * A lock left by a request that died is cleared and the upgrade proceeds.
	 *
	 * @return void
	 */
	public function test_a_stale_lock_is_cleared(): void {
		$this->pretend_site_is_behind();

		// A lock from a request that fataled an hour ago.
		add_option( Installer::OPTION_UPGRADE_LOCK, time() - 3600, '', false );

		$upgrader = new Upgrader(
			$this->installer,
			$this->audit,
			array( 2 => $this->recording_step( 2 ) )
		);

		$upgrader->maybe_upgrade();

		$this->assertSame( array( 2 ), $this->ran, 'A stale lock permanently wedged the upgrade.' );
	}

	/**
	 * The upgrade lock is never autoloaded.
	 *
	 * It is written and deleted rather than read, and an autoloaded option that
	 * churns is a cache-invalidation cost for nothing.
	 *
	 * @return void
	 */
	public function test_the_lock_option_is_not_autoloaded(): void {
		global $wpdb;

		$this->pretend_site_is_behind();

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
